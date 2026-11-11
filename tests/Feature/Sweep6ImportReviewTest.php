<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Import\WhmcsParity;
use App\Services\Import\WhmcsSecondFactor;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Independent and adversarial review of PR #764 (sweep 6, importer: client currency fallback and the pre-dump refusals).
 * Invented data only. Each test fails for the reason in its name (REV-764-n in the review's table).
 */
class Sweep6ImportReviewTest extends TestCase
{
    use RefreshDatabase;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 10:00:00');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function command(PDO $whmcs): ImportFromWhmcs
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $this->output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));

        return $command;
    }

    private function pdo(): PDO
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $whmcs;
    }

    /** A source with tblhosting, tblhostingaddons, tblinvoices and tblinvoiceitems for the cancelledRenewals check. */
    private function renewalsSource(): PDO
    {
        $whmcs = $this->pdo();
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, billingcycle TEXT, nextduedate TEXT, nextinvoicedate TEXT)');
        $whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INT, status TEXT, billingcycle TEXT, nextduedate TEXT, nextinvoicedate TEXT)');
        $whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, date TEXT, duedate TEXT, status TEXT)');
        $whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, amount REAL, duedate TEXT)');

        return $whmcs;
    }

    /** @return list<int|string> */
    private function cancelledRenewals(PDO $whmcs): array
    {
        $command = $this->command($whmcs);

        return (new ReflectionMethod($command, 'cancelledRenewals'))->invoke($command);
    }

    /**
     * REV-764-1: the addon check (new in #764) finds the renewal by the INVOICE's due date (COALESCE(i.duedate, ii.duedate)),
     * the line's own due date only when the invoice is deleted. WHMCS puts an addon's line on an invoice with other items,
     * whose due date is often another day (the invoice's is one date, each line keeps its own). So the same addon, the
     * same cancelled renewal, the same nextinvoicedate moved past it: refused when the invoice is DELETED (the line's date
     * is read), not refused when it is only CANCELLED and due another day. The period WHMCS gave up is then billed again
     * here, the harm S6-IMPORT-3 exists to stop.
     */
    public function test_rev_764_1_an_addon_renewal_cancelled_on_an_invoice_due_another_day_is_refused_or_never_billed_again(): void
    {
        $clientId = User::factory()->create()->client()->id;
        $whmcs = $this->pdo();
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT, groupid INT DEFAULT 0)');
        $whmcs->exec("CREATE TABLE tbladdons (id INTEGER PRIMARY KEY, packages TEXT, name TEXT, description TEXT, billingcycle TEXT, allowqty INT DEFAULT 0,
            tax INT DEFAULT 1, hidden INT DEFAULT 0, retired INT DEFAULT 0, autoactivate TEXT DEFAULT '')");
        $whmcs->exec('CREATE TABLE tblpricing (id INTEGER PRIMARY KEY, type TEXT, currency INT, relid INT, msetupfee REAL DEFAULT 0, qsetupfee REAL DEFAULT 0,
            ssetupfee REAL DEFAULT 0, asetupfee REAL DEFAULT 0, bsetupfee REAL DEFAULT 0, tsetupfee REAL DEFAULT 0, monthly REAL, quarterly REAL,
            semiannually REAL, annually REAL, biennially REAL, triennially REAL)');
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, nextduedate TEXT, nextinvoicedate TEXT)');
        $whmcs->exec("CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, orderid INT DEFAULT 0, hostingid INT, addonid INT, userid INT, name TEXT,
            qty INT DEFAULT 1, recurring REAL, billingcycle TEXT, tax TEXT, status TEXT, regdate TEXT, nextduedate TEXT, nextinvoicedate TEXT,
            paymentmethod TEXT DEFAULT 'banktransfer', notes TEXT DEFAULT '', subscriptionid TEXT DEFAULT '')");
        $whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT, duedate TEXT)');
        $whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $whmcs->exec('CREATE TABLE tblcancelrequests (id INTEGER PRIMARY KEY, date TEXT, relid INT, reason TEXT, type TEXT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $whmcs->exec("INSERT INTO tblclients (id, currency) VALUES ({$clientId}, 1)");
        $whmcs->exec("INSERT INTO tbladdons (id, packages, name, description, billingcycle, tax) VALUES (26, ',11,', 'Dedicated IP', '', 'recurring', 1)");
        $whmcs->exec("INSERT INTO tblpricing (type, currency, relid, monthly, quarterly, semiannually, annually, biennially, triennially) VALUES
            ('addon', 1, 26, 3, -1, -1, 36, -1, -1)");

        $product = Product::factory()->create(['id' => 11, 'name' => 'Business 10',
            'category_id' => Category::create(['name' => 'Shared Hosting', 'slug' => 'shared-hosting'])->id]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Annually',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 100, 'setup_fee' => 0, 'currency_code' => 'USD']);
        // A yearly service, its own dates fine (nextinvoicedate = nextduedate).
        $whmcs->exec("INSERT INTO tblhosting VALUES (1692, {$clientId}, 'Active', '2027-11-15', '2027-11-15')");
        Service::factory()->create(['id' => 1692, 'client_id' => $clientId, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 100, 'quantity' => 1, 'expires_at' => '2027-11-15',
            'domain' => 'site1692.example.test']);
        // Its monthly addon, on the service's day (15th): the renewal due 2026-11-15 invoiced and cancelled, nextinvoicedate on
        // to 12-15. The line keeps the addon's due date (11-15); the invoice it sits on is due 2026-11-10 (it carries
        // another item due that day, or staff moved its due date).
        $whmcs->exec("INSERT INTO tblhostingaddons (id, hostingid, addonid, userid, name, recurring, billingcycle, tax, status, regdate, nextduedate, nextinvoicedate)
            VALUES (123, 1692, 26, {$clientId}, '', 3, 'Monthly', '1', 'Active', '2025-05-15', '2026-11-15', '2026-12-15')");
        $whmcs->exec("INSERT INTO tblinvoices VALUES (950, {$clientId}, '', '2026-11-01', '2026-11-10', NULL, 'Cancelled', 3, 0, 0)");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (6001, 950, {$clientId}, 'Addon', 123, 'Dedicated IP', 3, 0, '2026-11-15')");

        $command = $this->command($whmcs);
        $step = fn (string $method) => (new ReflectionMethod($command, $method))->invoke($command);

        // Control: the very same line with its invoice deleted IS refused (the line's own due date is read then).
        $whmcs->exec('UPDATE tblinvoiceitems SET invoiceid = 9950 WHERE id = 6001');
        $this->assertSame(['addon #123'], $step('cancelledRenewals'), 'control: deleted, the line counts by its own due date');
        $whmcs->exec('UPDATE tblinvoiceitems SET invoiceid = 950 WHERE id = 6001');

        if ($step('cancelledRenewals') !== [] || $step('addonsApart') !== []) {
            $this->addToAssertionCount(1); // refused: fine

            return;
        }

        foreach (['importAddons', 'importInvoices', 'importInvoiceItems'] as $method) {
            $step($method);
        }
        $addon = Service::query()->where('whmcs_addon_id', 123)->sole();
        $this->assertSame('2026-11-15', $addon->expires_at->toDateString());

        $billed = (new RenewalBilling)->billRenewal($addon->id, Carbon::parse('2026-11-20'));

        $this->assertSame(0, $billed, 'not refused (the cancelled invoice is due 11-10, the addon\'s line 11-15), the import ran, and the'
            . ' period WHMCS gave up (2026-11-15..12-15) is billed again');
    }

    /**
     * REV-764-1b (the same rule, as it was before #764 for services): a service's renewal line due on its nextduedate, on
     * a Cancelled invoice due another day, is not refused either. Kept apart from REV-764-1: this half is not new.
     */
    public function test_rev_764_1b_a_service_renewal_cancelled_on_an_invoice_due_another_day_is_refused(): void
    {
        $whmcs = $this->renewalsSource();
        // Control (#30): cancelled invoice due on the service's nextduedate.
        $whmcs->exec("INSERT INTO tblhosting VALUES (30, 1, 'Active', 'Monthly', '2026-11-05', '2026-12-05')");
        $whmcs->exec("INSERT INTO tblinvoices VALUES (600, 1, '2026-10-22', '2026-11-05', 'Cancelled')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (1, 600, 1, 'Hosting', 30, 10, '2026-11-05')");
        // #31: the same, its line on an invoice due 2026-11-01 (with another item due then).
        $whmcs->exec("INSERT INTO tblhosting VALUES (31, 1, 'Active', 'Monthly', '2026-11-05', '2026-12-05')");
        $whmcs->exec("INSERT INTO tblinvoices VALUES (601, 1, '2026-10-22', '2026-11-01', 'Cancelled')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (2, 601, 1, 'Hosting', 31, 10, '2026-11-05')");

        $cancelled = $this->cancelledRenewals($whmcs);

        $this->assertContains(30, $cancelled, 'control');
        $this->assertContains(31, $cancelled, 'the line for the period due 2026-11-05 is on a cancelled invoice (due 11-01): given up in WHMCS, billed again here');
    }

    /**
     * REV-764-2: a false positive the LEFT JOIN brought. A live service whose period IS billed by a live (Unpaid) invoice,
     * that invoice due another day than the service (it carries other items; the line's own due date is the service's).
     * Before #764 nothing was refused. An old duplicate of that renewal that staff deleted leaves its line behind: the
     * deleted line is matched by its own due date, the live one is not (matched by the invoice's), so the service is
     * refused and staff are told to «bill that period again or give it away», with its invoice open.
     */
    public function test_rev_764_2_a_deleted_duplicate_does_not_refuse_a_service_whose_period_has_a_live_invoice_due_another_day(): void
    {
        $whmcs = $this->renewalsSource();
        $whmcs->exec("INSERT INTO tblhosting VALUES (40, 1, 'Active', 'Monthly', '2026-11-05', '2026-12-05')");
        // The live invoice of the period: due 2026-11-01 (another item's day), its Hosting line due 2026-11-05.
        $whmcs->exec("INSERT INTO tblinvoices VALUES (700, 1, '2026-10-22', '2026-11-01', 'Unpaid')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (1, 700, 1, 'Hosting', 40, 10, '2026-11-05')");

        $this->assertSame([], $this->cancelledRenewals($whmcs), 'control: with the live invoice alone nothing is refused');

        // The duplicate staff deleted (invoice #699 gone, its line left).
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (2, 699, 1, 'Hosting', 40, 10, '2026-11-05')");

        $this->assertSame([], $this->cancelledRenewals($whmcs), 'the period has a live unpaid invoice: nothing was given up, nothing to refuse');
    }

    /**
     * REV-764-3: a One Time or Free addon has no next period here (WhmcsAddonImport: expires_at null, a one-time or free
     * plan; RenewalBilling never bills it) and addonsApart leaves these cycles out. cancelledRenewals does not: with its
     * (first and only) invoice cancelled and a next invoice date past its due date it is refused, and staff are told to
     * «bill that period again or give it away» for an addon that is never billed again.
     */
    public function test_rev_764_3_a_one_time_or_free_addon_is_not_refused_as_a_cancelled_renewal(): void
    {
        $whmcs = $this->renewalsSource();
        $whmcs->exec("INSERT INTO tblhosting VALUES (50, 1, 'Active', 'Monthly', '2026-12-01', '2026-12-01')");
        // Control: a monthly addon is refused.
        $whmcs->exec("INSERT INTO tblhostingaddons VALUES (60, 50, 'Active', 'Monthly', '2026-11-01', '2026-12-01')");
        $whmcs->exec("INSERT INTO tblinvoices VALUES (800, 1, '2026-10-25', '2026-11-01', 'Cancelled')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (1, 800, 1, 'Addon', 60, 5, '2026-11-01')");
        foreach ([61 => 'One Time', 62 => 'Free', 63 => 'Free Account'] as $id => $cycle) {
            $whmcs->exec("INSERT INTO tblhostingaddons VALUES ({$id}, 50, 'Active', '{$cycle}', '2026-11-01', '2026-12-01')");
            $whmcs->exec('INSERT INTO tblinvoices VALUES (' . ($id + 800) . ", 1, '2026-10-25', '2026-11-01', 'Cancelled')");
            $whmcs->exec("INSERT INTO tblinvoiceitems VALUES ({$id}, " . ($id + 800) . ", 1, 'Addon', {$id}, 5, '2026-11-01')");
        }
        $command = $this->command($whmcs);
        // The monthly control is a month behind its service with nothing unpaid: refused for its date too since sweep 7
        // (S7-LATEST-FIXES-1); the one-time and free ones are left out there.
        $this->assertSame([60], (new ReflectionMethod($command, 'addonsApart'))->invoke($command), 'addonsApart: the one-time and free ones are left out there');

        $this->assertSame(['addon #60'], $this->cancelledRenewals($whmcs), 'only the recurring addon has a period to bill again');
    }

    /**
     * REV-764-4 (the author's open point): a source with no default currency and no EUR. The fallback writes 'EUR' on the
     * account's credit while importCurrencies brought no EUR row: money in a currency this instance does not have (no
     * currencies row: Credit::currency and Invoice::currency are null, no price in it, no report lists it).
     */
    public function test_rev_764_4_the_fallback_never_writes_a_currency_the_instance_does_not_have(): void
    {
        $whmcs = $this->pdo();
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT, prefix TEXT, suffix TEXT, format INT, rate REAL, `default` INT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD', '$', ' USD', 1, 1.0, 0), (2, 'GBP', '£', ' GBP', 1, 0.8, 0)");
        $whmcs->exec('CREATE TABLE tblusers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, password TEXT,
            second_factor TEXT, second_factor_config TEXT, email_verified_at TEXT, created_at TEXT, updated_at TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT,
            tax_id TEXT, phonenumber TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT,
            taxexempt INT, separateinvoices INT, disableautocc INT, overideduenotices INT, credit REAL, currency INT, created_at TEXT, updated_at TEXT)');
        $whmcs->exec('CREATE TABLE tblusers_clients (id INTEGER PRIMARY KEY, auth_user_id INT, client_id INT, owner INT, permissions TEXT,
            last_login TEXT, created_at TEXT, updated_at TEXT)');
        $whmcs->prepare('INSERT INTO tblusers VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([7, 'Ada', 'Owner', 'ada@login.test', Hash::make('Whmcs-Pass-1'), '', null, null, '2024-01-01 00:00:00', '2025-01-01 00:00:00']);
        $whmcs->exec("INSERT INTO tblclients VALUES (300, 'Ada', 'Owner', '', 'billing@company.test', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'GR',
            0, 0, 0, 0, 5.00, 0, '2024-01-01 00:00:00', '2025-01-01 00:00:00')");
        $whmcs->exec("INSERT INTO tblusers_clients VALUES (1, 7, 300, 1, NULL, NULL, '2024-01-01 00:00:00', NULL)");

        $command = $this->command($whmcs);
        $command->secondFactor = new WhmcsSecondFactor(null);
        DB::table('currencies')->delete(); // as handle does before importCurrencies
        (new ReflectionMethod($command, 'importCurrencies'))->invoke($command);
        (new ReflectionMethod($command, 'importUsers'))->invoke($command);

        $code = (string) DB::table('credits')->where('client_id', 300)->value('currency_code');
        // The owner's rule is «the default currency, else EUR» (2026-11-08): the source is not refused and no other currency
        // is taken; the instance gets the EUR row instead (importCurrencies). The reviewer's control expected GBP and USD only.
        $this->assertSame(['EUR', 'GBP', 'USD'], DB::table('currencies')->orderBy('code')->pluck('code')->all(), 'the currencies imported, and EUR for the fallback');
        $this->assertTrue(DB::table('currencies')->where('code', $code)->exists(),
            "the account's 5.00 credit is written in {$code}, a currency with no row here (the source has no default currency and no EUR):"
            . ' refuse the source or take a currency it has');
    }

    /**
     * REV-764-5: one place still reads tblclients.currency its own way. Parity's note counts DISTINCT c.currency: an
     * account with currency 0 (imported in the default currency, EUR here) next to the EUR accounts makes «2 currencies»,
     * and the report says the invoices' amounts are summed across currencies when every invoice is in EUR.
     */
    public function test_rev_764_5_parity_counts_the_currencies_as_the_importer_assigns_them(): void
    {
        $whmcs = $this->pdo();
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT, `default` INT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR', 1)");
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $whmcs->exec('INSERT INTO tblclients VALUES (1, 1), (2, 0)');
        $whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, status TEXT, total REAL)');
        $whmcs->exec("INSERT INTO tblinvoices VALUES (1, 1, 'Unpaid', 10), (2, 2, 'Unpaid', 10)");
        $whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, amount REAL)');
        $whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, amountin REAL, amountout REAL, refundid INT)');

        $notes = implode("\n", (new WhmcsParity($whmcs))->notes());

        $this->assertStringNotContainsString('2 currencies', $notes, 'both accounts are imported in EUR (the second by the default-currency rule)');
    }
}
