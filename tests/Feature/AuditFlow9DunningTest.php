<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Providers\SettingsProvider;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Pre-launch audit, flow 9 (the WHMCS importer, main @ 67ad3c09): after the import, does a payment unsuspend as WHMCS
 * does? Built on myip's copy of 2026-10-28 (anonymised here: no ids, names or domains of its own): 0 of its 123 suspended
 * or terminated services carry «Overdue on Payment»; WHMCS's own cron left suspendreason empty (10 of the 11 live
 * suspensions have an unpaid renewal invoice due 17–99 days before, as AutoSuspensionDays 15 does; AutoUnsuspend on).
 */
class AuditFlow9DunningTest extends TestCase
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

    private function service(int $id, string $due, string $status, string $reason = ''): void
    {
        $this->whmcs->exec("INSERT INTO tblhosting VALUES ({$id}, {$this->clientId}, NULL, {$this->productId}, '2024-01-01', 'example.test', 1, 10, 'Monthly',
            '{$due}', '{$status}', '', '', '', '{$reason}', '', '', 0, '0000-00-00')");
    }

    /** A renewal invoice of the service for the period from $due, issued 14 days before it (CreateInvoiceDaysBefore). */
    private function renewal(int $id, int $service, string $due, string $status): void
    {
        $issued = Carbon::parse($due)->subDays(14)->toDateString();
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES ({$id}, {$this->clientId}, '', '{$issued}', '{$due}', '0000-00-00 00:00:00', '{$status}', 10, 0, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed) VALUES ({$id}, {$this->clientId}, 'Hosting', {$service},
            'Hosting (example.test)', 10, 0)");
    }

    private function import(): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        foreach (['importAutomationSettings', 'importServices', 'importInvoices', 'importInvoiceItems'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
        SettingsProvider::reload(); // the cron and the payment run in their own processes, after handle()'s flushCache
    }

    /**
     * AUD9-3 (High, cut-over): a service WHMCS's own cron suspended (suspendreason empty, as on myip) is imported as a
     * staff hold (dunning_suspended_at null). The customer pays the overdue invoice after the cut-over: the service is
     * renewed but stays suspended (RenewServiceService::activate, $manualHold), where WHMCS (AutoUnsuspend on) unsuspends
     * it. Nor does the cron ever terminate it (WHMCS: at 120 days).
     */
    public function test_a_service_whmcs_suspended_for_non_payment_is_unsuspended_when_its_invoice_is_paid(): void
    {
        // As myip's: due 2026-10-11, suspended by WHMCS's cron after 15 days, reason left empty, its renewal unpaid.
        $this->service(9101, '2026-10-11', 'Suspended');
        $this->renewal(9200, 9101, '2026-09-11', 'Paid');
        $this->renewal(9201, 9101, '2026-10-11', 'Unpaid');
        $this->import();

        ExtensionHelper::addPayment(9201, null, 10.00, null, 'tx-aud9-3');

        $this->assertSame(Invoice::STATUS_PAID, Invoice::query()->findOrFail(9201)->status);
        $this->assertSame('2026-11-11', Service::query()->findOrFail(9101)->expires_at->toDateString(), 'renewed by the payment');
        Queue::assertPushed(UnsuspendJob::class, fn (UnsuspendJob $job) => (int) $job->service->id === 9101);
    }
}
