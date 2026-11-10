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
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Sweep 6, adversarial verifier of the latest fixes (#759, #760, #761): S6-LATEST-FIXES-3 only, the reviewer's test as
 * written (adv/sweep6-latest-fixes), with the line's due date WHMCS keeps (tblinvoiceitems.duedate) in the fixture.
 */
class Sweep6LatestFixesAddonRenewalTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private int $clientId;

    private ImportFromWhmcs $command;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 10:00:00');
        Queue::fake();
        $this->clientId = User::factory()->create()->client()->id;
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT, groupid INT DEFAULT 0)');
        $this->whmcs->exec("CREATE TABLE tbladdons (id INTEGER PRIMARY KEY, packages TEXT, name TEXT, description TEXT, billingcycle TEXT, allowqty INT DEFAULT 0,
            tax INT DEFAULT 1, hidden INT DEFAULT 0, retired INT DEFAULT 0, autoactivate TEXT DEFAULT '')");
        $this->whmcs->exec('CREATE TABLE tblpricing (id INTEGER PRIMARY KEY, type TEXT, currency INT, relid INT, msetupfee REAL DEFAULT 0, qsetupfee REAL DEFAULT 0,
            ssetupfee REAL DEFAULT 0, asetupfee REAL DEFAULT 0, bsetupfee REAL DEFAULT 0, tsetupfee REAL DEFAULT 0, monthly REAL, quarterly REAL,
            semiannually REAL, annually REAL, biennially REAL, triennially REAL)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, nextduedate TEXT, nextinvoicedate TEXT)');
        $this->whmcs->exec("CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, orderid INT DEFAULT 0, hostingid INT, addonid INT, userid INT, name TEXT,
            qty INT DEFAULT 1, recurring REAL, billingcycle TEXT, tax TEXT, status TEXT, regdate TEXT, nextduedate TEXT, nextinvoicedate TEXT,
            paymentmethod TEXT DEFAULT 'banktransfer', notes TEXT DEFAULT '', subscriptionid TEXT DEFAULT '')");
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT, duedate TEXT)');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $this->whmcs->exec('CREATE TABLE tblcancelrequests (id INTEGER PRIMARY KEY, date TEXT, relid INT, reason TEXT, type TEXT)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $this->whmcs->exec("INSERT INTO tblclients (id, currency) VALUES ({$this->clientId}, 1)");
        $this->whmcs->exec("INSERT INTO tbladdons (id, packages, name, description, billingcycle, tax) VALUES (26, ',11,', 'Dedicated IP', '', 'recurring', 1)");
        $this->whmcs->exec("INSERT INTO tblpricing (type, currency, relid, monthly, quarterly, semiannually, annually, biennially, triennially) VALUES
            ('addon', 1, 26, 3, -1, -1, 36, -1, -1)");

        $this->command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($this->command, 'pdo'))->setValue($this->command, $this->whmcs);
        $this->command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invokeStep(string $method): mixed
    {
        return (new ReflectionMethod($this->command, $method))->invoke($this->command);
    }

    /**
     * S6-LATEST-FIXES-3: cancelledRenewals checks only Hosting lines. A live monthly addon whose own renewal (due on its
     * nextduedate) WHMCS invoiced and staff cancelled, its nextinvoicedate moved a month on, is not refused, and its
     * period WHMCS gave up is billed again here: the harm the refusal exists to stop for services.
     */
    public function test_an_addons_own_cancelled_renewal_is_refused_or_never_billed_again(): void
    {
        $product = Product::factory()->create(['id' => 11, 'name' => 'Business 10',
            'category_id' => Category::create(['name' => 'Shared Hosting', 'slug' => 'shared-hosting'])->id]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Annually',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 100, 'setup_fee' => 0, 'currency_code' => 'USD']);
        // A yearly service, its own dates fine (nextinvoicedate = nextduedate).
        $this->whmcs->exec("INSERT INTO tblhosting VALUES (1692, {$this->clientId}, 'Active', '2027-11-15', '2027-11-15')");
        Service::factory()->create(['id' => 1692, 'client_id' => $this->clientId, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 100, 'quantity' => 1, 'expires_at' => '2027-11-15',
            'domain' => 'site1692.example.test']);
        // Its monthly addon, on the service's day (15th): the renewal due 2026-11-15 invoiced and cancelled, nextinvoicedate on to 12-15.
        $this->whmcs->exec("INSERT INTO tblhostingaddons (id, hostingid, addonid, userid, name, recurring, billingcycle, tax, status, regdate, nextduedate, nextinvoicedate)
            VALUES (123, 1692, 26, {$this->clientId}, '', 3, 'Monthly', '1', 'Active', '2025-05-15', '2026-11-15', '2026-12-15')");
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (950, {$this->clientId}, '', '2026-11-01', '2026-11-15', NULL, 'Cancelled', 3, 0, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES (6001, 950, {$this->clientId}, 'Addon', 123, 'Dedicated IP', 3, 0, '2026-11-15')");

        $refused = $this->invokeStep('cancelledRenewals') !== [] || $this->invokeStep('addonsApart') !== [];
        if ($refused) {
            $this->assertTrue($refused);

            return;
        }

        foreach (['importAddons', 'importInvoices', 'importInvoiceItems'] as $step) {
            $this->invokeStep($step);
        }
        $addon = Service::query()->where('whmcs_addon_id', 123)->sole();
        $this->assertSame('2026-11-15', $addon->expires_at->toDateString());

        $billed = (new RenewalBilling)->billRenewal($addon->id, Carbon::parse('2026-11-20'));

        $this->assertSame(0, $billed, 'the import ran, and the addon\'s period WHMCS gave up (2026-11-15..12-15, invoice cancelled, nextinvoicedate past it) is billed again');
    }
}
