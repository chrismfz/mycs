<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Category;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Reports\ProductIncome;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
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
 * WHMCS invoice line types in the importer (docs/importer.md «Τύποι γραμμών»): a service's setup fee, promotion, addon
 * and upgrade are its charges (`kind = charge`), so income by product counts them for its product, while the billing
 * never takes them for a renewal period.
 */
class ImportInvoiceLineTypesTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private int $clientId;

    private Product $product;

    private int $item = 5000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        $this->clientId = User::factory()->create()->client()->id;
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $this->whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INT, addonid INT, name TEXT)');
        $this->whmcs->exec('CREATE TABLE tblupgrades (id INTEGER PRIMARY KEY, relid INT, type TEXT)');
        $this->whmcs->exec('INSERT INTO tblcurrencies VALUES (1, \'USD\')');
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$this->clientId}, 1)");

        $this->product = Product::factory()->create(['name' => 'Business 10',
            'category_id' => Category::create(['name' => 'Shared Hosting', 'slug' => 'shared-hosting'])->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A monthly service at 10.00 with this (WHMCS) id, paid until 2026-10-25. */
    private function service(int $id, string $expires = '2026-10-25'): Service
    {
        $plan = Plan::factory()->create(['priceable_id' => $this->product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return Service::factory()->create(['id' => $id, 'client_id' => $this->clientId, 'product_id' => $this->product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => $expires]);
    }

    private function invoice(int $id, string $status, float $total): void
    {
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES ({$id}, {$this->clientId}, '', '2026-09-01', '2026-10-25', '2026-09-03', '{$status}', {$total}, 0, 0)");
    }

    private function item(int $invoice, string $type, int $relid, float $amount, string $description = 'Line'): int
    {
        $id = ++$this->item;
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES ({$id}, {$invoice}, {$this->clientId}, '{$type}', {$relid}, '{$description}', {$amount}, 0)");

        return $id;
    }

    private function import(): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        foreach (['importInvoices', 'importInvoiceItems', 'importPayments'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
    }

    /** @return array{0: string|null, 1: int|null, 2: string|null} */
    private function reference(int $line): array
    {
        $item = InvoiceItem::query()->findOrFail($line);

        return [$item->reference_type, $item->reference_id === null ? null : (int) $item->reference_id, $item->kind];
    }

    public function test_each_line_type_keeps_what_it_is_for(): void
    {
        $this->service(101);
        $this->service(102);
        $this->whmcs->exec("INSERT INTO tblhostingaddons VALUES (31, 102, 4, 'Dedicated IP'), (32, 999, 4, 'Dedicated IP')");
        $this->whmcs->exec("INSERT INTO tblupgrades VALUES (41, 101, 'package'), (42, 102, 'configoptions'), (43, 101, 'addon')");
        $this->invoice(700, 'Paid', 100);
        $lines = [
            'hosting' => $this->item(700, 'Hosting', 101, 10),
            'setup' => $this->item(700, 'Setup', 101, 5),
            'promo' => $this->item(700, 'PromoHosting', 101, -2),
            'addon' => $this->item(700, 'Addon', 31, 3),
            'package upgrade' => $this->item(700, 'Upgrade', 41, 4),
            'options upgrade' => $this->item(700, 'Upgrade', 42, 1),
            'domain' => $this->item(700, 'DomainRegister', 9, 12),
            'promo domain' => $this->item(700, 'PromoDomain', 9, -1),
            'add funds' => $this->item(700, 'AddFunds', 0, 50),
            'late fee' => $this->item(700, 'LateFee', 700, 2),
            'by hand' => $this->item(700, '', 0, 7),
            // Nothing to attach it to: no type, never a guess.
            'setup of a deleted service' => $this->item(700, 'Setup', 555, 5),
            'addon of a deleted service' => $this->item(700, 'Addon', 32, 3),
            'addon not in WHMCS any more' => $this->item(700, 'Addon', 33, 3),
            'upgrade of an addon' => $this->item(700, 'Upgrade', 43, 3),
        ];

        $this->import();

        $charge = InvoiceItem::KIND_CHARGE;
        $this->assertSame([
            'hosting' => [Service::class, 101, null],
            'setup' => [Service::class, 101, $charge],
            'promo' => [Service::class, 101, $charge],
            'addon' => [Service::class, 102, $charge],
            'package upgrade' => [Service::class, 101, $charge],
            'options upgrade' => [Service::class, 102, $charge],
            'domain' => [null, 9, null],
            'promo domain' => [null, 9, null],
            'add funds' => [Credit::class, null, null],
            'late fee' => [null, 700, null],
            'by hand' => [null, 0, null],
            'setup of a deleted service' => [null, 555, null],
            'addon of a deleted service' => [null, 32, null],
            'addon not in WHMCS any more' => [null, 33, null],
            'upgrade of an addon' => [null, 43, null],
        ], array_map(fn (int $line) => $this->reference($line), $lines));
    }

    public function test_a_whmcs_without_addons_or_upgrades_tables_imports_them_untyped(): void
    {
        $this->service(101);
        $this->whmcs->exec('DROP TABLE tblhostingaddons');
        $this->whmcs->exec('DROP TABLE tblupgrades');
        $this->invoice(700, 'Paid', 10);
        $addon = $this->item(700, 'Addon', 31, 3);
        $upgrade = $this->item(700, 'Upgrade', 41, 4);
        $setup = $this->item(700, 'Setup', 101, 5);

        $this->import();

        $this->assertSame([[null, 31, null], [null, 41, null], [Service::class, 101, InvoiceItem::KIND_CHARGE]],
            [$this->reference($addon), $this->reference($upgrade), $this->reference($setup)]);
    }

    public function test_a_discount_setup_fee_and_upgrade_count_for_the_services_product(): void
    {
        $this->service(101);
        $this->whmcs->exec("INSERT INTO tblupgrades VALUES (41, 101, 'package')");
        $this->invoice(700, 'Paid', 21);
        $this->item(700, 'Hosting', 101, 10);
        $this->item(700, 'Setup', 101, 5);
        $this->item(700, 'PromoHosting', 101, -3);
        $this->item(700, 'Upgrade', 41, 4);
        $this->item(700, 'Item', 0, 5, 'Something by hand');
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES (7001, {$this->clientId}, 700, 'banktransfer', '2026-09-03 10:00:00', 21, 0, 0, 'wire')");

        $this->import();
        $report = ProductIncome::year('USD', 2026);

        $groups = collect($report['groups'])->keyBy('name');
        $this->assertSame(1600, $groups['Shared Hosting']['items'][0]['received'], '10 + 5 − 3 + 4: the product, by its discount too');
        $this->assertSame(500, $groups[__('Other (no service)')]['received'], 'only the line by hand');
        $this->assertSame(2100, $report['total']);
    }

    public function test_paying_an_imported_invoice_renews_one_period_whatever_its_charges(): void
    {
        $service = $this->service(101);
        $this->invoice(700, 'Unpaid', 12);
        $hosting = $this->item(700, 'Hosting', 101, 10, 'Business 10 (25/10/2026 - 24/11/2026)');
        $setup = $this->item(700, 'Setup', 101, 5);
        $promo = $this->item(700, 'PromoHosting', 101, -3);
        $this->import();
        $invoice = Invoice::query()->findOrFail(700);

        (new RecordPaymentService)->handle($invoice, null, 12.0, transactionId: 'wire-700');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('2026-11-25', $service->fresh()->expires_at->toDateString(), 'one month: the charges renew nothing');
        $this->assertSame(['2026-10-25', '2026-11-25'], [InvoiceItem::query()->findOrFail($hosting)->period_start?->toDateString(),
            InvoiceItem::query()->findOrFail($hosting)->period_end?->toDateString()]);
        foreach ([$setup, $promo] as $line) {
            $this->assertNull(InvoiceItem::query()->findOrFail($line)->period_start, 'a charge never takes a period');
        }
    }

    public function test_cancelling_an_imported_invoice_gives_up_one_period_not_one_per_charge(): void
    {
        // Overdue since September: two periods due, so a charge taken for a renewal would give up the second.
        $service = $this->service(101, '2026-09-25');
        $this->invoice(700, 'Unpaid', 12);
        $hosting = $this->item(700, 'Hosting', 101, 10);
        $setup = $this->item(700, 'Setup', 101, 5);
        $promo = $this->item(700, 'PromoHosting', 101, -3);
        $this->import();

        Invoice::query()->findOrFail(700)->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->assertSame(['2026-09-25', '2026-10-25'], [InvoiceItem::query()->findOrFail($hosting)->period_start?->toDateString(),
            InvoiceItem::query()->findOrFail($hosting)->period_end?->toDateString()], 'the renewal gives up its period');
        foreach ([$setup, $promo] as $line) {
            $item = InvoiceItem::query()->findOrFail($line);
            $this->assertSame([null, null], [$item->period_start, $item->forgone_at], 'a charge gives up no period');
        }
        $this->assertSame(1, $service->invoiceItems()->whereNotNull('forgone_at')->count());
    }

    public function test_an_upgrade_reprices_the_renewal_and_leaves_the_charges_alone(): void
    {
        $service = $this->service(101);
        $this->invoice(700, 'Unpaid', 12);
        $hosting = $this->item(700, 'Hosting', 101, 10);
        $setup = $this->item(700, 'Setup', 101, 5);
        $promo = $this->item(700, 'PromoHosting', 101, -3);
        $this->import();
        $bigger = Product::factory()->create(['name' => 'Business 20']);
        $plan = Plan::factory()->create(['priceable_id' => $bigger->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 20, 'setup_fee' => 0, 'currency_code' => 'USD']);
        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'plan_id' => $plan->id, 'product_id' => $bigger->id,
            'status' => ServiceUpgrade::STATUS_PENDING, 'invoice_id' => null]);

        (new ServiceUpgradeService)->handle($upgrade);

        $this->assertSame(['20.00', '5.00', '-3.00'], array_map(fn (int $line) => (string) InvoiceItem::query()->findOrFail($line)->price,
            [$hosting, $setup, $promo]), 'the renewal at the new price; a setup fee and a discount keep theirs');
    }
}
