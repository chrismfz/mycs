<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Product;
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
 * A WHMCS «Free Account» of a product sold on its prices (nixpal's licenses staff gave free: parity of 2026-11-03 found 4
 * with no plan here): on the product's first plan at 0.00, never a Free plan made for it, which customers could order
 * free (owner 2026-11-03). It renews free, never invoiced, as in WHMCS. A product with its own Free plan keeps it.
 */
class ImportFreeAccountTest extends TestCase
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

    private function service(int $id, string $cycle, float $amount, string $due = '0000-00-00', ?int $product = null): void
    {
        $product ??= $this->productId;
        $this->whmcs->exec("INSERT INTO tblhosting VALUES ({$id}, {$this->clientId}, NULL, {$product}, '2024-01-01', 'lic-{$id}', 1, {$amount}, '{$cycle}',
            '{$due}', 'Active', '', '', '', '', '', '', 0, '0000-00-00')");
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

    public function test_a_free_account_of_a_sold_product_goes_on_its_first_plan_at_zero(): void
    {
        $this->service(901, 'Free Account', 5.00); // an amount WHMCS left on it, never invoiced
        $this->import();

        $service = Service::query()->findOrFail(901);
        $this->assertSame((int) Plan::query()->where('priceable_id', $this->productId)->where('name', 'Monthly')->value('id'), (int) $service->plan_id);
        $this->assertEquals(0, (float) $service->price);
        $this->assertSame(0, Plan::query()->where('priceable_id', $this->productId)->where('type', 'free')->count(), 'no Free plan a customer could order');

        $this->artisan('app:cron-job');
        $this->assertSame(0, Invoice::query()->count(), 'never invoiced');
    }

    public function test_a_free_account_keeps_its_products_own_free_plan(): void
    {
        $free = Product::query()->findOrFail($this->productId)->plans()->create(['name' => 'Free', 'type' => 'free']);
        $this->service(902, 'Free Account', 0);
        $this->import();

        $this->assertSame($free->id, (int) Service::query()->findOrFail(902)->plan_id);
    }
}
