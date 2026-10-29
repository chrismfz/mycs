<?php

namespace Tests\Feature;

use App\Classes\Settings;
use App\Console\Commands\EuVatRatesCheck;
use App\Jobs\SendChatNotification;
use App\Models\ChatNotificationRule;
use App\Models\EmailLog;
use App\Models\TaxRate;
use App\Services\Ops\Health;
use App\Services\Tax\EuVatRates;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use MyCS\EuVatRates\StandardRates;
use MyCS\EuVatRates\Tedb;
use MyCS\Vies\VatCountry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The EU standard VAT rates (docs/eu-vat.md): the checked list into Tax Rates only where a country has none, and the
 * weekly comparison with TEDB, which tells staff of a difference and changes nothing. Off by default; TEDB failing
 * changes nothing either.
 */
class EuVatRatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake([SendChatNotification::class]);
        $this->storeSettings(['eu_vat_rates_check' => true, 'system_email_address' => 'staff@example.test', 'mail_disable' => false]);
        ChatNotificationRule::query()->create(['name' => 'Ops', 'webhook_url' => 'https://chat.example.test/hooks/x', 'events' => ['vat_rate_differs'], 'enabled' => true]);
    }

    private function answer(): string
    {
        return (string) file_get_contents(base_path('packages/eu-vat-rates/tests/fixtures/tedb-answer.xml'));
    }

    private function tedbAnswers(string $body, int $status = 200): void
    {
        Http::fake([Tedb::ENDPOINT . '*' => Http::response($body, $status, ['Content-Type' => 'text/xml'])]);
    }

    private function mails(): int
    {
        return EmailLog::query()->where('subject', 'EU VAT rate changed')->count();
    }

    private function chats(): int
    {
        return Queue::pushed(SendChatNotification::class)->count();
    }

    /** @return array<string, string> */
    private function rates(): array
    {
        return TaxRate::query()->orderBy('country')->get()->mapWithKeys(fn (TaxRate $rate) => [$rate->country => number_format((float) $rate->rate, 2, '.', '') . ' ' . $rate->name])->all();
    }

    public function test_the_seed_adds_every_member_state_once(): void
    {
        $this->artisan('mycs:vat-rates:seed')->assertSuccessful();

        $this->assertSame(27, TaxRate::query()->count());
        $this->assertSame('24.00', number_format((float) TaxRate::query()->where('country', 'GR')->value('rate'), 2));
        $this->assertSame('25.50', number_format((float) TaxRate::query()->where('country', 'FI')->value('rate'), 2));
        $this->assertSame('VAT Austria', TaxRate::query()->where('country', 'AT')->value('name'));

        $this->artisan('mycs:vat-rates:seed')->expectsOutputToContain('Added 0 rate(s)')->assertSuccessful();
        $this->assertSame(27, TaxRate::query()->count());
    }

    public function test_the_seed_never_changes_what_staff_wrote(): void
    {
        TaxRate::query()->create(['name' => 'ΦΠΑ', 'rate' => 13, 'country' => 'GR']);
        TaxRate::query()->create(['name' => 'Everyone', 'rate' => 24, 'country' => 'all']);
        TaxRate::query()->create(['name' => 'Swiss', 'rate' => 8.1, 'country' => 'CH']);

        $this->artisan('mycs:vat-rates:seed')->expectsOutputToContain('Added 26 rate(s)')->assertSuccessful();

        $rates = $this->rates();
        $this->assertSame('13.00 ΦΠΑ', $rates['GR']);
        $this->assertSame('24.00 Everyone', $rates['all']);
        $this->assertSame('8.10 Swiss', $rates['CH']);
        $this->assertCount(29, $rates);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        TaxRate::query()->create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);

        $this->artisan('mycs:vat-rates:seed', ['--dry-run' => true])->expectsOutputToContain('Would add 26 rate(s)')->assertSuccessful();

        $this->assertSame(1, TaxRate::query()->count());
    }

    public function test_the_check_is_off_by_default_and_calls_nothing(): void
    {
        $this->storeSettings(['eu_vat_rates_check' => false]);
        $this->assertFalse(collect(Settings::settings()['tax'])->firstWhere('name', 'eu_vat_rates_check')['default']);
        Http::fake();

        $this->artisan('mycs:vat-rates:check')->expectsOutputToContain('off')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame([], app(EuVatRates::class)->state());
        $this->assertSame(Health::OK, Health::report()['checks']['vat_rates']['status']);
    }

    public function test_the_check_runs_weekly_from_the_scheduler(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'mycs:vat-rates:check'));

        $this->assertNotNull($event, EuVatRatesCheck::class . ' is not scheduled');
        $this->assertSame('45 5 * * 1', $event->expression);
    }

    public function test_rates_as_in_tedb_tell_nobody(): void
    {
        TaxRate::query()->create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        TaxRate::query()->create(['name' => 'VAT', 'rate' => 25.5, 'country' => 'FI']);
        $this->tedbAnswers($this->answer());

        $this->artisan('mycs:vat-rates:check')->expectsOutputToContain('2 rate(s) compared, 0 different')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === Tedb::ENDPOINT
            && $request->hasHeader('SOAPAction', '"' . Tedb::SOAP_ACTION . '"') && str_contains($request->body(), '<t:isoCode>EL</t:isoCode>'));
        $this->assertSame(0, $this->mails());
        $this->assertSame(0, $this->chats());
        $health = Health::report()['checks']['vat_rates'];
        $this->assertSame(Health::OK, $health['status']);
        $this->assertStringContainsString('2 rates as in TEDB', $health['detail']);
    }

    public function test_a_different_rate_tells_staff_and_changes_nothing(): void
    {
        TaxRate::query()->create(['name' => 'ΦΠΑ', 'rate' => 23, 'country' => 'GR']);
        TaxRate::query()->create(['name' => 'MwSt', 'rate' => 19, 'country' => 'DE']);
        TaxRate::query()->create(['name' => 'Everyone', 'rate' => 10, 'country' => 'all']); // not a member state: not compared
        $this->tedbAnswers($this->answer());

        $this->artisan('mycs:vat-rates:check')->expectsOutputToContain('GR: 23.00% here, 24.00% in TEDB')->assertSuccessful();

        $this->assertSame(1, $this->mails());
        $this->assertStringContainsString('GR: 23.00% in Tax Rates, 24.00% in TEDB', (string) EmailLog::query()->where('subject', 'EU VAT rate changed')->value('body'));
        $this->assertSame(1, $this->chats());
        $this->assertSame('23.00', number_format((float) TaxRate::query()->where('country', 'GR')->value('rate'), 2));
        $this->assertSame('10.00', number_format((float) TaxRate::query()->where('country', 'all')->value('rate'), 2));
        $health = Health::report()['checks']['vat_rates'];
        $this->assertSame(Health::WARN, $health['status']);
        $this->assertStringContainsString('GR 23.00% here, 24.00% in TEDB', $health['detail']);

        // The same difference is not told again the next day, but is a week later, until staff change it.
        $this->travel(1)->days();
        $this->artisan('mycs:vat-rates:check')->assertSuccessful();
        $this->assertSame(1, $this->mails());
        $this->travel(6)->days();
        $this->artisan('mycs:vat-rates:check')->assertSuccessful();
        $this->assertSame(2, $this->mails());

        // Staff apply it: nothing to tell, and Automation Status is ok again.
        TaxRate::query()->where('country', 'GR')->update(['rate' => 24]);
        $this->artisan('mycs:vat-rates:check')->expectsOutputToContain('0 different')->assertSuccessful();
        $this->assertSame(2, $this->mails());
        $this->assertSame(Health::OK, Health::report()['checks']['vat_rates']['status']);
    }

    /** @return iterable<string, array{\Closure(self): void, string}> */
    public static function failures(): iterable
    {
        yield 'timeout' => [fn (self $test) => Http::fake(fn () => throw new ConnectionException('Operation timed out')), 'timed out'];
        yield 'server error' => [fn (self $test) => $test->tedbAnswers('<html>Service Unavailable</html>', 503), 'HTTP 503'];
        yield 'fault' => [fn (self $test) => $test->tedbAnswers('<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body><env:Fault>'
            . '<faultcode>env:Server</faultcode><faultstring>TEDB-500 internal</faultstring></env:Fault></env:Body></env:Envelope>', 500), 'TEDB-500'];
        yield 'not every state' => [fn (self $test) => $test->tedbAnswers((string) preg_replace(
            '#<vatRateResults><memberState>EL</memberState><type>STANDARD</type>.*?</vatRateResults>#s', '', $test->answer())), 'no standard rate for GR'];
        yield 'an empty 200' => [fn (self $test) => $test->tedbAnswers(''), 'answered nothing'];
    }

    /** @param \Closure(self): void $fake */
    #[DataProvider('failures')]
    public function test_tedb_failing_changes_nothing_and_is_seen(\Closure $fake, string $why): void
    {
        TaxRate::query()->create(['name' => 'ΦΠΑ', 'rate' => 23, 'country' => 'GR']);
        Log::spy();
        $fake($this);

        $this->artisan('mycs:vat-rates:check')->expectsOutputToContain($why)->assertFailed();

        $this->assertSame(0, $this->mails());
        $this->assertSame(0, $this->chats());
        $this->assertSame('23.00', number_format((float) TaxRate::query()->where('country', 'GR')->value('rate'), 2));
        $this->assertSame(1, TaxRate::query()->count());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, $why))->once();
        $health = Health::report()['checks']['vat_rates'];
        $this->assertSame(Health::WARN, $health['status']);
        $this->assertStringContainsString('TEDB did not answer', $health['detail']);
    }

    public function test_a_failure_after_a_good_check_keeps_what_was_found_and_a_good_one_clears_it(): void
    {
        TaxRate::query()->create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        $up = true;
        Http::fake(function () use (&$up) {
            return $up ? Http::response($this->answer()) : throw new ConnectionException('Connection refused');
        });
        $this->artisan('mycs:vat-rates:check')->assertSuccessful();

        $this->travel(1)->days();
        $up = false;
        $this->artisan('mycs:vat-rates:check')->assertFailed();
        $state = app(EuVatRates::class)->state();
        $this->assertNotNull($state['checked_at']);
        $this->assertSame(Health::WARN, Health::report()['checks']['vat_rates']['status']);

        $this->travel(1)->days();
        $up = true;
        $this->artisan('mycs:vat-rates:check')->assertSuccessful();
        $this->assertSame(Health::OK, Health::report()['checks']['vat_rates']['status']);
    }

    public function test_a_rate_staff_fix_after_the_check_is_ok_at_once(): void
    {
        TaxRate::query()->create(['name' => 'ΦΠΑ', 'rate' => 23, 'country' => 'GR']);
        $this->tedbAnswers($this->answer());
        $this->artisan('mycs:vat-rates:check')->assertSuccessful();
        $this->assertSame(Health::WARN, Health::report()['checks']['vat_rates']['status']);

        TaxRate::query()->where('country', 'GR')->update(['rate' => 24]);

        $this->assertSame(Health::OK, Health::report()['checks']['vat_rates']['status']);
        Http::assertSentCount(1); // Automation Status reads the kept answer, never TEDB
    }

    public function test_a_check_not_run_for_over_a_week_is_seen(): void
    {
        TaxRate::query()->create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        $this->tedbAnswers($this->answer());
        $this->artisan('mycs:vat-rates:check')->assertSuccessful();

        $this->travel(EuVatRates::STALE_DAYS + 1)->days();

        $health = Health::report()['checks']['vat_rates'];
        $this->assertSame(Health::WARN, $health['status']);
        $this->assertStringContainsString('Last checked', $health['detail']);
    }

    public function test_the_presets_cover_every_member_state(): void
    {
        $this->assertSame(StandardRates::EU, array_keys(StandardRates::rates()));
        $this->assertSame(VatCountry::EU, StandardRates::EU);
    }
}
