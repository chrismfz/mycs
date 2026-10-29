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
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Adversarial review of #531 (AUD9-3). Pre-launch audit, flow 9 (the WHMCS importer, main @ 67ad3c09): after the import, does a payment unsuspend as WHMCS
 * does? Built on myip's copy of 2026-10-28 (anonymised here: no ids, names or domains of its own): 0 of its 123 suspended
 * or terminated services carry «Overdue on Payment»; WHMCS's own cron left suspendreason empty (10 of the 11 live
 * suspensions have an unpaid renewal invoice due 17–99 days before, as AutoSuspensionDays 15 does; AutoUnsuspend on).
 */
class Adv531ImportDunningTest extends TestCase
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

    private function renewalWith(int $id, int $service, string $due, string $status): void
    {
        $issued = Carbon::parse($due)->subDays(14)->toDateString();
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES ({$id}, {$this->clientId}, '', '{$issued}', '{$due}', '0000-00-00 00:00:00', '{$status}', 10, 0, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed) VALUES ({$id}, {$this->clientId}, 'Hosting', {$service},
            'Hosting (example.test)', 10, 0)");
    }

    /**
     * ADV-531-1 (Low): the importer maps WHMCS's «Collections» and «Payment Pending» invoices to unpaid
     * (invoiceStatus's default), but overdueServices() counts only status = 'Unpaid'. A service WHMCS's cron suspended
     * (reason empty) whose renewal staff then moved to Collections comes as a staff hold: its invoice is open and payable
     * here, yet paying it renews the service without unsuspending it, and the cron never terminates it.
     */
    #[DataProvider('unpaidStatuses')]
    public function test_a_suspension_for_an_overdue_invoice_in_collections_is_lifted_by_its_payment(string $whmcsStatus): void
    {
        $this->service(9101, '2026-10-11', 'Suspended');
        $this->renewalWith(9201, 9101, '2026-10-11', $whmcsStatus);
        $this->import();
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->findOrFail(9201)->status, 'open and payable here');

        ExtensionHelper::addPayment(9201, null, 10.00, null, 'tx-adv-531-1');

        $this->assertNotNull(Service::query()->findOrFail(9101)->dunning_suspended_at, 'a suspension for non-payment, as an Unpaid one is');
        Queue::assertPushed(UnsuspendJob::class, fn (UnsuspendJob $job) => (int) $job->service->id === 9101);
    }

    /** @return array<string, array{string}> */
    public static function unpaidStatuses(): array
    {
        return ['Collections' => ['Collections'], 'Payment Pending' => ['Payment Pending']];
    }
}
