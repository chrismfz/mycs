<?php

namespace Tests\Feature;

use App\Classes\Settings;
use App\Models\Currency;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MyCS\EuVatRates\Tedb;
use MyCS\EuVatRates\TedbUnavailable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Adversarial review of #525 (EU standard VAT rates: the seed into Tax Rates and the weekly TEDB check).
 */
class Adv525Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config(['settings.eu_vat_enabled' => false, 'settings.eu_vat_home_country' => null, 'settings.tax_home_country' => null]);
    }

    private function clientWithoutCountry()
    {
        $client = User::factory()->create()->client();
        $client->primaryContact->update(['country' => null]); // imported from WHMCS without a country (#524)

        return $client->fresh();
    }

    private function taxFor($client): float
    {
        app()->forgetScopedInstances(); // a new request/job: no memoised rates
        $rate = Settings::tax($client);

        return $rate === 0 ? 0.0 : (float) $rate->rate;
    }

    /**
     * ADV-525-1 (failing): myip as documented (only GR, no «all», no Company country). #524's owner decision: an
     * account without a country pays the company's own rate, found as «the country of the only rate there is», never
     * 0%. Seeding the EU rates (staff answer «yes», or --force, or `mycs:install --eu-vat-rates`) makes it 27 rates:
     * onlyRateCountry() is null, no «all» row, so every such account is now invoiced at 0%. The seed knows the home
     * country it is about to erase (onlyRateCountry before it adds) but only prints a hint; it should keep it
     * (write it as Company country) or refuse.
     */
    public function test_adv_525_1_seeding_on_myip_drops_accounts_without_a_country_to_zero_percent(): void
    {
        TaxRate::query()->create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        $client = $this->clientWithoutCountry();
        $this->assertSame(24.0, $this->taxFor($client), 'precondition: #524 fallback to the only rate');

        $this->artisan('mycs:vat-rates:seed')->expectsConfirmation('Add the missing EU rates anyway?', 'yes')->assertSuccessful();

        $this->assertSame(27, TaxRate::query()->count());
        $this->assertSame(24.0, $this->taxFor($client), 'an account without a country is now invoiced at 0% after the seed');
    }

    /**
     * ADV-525-2 (failing): `mycs:install --force` is the re-run on an instance that already has users; with
     * --eu-vat-rates (or «yes» to the interactive question) it calls the seed with --force unconditionally. On a
     * fresh install Tax Rates is empty and --force changes nothing; it only matters exactly when the instance already
     * bills (GR + «all»), the case the seed's guard exists for. The guard is skipped with no warning: every EU
     * customer who paid «all» now pays their own country's rate from the next invoice.
     */
    public function test_adv_525_2_install_force_bypasses_the_seeds_existing_rates_guard(): void
    {
        Setting::query()->delete();
        Currency::query()->delete();
        Role::query()->delete();
        putenv('MYCS_ADMIN_PASSWORD=Secret123');
        $options = ['--company' => 'Test Hosting', '--url' => 'https://my.example.test/', '--first-name' => 'Test',
            '--last-name' => 'Admin', '--email' => 'admin@example.test', '--no-interaction' => true];
        try {
            $this->artisan('mycs:install', $options)->assertSuccessful();
            TaxRate::query()->create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
            TaxRate::query()->create(['name' => 'All other', 'rate' => 24, 'country' => 'all']);

            $this->artisan('mycs:install', ['--force' => true, '--eu-vat-rates' => true] + $options);
        } finally {
            putenv('MYCS_ADMIN_PASSWORD');
        }

        $this->assertSame(2, TaxRate::query()->count(), 'install --force seeded 26 rates over a billing instance\'s GR + «all» without the guard');
    }

    /** @return iterable<string, array{string}> */
    public static function implausibleRates(): iterable
    {
        yield 'zero' => ['0.0'];
        yield 'a hundred' => ['100.0'];
        yield 'nine hundred' => ['999.0'];
        yield 'below the EU minimum' => ['5.0'];
    }

    /**
     * ADV-525-3 (failing): Tedb::parse takes any 0–999.99 as a member state's standard rate. Directive 2006/112/EC
     * sets a 15% minimum, so a STANDARD/DEFAULT of 0, 5 or 100 is a broken (or hostile) answer, which the class
     * docblock says is «no answer». It is instead compared and mailed to staff as «EU VAT rate changed … change the
     * rate in Tax Rates on that day», the instruction that leads staff to charge it. And «the national rate is the
     * highest» means one stray high row for a state wins outright.
     */
    #[DataProvider('implausibleRates')]
    public function test_adv_525_3_an_implausible_standard_rate_is_no_answer(string $value): void
    {
        $xml = (string) file_get_contents(base_path('packages/eu-vat-rates/tests/fixtures/tedb-answer.xml'));
        $xml = str_replace('<memberState>EL</memberState><type>STANDARD</type><rate><type>DEFAULT</type><value>24.0</value>',
            "<memberState>EL</memberState><type>STANDARD</type><rate><type>DEFAULT</type><value>{$value}</value>", $xml, $count);
        $this->assertSame(1, $count, 'fixture changed');

        $this->expectException(TedbUnavailable::class);
        $rates = Tedb::parse($xml);
        $this->fail("accepted GR {$rates['GR']}% as Greece's standard rate");
    }

    /**
     * Pinned (passes): a country staff (or an import) wrote in lower case is that country: the seed adds no second
     * Greece, and an «all» row is never taken for a country.
     */
    public function test_pinned_a_lower_case_country_is_not_seeded_twice(): void
    {
        TaxRate::query()->create(['name' => 'ΦΠΑ', 'rate' => 13, 'country' => 'gr']);
        TaxRate::query()->create(['name' => 'Everyone', 'rate' => 24, 'country' => 'all']);

        $this->artisan('mycs:vat-rates:seed', ['--force' => true])->assertSuccessful();

        // One Greece, staff's: compared in upper case, as MariaDB's collation finds «gr» for «GR» and SQLite does not.
        $this->assertSame(['gr'], TaxRate::query()->whereRaw('upper(country) = ?', ['GR'])->pluck('country')->all());
        $this->assertSame(28, TaxRate::query()->count());
    }
}
