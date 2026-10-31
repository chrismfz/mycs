<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\TicketBlockedSender;
use App\Models\User;
use App\Services\Import\WhmcsServiceLabelImport;
use App\Services\Import\WhmcsSpamFilterImport;
use App\Services\Import\WhmcsTaxImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

/**
 * WHMCS's tax, the names customers gave their services, and the ticket spam filters (the sweep of 2026-10-31). The
 * rates come as WHMCS charged them, marked, so the seed can bring the stale ones to the checked list and only those.
 * Invented data.
 */
class ImportTaxLabelsFiltersTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tbltax (id INTEGER PRIMARY KEY, level INT, name TEXT, state TEXT, country TEXT, taxrate REAL)',
            'CREATE TABLE tblconfiguration (setting TEXT, value TEXT)',
            'CREATE TABLE mod_clientproductdescriptions (id INTEGER PRIMARY KEY, pid TEXT, type TEXT, description TEXT)',
            'CREATE TABLE tblticketspamfilters (id INTEGER PRIMARY KEY, type TEXT, content TEXT)',
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    private function tax(int $id, string $country, float $rate, int $level = 1, string $state = '', string $name = 'VAT'): void
    {
        $this->whmcs->prepare('INSERT INTO tbltax VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $level, $name, $state, $country, $rate]);
    }

    private function config(array $values): void
    {
        foreach ($values as $setting => $value) {
            $this->whmcs->prepare('INSERT INTO tblconfiguration VALUES (?, ?)')->execute([$setting, $value]);
        }
    }

    private function setting(string $key): ?string
    {
        return Setting::query()->where('key', $key)->whereNull('settingable_type')->value('value');
    }

    // ---- tax --------------------------------------------------------------------------------------------------------

    public function test_the_rates_come_as_whmcs_charged_them_and_marked(): void
    {
        $this->tax(1, 'EE', 20);
        $this->tax(2, 'GR', 24);

        $result = (new WhmcsTaxImport($this->whmcs))->run();

        $this->assertSame(2, $result['counts']['rates']);
        $this->assertEquals(['20.00', '20.00'], [TaxRate::query()->where('country', 'EE')->value('rate'), TaxRate::query()->where('country', 'EE')->value('whmcs_rate')]);
        $this->assertEquals('24.00', TaxRate::query()->where('country', 'GR')->value('rate'));
    }

    public function test_a_state_rule_a_second_level_and_a_repeated_country_are_said_not_carried(): void
    {
        $this->tax(1, 'US', 7, state: 'California');
        $this->tax(2, 'GR', 24, level: 2);
        $this->tax(3, 'DE', 19);
        $this->tax(4, 'DE', 7);
        $this->tax(5, 'Atlantis!', 5);

        $result = (new WhmcsTaxImport($this->whmcs))->run();

        $this->assertSame(['DE'], TaxRate::query()->pluck('country')->all());
        $this->assertEquals('19.00', TaxRate::query()->value('rate'), 'the first of a country is kept');
        $this->assertSame(4, $result['counts']['skipped']);
    }

    public function test_a_rule_for_any_country_is_the_all_rate(): void
    {
        $this->tax(1, '', 24, name: 'Any');

        (new WhmcsTaxImport($this->whmcs))->run();

        $this->assertEquals('24.00', TaxRate::query()->where('country', 'all')->value('rate'));
    }

    public function test_the_tax_settings_come_and_the_company_country_is_left_to_the_eu_home(): void
    {
        $this->config(['TaxEnabled' => 'on', 'TaxType' => 'Exclusive', 'TaxEUTaxExempt' => '1', 'TaxEUTaxValidation' => '1', 'TaxEUHomeCountry' => 'EE', 'TaxCode' => 'EE 102019025']);

        (new WhmcsTaxImport($this->whmcs))->run();

        $this->assertSame('1', $this->setting('tax_enabled'));
        $this->assertSame('exclusive', $this->setting('tax_type'));
        $this->assertSame('1', $this->setting('eu_vat_enabled'));
        $this->assertSame('EE', $this->setting('eu_vat_home_country'));
        $this->assertSame('EE102019025', $this->setting('eu_vat_requester'));
        $this->assertNull($this->setting('tax_home_country'));
    }

    public function test_a_tax_code_that_is_not_a_vat_number_and_a_home_that_is_not_a_member_state_are_not_taken(): void
    {
        $this->config(['TaxEUHomeCountry' => 'US', 'TaxCode' => 'tax-id 7']);

        $result = (new WhmcsTaxImport($this->whmcs))->run();

        $this->assertNull($this->setting('eu_vat_home_country'));
        $this->assertNull($this->setting('eu_vat_requester'));
        $this->assertStringContainsString('no member state', implode("\n", $result['notes']));
    }

    // ---- the seed brings the stale ones up ----------------------------------------------------------------------------

    public function test_the_seed_brings_an_untouched_imported_rate_to_the_checked_list_and_keeps_what_staff_changed(): void
    {
        $this->tax(1, 'EE', 20);
        $this->tax(2, 'FI', 24);
        $this->tax(3, 'GR', 24);
        (new WhmcsTaxImport($this->whmcs))->run();
        TaxRate::query()->where('country', 'FI')->update(['rate' => 25.0]); // staff, since the import

        $this->artisan('mycs:vat-rates:seed', ['--update-imported' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertEquals('20.00', TaxRate::query()->where('country', 'EE')->value('rate'), 'a dry run changes nothing');

        $this->artisan('mycs:vat-rates:seed', ['--update-imported' => true])->assertSuccessful();

        $ee = TaxRate::query()->where('country', 'EE')->first();
        $this->assertEquals('24.00', $ee->rate);
        $this->assertNull($ee->whmcs_rate, 'ours now');
        $this->assertEquals('25.00', TaxRate::query()->where('country', 'FI')->value('rate'), 'staff\'s stays');
        $this->assertEquals('24.00', TaxRate::query()->where('country', 'GR')->value('rate'));
        $this->assertSame(0, TaxRate::query()->where('country', 'LU')->count(), 'no country is added by it');
    }

    public function test_the_seed_never_touches_a_rate_staff_wrote(): void
    {
        TaxRate::query()->create(['name' => 'VAT', 'rate' => '20.00', 'country' => 'EE']);

        $this->artisan('mycs:vat-rates:seed', ['--update-imported' => true])->assertSuccessful();

        $this->assertEquals('20.00', TaxRate::query()->where('country', 'EE')->value('rate'));
    }

    // ---- labels -----------------------------------------------------------------------------------------------------

    public function test_a_services_name_comes_as_its_label_only_for_a_service_that_came(): void
    {
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id]);
        $this->whmcs->prepare('INSERT INTO mod_clientproductdescriptions VALUES (?, ?, ?, ?)')->execute([1, (string) $service->id, 'tblhosting', ' web32 &amp; co ']);
        $this->whmcs->prepare('INSERT INTO mod_clientproductdescriptions VALUES (?, ?, ?, ?)')->execute([2, '999999', 'tblhosting', 'gone']);
        $this->whmcs->prepare('INSERT INTO mod_clientproductdescriptions VALUES (?, ?, ?, ?)')->execute([3, (string) $service->id, 'tbldomains', 'a domain\'s']);
        $this->whmcs->prepare('INSERT INTO mod_clientproductdescriptions VALUES (?, ?, ?, ?)')->execute([4, (string) $service->id, 'tblhosting', '  ']);

        $result = (new WhmcsServiceLabelImport($this->whmcs))->run();

        $this->assertSame('web32 & co', $service->fresh()->label);
        $this->assertSame(1, $result['counts']['labels']);
    }

    // ---- spam filters -----------------------------------------------------------------------------------------------

    public function test_sender_filters_become_blocked_senders_once_and_the_others_are_said(): void
    {
        foreach ([[1, 'sender', 'Spam@Example.test'], [2, 'sender', 'spam@example.test'], [3, 'sender', '*@bad.test'], [4, 'subject', 'cheap pills'], [5, 'phrase', 'win now']] as $row) {
            $this->whmcs->prepare('INSERT INTO tblticketspamfilters VALUES (?, ?, ?)')->execute($row);
        }

        $result = (new WhmcsSpamFilterImport($this->whmcs))->run();

        $this->assertSame(2, $result['counts']['senders']);
        $this->assertEqualsCanonicalizing(['spam@example.test', '@bad.test'], TicketBlockedSender::query()->pluck('value')->all());
        $this->assertStringContainsString('2 spam filter(s)', implode("\n", $result['notes']));
    }

    public function test_a_rerun_adds_no_second_blocked_sender(): void
    {
        $this->whmcs->prepare('INSERT INTO tblticketspamfilters VALUES (?, ?, ?)')->execute([1, 'sender', 'a@b.test']);

        (new WhmcsSpamFilterImport($this->whmcs))->run();
        (new WhmcsSpamFilterImport($this->whmcs))->run();

        $this->assertSame(1, TicketBlockedSender::query()->count());
    }

    /** A staff edit takes the mark off: set back to the imported value later, no seed overwrites it. */
    public function test_a_rate_staff_changed_is_no_longer_marked(): void
    {
        $this->tax(1, 'EE', 20);
        (new WhmcsTaxImport($this->whmcs))->run();
        $rate = TaxRate::query()->where('country', 'EE')->firstOrFail();

        $rate->update(['rate' => 22]);
        $rate->update(['rate' => 20]);
        $this->artisan('mycs:vat-rates:seed', ['--update-imported' => true])->assertSuccessful();

        $this->assertNull($rate->fresh()->whmcs_rate);
        $this->assertEquals('20.00', $rate->fresh()->rate);
    }

    public function test_a_bare_word_or_an_address_without_a_domain_is_no_blocked_sender(): void
    {
        foreach ([[1, 'sender', 'gmail.com'], [2, 'sender', 'x@'], [3, 'sender', '@'], [4, 'sender', 'ok@example.test']] as $row) {
            $this->whmcs->prepare('INSERT INTO tblticketspamfilters VALUES (?, ?, ?)')->execute($row);
        }

        $result = (new WhmcsSpamFilterImport($this->whmcs))->run();

        $this->assertSame(['ok@example.test'], TicketBlockedSender::query()->pluck('value')->all());
        $this->assertStringContainsString('3 sender filter(s)', implode("\n", $result['notes']));
    }

    public function test_a_greek_vat_number_written_el_is_kept_as_written(): void
    {
        $this->config(['TaxCode' => 'EL123456789']);

        (new WhmcsTaxImport($this->whmcs))->run();

        $this->assertSame('EL123456789', $this->setting('eu_vat_requester'));
    }
}
