<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Providers\SettingsProvider;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Sweep 4, lens c-import (adversarial verifier): failing tests for what the importer leaves wrong.
 */
class Sweep4CImportTest extends TestCase
{
    use RefreshDatabase;

    private const AUTOMATION = ['AutoSuspension' => 'on', 'AutoSuspensionDays' => '15', 'AutoTermination' => 'on', 'AutoTerminationDays' => '120',
        'AutoUnsuspend' => 'on', 'CreateInvoiceDaysBefore' => '14', 'DailyCronExecutionHour' => '10'];

    private int $clientId;

    private int $productId;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-10-29 10:00:00');
        $this->clientId = User::factory()->create()->client()->id;
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->productId = $product->product->id;

        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblconfiguration (setting TEXT PRIMARY KEY, value TEXT)');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, regdate TEXT, domain TEXT, qty INT, amount REAL,
            billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT, dedicatedip TEXT,
            assignedips TEXT, overideautosuspend INT, overidesuspenduntil TEXT)');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $this->whmcs->exec('CREATE TABLE tblcancelrequests (id INTEGER PRIMARY KEY, date TEXT, relid INT, reason TEXT, type TEXT)');
        $insert = $this->whmcs->prepare('INSERT INTO tblconfiguration VALUES (?, ?)');
        foreach (self::AUTOMATION as $setting => $value) {
            $insert->execute([$setting, $value]);
        }
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$this->clientId}, 1)");
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(int $id, string $cycle, float $amount, string $due, int $qty = 1): void
    {
        $this->whmcs->exec("INSERT INTO tblhosting VALUES ({$id}, {$this->clientId}, NULL, {$this->productId}, '2024-01-01', 'svc-{$id}.test', {$qty}, {$amount}, '{$cycle}',
            '{$due}', 'Active', '', '', '', '', '', '', 0, '0000-00-00')");
    }

    /** @param  list<string>  $steps */
    private function import(array $steps): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        // The importer runs with foreign_key_checks=0 (handle()). On SQLite, inside the test's transaction, foreign_keys
        // cannot change: the checks are deferred to a commit that never comes (RefreshDatabase rolls back).
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        } else {
            DB::statement('SET foreign_key_checks=0');
        }
        foreach ($steps as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('SET foreign_key_checks=1');
        }
        SettingsProvider::reload();
    }

    /**
     * C-IMPORT-1: WHMCS deleted service #5000 (its highest id), whose cancellation request and Hosting line remain. No addons,
     * so nothing moves the services' counter past it: the next service made here is #5000 and inherits both.
     */
    public function test_c_import_1_a_new_service_does_not_inherit_a_deleted_services_cancellation_and_lines(): void
    {
        $this->service(4990, 'Monthly', 10.00, '2026-12-01');
        $this->whmcs->exec("INSERT INTO tblcancelrequests VALUES (1, '2025-05-01 10:00:00', 5000, 'too expensive', 'End of Billing Period')");
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (700, {$this->clientId}, '', '2025-04-20', '2025-05-01', '0000-00-00 00:00:00', 'Unpaid', 10, 0, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed) VALUES (700, {$this->clientId}, 'Hosting', 5000,
            'Hosting (deleted.test)', 10, 0)");

        $this->import(['importAutomationSettings', 'importServices', 'importCancellations', 'importInvoices', 'importInvoiceItems']);

        // Ten orders after the cut-over: the tenth takes #5000.
        $new = null;
        for ($i = 0; $i < 10; $i++) {
            $new = Service::factory()->create(['client_id' => $this->clientId, 'product_id' => $this->productId, 'status' => Service::STATUS_ACTIVE,
                'price' => 10, 'quantity' => 1]);
        }

        $this->assertSame(0, Service::query()->where('id', '>', 4990)->has('cancellation')->count(),
            "a new service (#{$new->id}) carries an old customer's cancellation request");
        $this->assertSame(0, Service::query()->where('id', '>', 4990)->has('renewalInvoices')->count(),
            "a new service (#{$new->id}) carries an old customer's Hosting lines");
    }

    /** C-IMPORT-1, the counter: the next service made here is past every id WHMCS still names, not the highest kept + 1. */
    public function test_c_import_1_the_services_counter_moves_past_ids_whmcs_deleted(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('MariaDB: ALTER TABLE commits the test\'s transaction, so the importer moves the counter only outside one.');
        }
        $this->service(4990, 'Monthly', 10.00, '2026-12-01');
        $this->whmcs->exec("INSERT INTO tblcancelrequests VALUES (1, '2025-05-01 10:00:00', 5000, 'too expensive', 'End of Billing Period')");

        $this->import(['importAutomationSettings', 'importServices']);

        $new = Service::factory()->create(['client_id' => $this->clientId, 'product_id' => $this->productId, 'price' => 10, 'quantity' => 1]);
        $this->assertSame(5001, $new->id);
    }

    /** C-IMPORT-1, what stays: an imported service keeps its own request and Hosting line; a deleted one's line has no type. */
    public function test_c_import_1_an_imported_services_request_and_lines_are_kept(): void
    {
        $this->service(4990, 'Monthly', 10.00, '2026-12-01');
        $this->whmcs->exec("INSERT INTO tblcancelrequests VALUES (1, '2025-05-01 10:00:00', 4990, 'moving', 'End of Billing Period')");
        $this->whmcs->exec("INSERT INTO tblcancelrequests VALUES (2, '2025-05-01 10:00:00', 5000, 'too expensive', 'Immediate')");
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (700, {$this->clientId}, '', '2025-04-20', '2025-05-01', '0000-00-00 00:00:00', 'Unpaid', 20, 0, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed) VALUES (700, {$this->clientId}, 'Hosting', 4990,
            'Hosting (svc-4990.test)', 10, 0), (700, {$this->clientId}, 'Hosting', 5000, 'Hosting (deleted.test)', 10, 0)");

        $this->import(['importAutomationSettings', 'importServices', 'importCancellations', 'importInvoices', 'importInvoiceItems']);

        $this->assertSame([4990], DB::table('service_cancellations')->pluck('service_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(1, Service::query()->findOrFail(4990)->renewalInvoices()->count());
        $deleted = DB::table('invoice_items')->where('invoice_id', 700)->where('reference_id', 5000)->first();
        $this->assertNotNull($deleted, 'the line is imported, its amount kept');
        $this->assertNull($deleted->reference_type);
        $this->assertSame('20.00', number_format((float) DB::table('invoice_items')->where('invoice_id', 700)->sum('line_total'), 2, '.', ''),
            'the invoice still holds both lines');
    }

    /**
     * C-IMPORT-2: a WHMCS service of 3 units at amount 30.00 (WHMCS's recurring amount for all of them, billed as one line
     * «3 x ...») is renewed here at 3 x 30.00.
     */
    public function test_c_import_2_a_scaled_service_renews_at_whmcs_amount_not_amount_times_qty(): void
    {
        $this->markTestIncomplete('Left (sweep IMPORT-1): no live qty>1 services or addons at either company (2026-11-06), and the cut-over'
            . ' runbook checks COUNT = 0 before the final dump. Pricing is not changed until WHMCS\'s Scaling amount is checked.');
        // @phpstan-ignore deadCode.unreachable
        $this->service(801, 'Monthly', 30.00, '2026-11-05', qty: 3);
        $this->import(['importAutomationSettings', 'importServices', 'importInvoices', 'importInvoiceItems']);

        $this->artisan('app:cron-job');

        $invoice = Invoice::query()->latest('id')->firstOrFail();
        $this->assertEquals(30.00, (float) $invoice->items()->where('reference_id', 801)->sum('line_total'),
            'WHMCS billed 30.00 a month for the three units; the renewal here bills amount x qty');
    }
}
