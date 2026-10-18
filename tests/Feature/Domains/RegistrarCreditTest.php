<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainRegistrarConnectionResource\Pages\ListDomainRegistrarConnections;
use App\Admin\Widgets\RegistrarCredit;
use App\Models\DomainRegistrarConnection;
use App\Models\EmailLog;
use App\Models\Staff;
use App\Services\Domains\RegistrarBalances;
use App\Services\Domains\Registrars\GrEppRegistrar;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * G8-1, «Registrar credit» (owner 2026-10-19): what is left with the .gr registry and Openprovider, read hourly and kept
 * on the connection; the dashboard shows what was read, never calls a registrar; staff mailed once when it is low,
 * suspended or unread.
 */
class RegistrarCreditTest extends TestCase
{
    use RefreshDatabase;

    private FakeTransport $transport;

    private DomainRegistrarConnection $gr;

    private DomainRegistrarConnection $op;

    private string $opBalance = '151.41';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
        Http::preventStrayRequests();
        config(['settings.mail_disable' => false, 'settings.system_email_address' => 'staff@example.test']);
        $this->transport = new FakeTransport;
        GrEppRegistrar::$transport = fn () => $this->transport;
        GrEppRegistrar::reset();
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => '.gr registry', 'is_active' => true, 'mode' => 'production']);
        $this->gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'Openprovider', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        DomainRegistrarConnection::query()->create(['registrar' => 'manual', 'label' => 'By hand', 'is_active' => true, 'mode' => 'production']);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if (str_ends_with($request->url(), '/v1beta/resellers')) {
                return Http::response(['code' => 0, 'data' => ['id' => 1, 'company_name' => 'Example', 'balance' => (float) $this->opBalance, 'reserved_balance' => 12.5]]);
            }

            return Http::response([], 404);
        });
    }

    protected function tearDown(): void
    {
        GrEppRegistrar::$transport = null;
        GrEppRegistrar::reset();
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** account:info as the guide's example 66 gives it: -25 EUR, to be suspended on 16/01/2012. */
    private function registryAnswers(string $balance = '-25', bool $suspends = true, bool $suspended = false): void
    {
        $body = (string) file_get_contents(base_path('packages/gr-epp/tests/fixtures/v4.3/66-account-info-response.xml'));
        $body = str_replace('>-25<', ">{$balance}<", $body);
        if ($suspended) {
            $body = str_replace('<account:tbSuspendedOn>2012-01-16T10:00:00Z</account:tbSuspendedOn>', '<account:suspendedOn>2012-01-20T10:00:00Z</account:suspendedOn>', $body);
        }
        if (!$suspends) {
            $body = (string) preg_replace('#<account:tbSuspendedOn>[^<]*</account:tbSuspendedOn>#', '', $body);
        }
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml')
            ->queue(new HttpResponse(200, (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', $body)));
    }

    private function mails(): int
    {
        return EmailLog::query()->where('template_key', 'system')->where('subject', 'like', 'Registrar credit%')->count();
    }

    public function test_both_registrars_are_read_and_kept(): void
    {
        $this->registryAnswers('38.50', suspends: false);

        $this->artisan('domains:balances')->assertSuccessful();

        $this->assertSame(['amount' => '38.50', 'currency' => 'EUR', 'charges_allowed' => true, 'suspends_on' => null],
            array_intersect_key($this->gr->fresh()->balance, array_flip(['amount', 'currency', 'charges_allowed', 'suspends_on'])));
        $this->assertSame(['amount' => '151.41', 'currency' => 'EUR', 'reserved' => '12.50'],
            array_intersect_key($this->op->fresh()->balance, array_flip(['amount', 'reserved', 'currency'])));
        $this->assertSame(['.gr registry', 'Openprovider'], app(RegistrarBalances::class)->connections()->pluck('label')->all(), 'not the manual one');
        $this->assertSame('info', $this->transport->kinds()[2] ?? null, 'account:info');
        $this->assertSame(0, $this->mails(), 'nothing wrong, no low balance set: nobody mailed');
        $this->assertFalse($this->gr->fresh()->audits()->where('event', 'updated')->exists(), 'a read is not audited');
    }

    public function test_staff_are_told_once_when_it_falls_under_its_low_balance(): void
    {
        $this->op->update(['low_balance' => '200']);

        app(RegistrarBalances::class)->refresh($this->op->fresh());
        $this->assertSame(1, $this->mails());
        $this->assertSame(__('Under :low', ['low' => '200.00']), RegistrarBalances::problem($this->op->fresh()));

        $this->opBalance = '140.00';
        app(RegistrarBalances::class)->refresh($this->op->fresh());
        $this->assertSame(1, $this->mails(), 'still under: told already');

        $this->opBalance = '500.00';
        app(RegistrarBalances::class)->refresh($this->op->fresh());
        $this->assertNull(RegistrarBalances::problem($this->op->fresh()));
        $this->opBalance = '20.00';
        app(RegistrarBalances::class)->refresh($this->op->fresh());
        $this->assertSame(2, $this->mails(), 'under it again after it was fine: told again');
    }

    public function test_a_suspension_is_told_whatever_the_low_balance(): void
    {
        $this->registryAnswers();

        app(RegistrarBalances::class)->refresh($this->gr->fresh());

        $this->assertSame('-25', $this->gr->fresh()->balance['amount']);
        $this->assertSame(__('To be suspended on :date', ['date' => '16/01/2012']), RegistrarBalances::problem($this->gr->fresh()));
        $this->assertSame(1, $this->mails());

        // Suspended: the registry's suspendedOn.
        GrEppRegistrar::reset();
        $this->transport = new FakeTransport;
        $this->registryAnswers(suspended: true);
        app(RegistrarBalances::class)->refresh($this->gr->fresh());
        $this->assertSame(__('Suspended since :date: no registrations or renewals', ['date' => '20/01/2012']), RegistrarBalances::problem($this->gr->fresh()));
        $this->assertSame(2, $this->mails(), 'another state: told');
    }

    public function test_a_failed_read_keeps_the_last_balance_and_tells_after_a_day(): void
    {
        $this->registryAnswers('38.50', suspends: false);
        app(RegistrarBalances::class)->refresh($this->gr->fresh());
        GrEppRegistrar::reset();
        $this->transport = new FakeTransport;
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->result(2200, 'Λάθος ταυτοποίηση χρήστη');

        app(RegistrarBalances::class)->refresh($this->gr->fresh());
        $balance = $this->gr->fresh()->balance;
        $this->assertSame('38.50', $balance['amount'], 'the last one read kept');
        $this->assertNotNull($balance['error']);
        $this->assertStringNotContainsString('Secret-pw1', (string) $balance['error']);
        $this->assertNull(RegistrarBalances::problem($this->gr->fresh()), 'an hour unread is not news');

        Carbon::setTestNow(now()->addHours(RegistrarBalances::STALE_HOURS + 1));
        $this->assertNotNull(RegistrarBalances::problem($this->gr->fresh()), 'a day unread is');
    }

    public function test_the_dashboard_shows_what_was_read_and_calls_no_registrar(): void
    {
        $this->registryAnswers('38.50', suspends: false);
        $this->artisan('domains:balances')->assertSuccessful();
        $this->op->update(['low_balance' => '200']);
        GrEppRegistrar::reset(); // its session ended
        $sent = count($this->transport->sent);
        Http::fake(fn () => throw new \RuntimeException('a page called a registrar'));

        $staff = Staff::factory()->withPermissions(['admin.widgets.registrar_credit'])->create();
        $this->actingAs($staff);
        $this->assertTrue(RegistrarCredit::canView());
        $this->livewireAs($staff)->test(RegistrarCredit::class)->assertSee('38,50 €')->assertSee('151,41 €')->assertSee('.gr registry')
            ->assertDontSee('By hand');
        $this->assertCount($sent, $this->transport->sent);

        $this->actingAs(Staff::factory()->withPermissions([])->create());
        $this->assertFalse(RegistrarCredit::canView());
    }

    public function test_staff_read_a_balance_now_from_its_connection(): void
    {
        $staff = Staff::factory()->withPermissions(['admin.domain_registrars.viewAny', 'admin.domain_registrars.update'])->create();

        $this->livewireAs($staff)->test(ListDomainRegistrarConnections::class)->callTableAction('balance', $this->op)->assertNotified();

        $this->assertSame('151.41', $this->op->fresh()->balance['amount']);
    }
}
