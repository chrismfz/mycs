<?php

namespace Tests\Feature;

use App\Models\TaxRate;
use App\Models\TicketBlockedSender;
use App\Services\Import\WhmcsSpamFilterImport;
use App\Services\Import\WhmcsTaxImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of #562 (ADV-562-n). Invented data.
 */
class ImportTaxLabelsFiltersAdversarialTest extends TestCase
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
            'CREATE TABLE tblticketspamfilters (id INTEGER PRIMARY KEY, type TEXT, content TEXT)',
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    /**
     * ADV-562-1 (Medium): a sender filter whose content has no domain («*@», «@», «x@») makes
     * TicketBlockedSender::normalise() throw a ValueError (idn_to_ascii on ''). It is no PDOException, so
     * ImportFromWhmcs::handle() does not catch it: the import dies after migrate:fresh with foreign_key_checks off and no
     * invoice, payment or password imported. One odd filter among the real ones must be skipped and said, not fatal.
     */
    public function test_a_sender_filter_without_a_domain_does_not_abort_the_import(): void
    {
        foreach ([[1, 'Sender', 'spam@example.test'], [2, 'Sender', '*@'], [3, 'Sender', 'later@example.test']] as $row) {
            $this->whmcs->prepare('INSERT INTO tblticketspamfilters VALUES (?, ?, ?)')->execute($row);
        }

        $result = (new WhmcsSpamFilterImport($this->whmcs))->run();

        $this->assertEqualsCanonicalizing(['spam@example.test', 'later@example.test'], TicketBlockedSender::query()->pluck('value')->all());
        $this->assertSame(1, $result['counts']['skipped']);
    }

    /**
     * ADV-562-2 (Medium): a 0.00% rule in WHMCS is a decision (an exempt country), not a stale list. `rate == whmcs_rate`
     * is true for it, so --update-imported raises it to the list's rate (CY 0 -> 19) and the next invoice charges VAT that
     * WHMCS did not. It should be kept and said, as the changed ones are.
     */
    public function test_a_zero_rated_whmcs_rule_is_not_raised_by_update_imported(): void
    {
        $this->whmcs->exec("INSERT INTO tbltax VALUES (1, 1, 'VAT', '', 'CY', 0)");
        (new WhmcsTaxImport($this->whmcs))->run();

        $this->artisan('mycs:vat-rates:seed', ['--update-imported' => true])->assertSuccessful();

        $this->assertEquals('0.00', TaxRate::query()->where('country', 'CY')->value('rate'));
    }
}
