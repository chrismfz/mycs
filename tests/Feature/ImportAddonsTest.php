<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Import\WhmcsAddonImport;
use App\Services\Import\WhmcsForecastParity;
use App\Services\Import\WhmcsNoteImport;
use App\Services\Import\WhmcsRecordParity;
use App\Services\Invoice\RecordPaymentService;
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
 * WHMCS's product addons imported as services fastened to their parents (docs/addons.md, step 4): products from the
 * catalog's addons and by name, WHMCS's price, cycle, due date, status, tax and notes, their invoice lines, and parity.
 */
class ImportAddonsTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private int $clientId;

    private Product $hosting;

    private int $item = 5000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 10:00:00');
        Queue::fake();
        $this->clientId = User::factory()->create()->client()->id;
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT, groupid INT DEFAULT 0)');
        $this->whmcs->exec('CREATE TABLE tbladdons (id INTEGER PRIMARY KEY, packages TEXT, name TEXT, description TEXT, billingcycle TEXT, allowqty INT DEFAULT 0,
            tax INT DEFAULT 1, hidden INT DEFAULT 0, retired INT DEFAULT 0, autoactivate TEXT DEFAULT \'\')');
        $this->whmcs->exec('CREATE TABLE tblpricing (id INTEGER PRIMARY KEY, type TEXT, currency INT, relid INT, msetupfee REAL DEFAULT 0, qsetupfee REAL DEFAULT 0,
            ssetupfee REAL DEFAULT 0, asetupfee REAL DEFAULT 0, bsetupfee REAL DEFAULT 0, tsetupfee REAL DEFAULT 0, monthly REAL, quarterly REAL,
            semiannually REAL, annually REAL, biennially REAL, triennially REAL)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT)');
        $this->whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, orderid INT DEFAULT 0, hostingid INT, addonid INT, userid INT, name TEXT,
            qty INT DEFAULT 1, recurring REAL, billingcycle TEXT, tax TEXT, status TEXT, regdate TEXT, nextduedate TEXT, nextinvoicedate TEXT,
            paymentmethod TEXT DEFAULT \'banktransfer\', notes TEXT DEFAULT \'\', subscriptionid TEXT DEFAULT \'\')');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $this->whmcs->exec('CREATE TABLE tblcancelrequests (id INTEGER PRIMARY KEY, date TEXT, relid INT, reason TEXT, type TEXT)');
        $this->whmcs->exec('INSERT INTO tblcurrencies VALUES (1, \'USD\'), (5, \'EUR\')');
        $this->whmcs->exec("INSERT INTO tblclients (id, currency) VALUES ({$this->clientId}, 1)");
        // The catalog's Dedicated IP (WHMCS 7+: a price per cycle, −1 off) and System Maintenance (one cycle, before 7: its
        // price in `monthly`, not offered in EUR).
        $this->whmcs->exec("INSERT INTO tbladdons (id, packages, name, description, billingcycle, tax) VALUES
            (26, ',11,9999,', 'Dedicated IP', 'An IPv4 of its own', 'recurring', 1), (30, '', 'System Maintenance', '', 'Annually', 1)");
        $this->whmcs->exec("INSERT INTO tblpricing (type, currency, relid, monthly, quarterly, semiannually, annually, biennially, triennially) VALUES
            ('addon', 1, 26, -1, -1, 18, 36, 72, -1), ('addon', 1, 30, 600, 0, 0, 0, 0, 0), ('addon', 5, 30, -1, -1, -1, -1, -1, -1), ('addon', 1, 3, 10, 0, 0, 0, 0, 0)");

        $this->hosting = Product::factory()->create(['id' => 11, 'name' => 'Business 10',
            'category_id' => Category::create(['name' => 'Shared Hosting', 'slug' => 'shared-hosting'])->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A yearly service at 100.00 with this (WHMCS) id, and WHMCS's row of it. */
    private function service(int $id, string $status = Service::STATUS_ACTIVE, string $expires = '2027-05-18'): Service
    {
        $plan = Plan::factory()->create(['priceable_id' => $this->hosting->id, 'priceable_type' => Product::class, 'name' => 'Annually',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 100, 'setup_fee' => 0, 'currency_code' => 'USD']);
        $this->whmcs->exec("INSERT INTO tblhosting VALUES ({$id}, {$this->clientId}, '" . ucfirst($status) . "')");

        return Service::factory()->create(['id' => $id, 'client_id' => $this->clientId, 'product_id' => $this->hosting->id, 'plan_id' => $plan->id,
            'status' => $status, 'currency_code' => 'USD', 'price' => 100, 'quantity' => 1, 'expires_at' => $expires, 'domain' => "site{$id}.example.test"]);
    }

    /** @param  array<string, mixed>  $fields */
    private function addon(int $id, int $parent, array $fields = []): void
    {
        $row = $fields + ['addonid' => 26, 'userid' => $this->clientId, 'name' => '', 'recurring' => 24, 'billingcycle' => 'Annually', 'tax' => '1',
            'status' => 'Active', 'regdate' => '2019-06-05', 'nextduedate' => '2027-05-18', 'nextinvoicedate' => '2027-05-18', 'notes' => ''];
        $statement = $this->whmcs->prepare('INSERT INTO tblhostingaddons (id, hostingid, ' . implode(', ', array_keys($row)) . ') VALUES (?, ?'
            . str_repeat(', ?', count($row)) . ')');
        $statement->execute([$id, $parent, ...array_values($row)]);
    }

    private function line(int $invoice, int $relid, float $amount, string $type = 'Addon'): int
    {
        $id = ++$this->item;
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES ({$id}, {$invoice}, {$this->clientId}, '{$type}', {$relid}, 'Line', {$amount}, 0)");

        return $id;
    }

    /** @param  list<string>  $steps */
    private function import(array $steps = ['importAddons']): string
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output = new BufferedOutput));
        foreach ($steps as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }

        return $output->fetch();
    }

    private function addonService(int $whmcsId): Service
    {
        return Service::query()->where('whmcs_addon_id', $whmcsId)->sole();
    }

    public function test_the_catalogs_addons_are_addon_products_with_their_prices_and_targets(): void
    {
        $this->import();

        $ip = Product::query()->where('whmcs_addon_id', 26)->sole();
        $this->assertTrue($ip->is_addon);
        $this->assertSame('Dedicated IP', $ip->name);
        $this->assertSame(['product:11'], $ip->addon_targets, 'only the products that are here');
        $this->assertFalse(Category::query()->findOrFail($ip->category_id)->show_in_menu, 'out of the shop\'s menu');
        $prices = $ip->plans()->with('prices')->get()->mapWithKeys(fn (Plan $plan) => ["{$plan->billing_period} {$plan->billing_unit}" => $plan->prices->pluck('price', 'currency_code')
            ->map(fn ($price) => number_format((float) $price, 2))->all()])->all();
        $this->assertSame(['6 month' => ['USD' => '18.00'], '1 year' => ['USD' => '36.00'], '2 year' => ['USD' => '72.00']], $prices, '−1 is off');

        $maintenance = Product::query()->where('whmcs_addon_id', 30)->sole();
        $this->assertNull($maintenance->addon_targets, 'for any service');
        $plan = $maintenance->plans()->with('prices')->sole();
        $this->assertSame(['recurring', 1, 'year'], [$plan->type, $plan->billing_period, $plan->billing_unit]);
        $this->assertSame(['USD' => '600.00'], $plan->prices->pluck('price', 'currency_code')->map(fn ($price) => number_format((float) $price, 2, '.', ''))->all(), 'not offered in EUR');
        $this->assertFalse(Product::query()->where('is_addon', true)->where('name', '')->exists(), 'a deleted addon\'s prices make no product');
    }

    public function test_a_live_addon_is_a_service_of_its_parent_with_whmcs_s_price_cycle_and_due_date(): void
    {
        $parent = $this->service(1648);
        $this->addon(104, 1648, ['name' => '84.54.49.64', 'notes' => 'Ordered by phone &amp; paid']);
        $this->addon(105, 1648, ['status' => 'Terminated']);

        $output = $this->import();

        $addon = $this->addonService(104);
        $this->assertNotSame(104, $addon->id, 'a new id: WHMCS\'s collide with its services\'');
        $this->assertSame([$parent->id, $this->clientId, 'USD', Service::STATUS_ACTIVE, '24.00', 1],
            [$addon->parent_service_id, $addon->client_id, $addon->currency_code, $addon->status, number_format((float) $addon->price, 2), (int) $addon->quantity]);
        $this->assertSame('2027-05-18', $addon->expires_at->toDateString());
        $this->assertSame(['recurring', 1, 'year'], [$addon->plan->type, $addon->plan->billing_period, $addon->plan->billing_unit]);
        $this->assertSame(Product::query()->where('whmcs_addon_id', 26)->value('id'), $addon->product_id);
        $this->assertFalse($addon->price_locked, 'priced in the catalog: as any service');
        $this->assertSame('84.54.49.64', $addon->label);
        $this->assertSame('Ordered by phone & paid', $addon->notes);
        $this->assertNull($addon->server_id);
        $this->assertSame('Dedicated IP (site1648.example.test)', $addon->lineName());
        $this->assertNull(Service::query()->where('whmcs_addon_id', 105)->first(), 'an ended one stays out');
        $this->assertStringContainsString('1 live addon(s) as services of their parents', $output);
    }

    public function test_one_made_by_hand_goes_by_its_name(): void
    {
        $this->service(1763);
        $this->addon(91, 1763, ['addonid' => 0, 'name' => 'Dedicated IP site1763.example.test']);
        $this->addon(162, 1763, ['addonid' => 0, 'name' => '20GB Extra Space', 'recurring' => 49.50, 'billingcycle' => 'Quarterly']);
        $this->addon(170, 1763, ['addonid' => 0, 'name' => '+10gb addon site1763.example.test', 'recurring' => 8.25, 'billingcycle' => 'Monthly']);
        $this->addon(175, 1763, ['addonid' => 3, 'name' => 'Priority support', 'recurring' => 5, 'billingcycle' => 'Monthly']);

        $this->import();

        $this->assertSame(Product::query()->where('whmcs_addon_id', 26)->value('id'), $this->addonService(91)->product_id, 'the catalog\'s Dedicated IP');
        $space = $this->addonService(162);
        $this->assertSame('Extra disk space', $space->product->name);
        $this->assertSame($space->product_id, $this->addonService(170)->product_id, 'one product for both');
        $this->assertTrue($space->product->is_addon);
        $this->assertSame(['49.50', 3, 'month'], [number_format((float) $space->price, 2), $space->plan->billing_period, $space->plan->billing_unit]);
        $this->assertTrue($space->price_locked, 'no catalog price: never «recalculated» to 0.00');
        $this->assertSame(0, $space->plan->prices()->count(), 'a plan never sold');
        $this->assertSame('Addon (WHMCS)', $this->addonService(175)->product->name, 'an addon WHMCS deleted, no group of its own');
        $this->assertSame('20GB Extra Space', $space->label);
    }

    public function test_one_billed_without_vat_stays_so_on_a_retired_product_of_the_same_name(): void
    {
        $this->service(1328);
        $this->addon(105, 1328, ['tax' => '0']);
        $this->addon(166, 1328, ['tax' => '1']);

        $this->import();

        $untaxed = $this->addonService(105)->product;
        $catalog = Product::query()->where('whmcs_addon_id', 26)->sole();
        $this->assertNotSame($catalog->id, $untaxed->id);
        $this->assertSame(['Dedicated IP', false, true, true], [$untaxed->name, $untaxed->taxable, $untaxed->retired, $untaxed->is_addon]);
        $this->assertSame($catalog->id, $this->addonService(166)->product_id, 'taxed as its catalog addon: on it');
        $this->assertFalse(Product::query()->listed()->whereKey($untaxed->id)->exists());
    }

    public function test_its_suspension_is_its_parents_whmcs_s_dunning_or_staff_s(): void
    {
        $this->service(1550, Service::STATUS_SUSPENDED);
        $this->service(1551);
        $this->addon(160, 1550, ['status' => 'Suspended']);
        $this->addon(161, 1550, ['status' => 'Active']);
        $this->addon(163, 1551, ['status' => 'Suspended', 'nextduedate' => '2026-10-17']);
        $this->addon(164, 1551, ['status' => 'Suspended', 'nextduedate' => '2026-12-17']);
        $this->addon(165, 9999, ['status' => 'Active']);
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (900, {$this->clientId}, '', '2026-09-27', '2026-10-17', NULL, 'Unpaid', 24, 0, 0)");
        $this->line(900, 163, 24);

        $output = $this->import();

        $this->assertSame([Service::STATUS_SUSPENDED, Service::SUSPEND_REASON_PARENT], [$this->addonService(160)->status, $this->addonService(160)->suspend_reason]);
        $this->assertSame([Service::STATUS_SUSPENDED, Service::SUSPEND_REASON_PARENT], [$this->addonService(161)->status, $this->addonService(161)->suspend_reason],
            'never active under a suspended parent');
        $dunning = $this->addonService(163);
        $this->assertNull($dunning->suspend_reason);
        $this->assertNotNull($dunning->dunning_suspended_at, 'unpaid past due: WHMCS\'s dunning, lifted by a payment');
        $byHand = $this->addonService(164);
        $this->assertSame([WhmcsAddonImport::SUSPENDED_IN_WHMCS, null], [$byHand->suspend_reason, $byHand->dunning_suspended_at]);
        $this->assertNull(Service::query()->where('whmcs_addon_id', 165)->first(), 'its service is not here');
        $this->assertStringContainsString('WHMCS addon #165', $output);
        $this->assertStringContainsString('active under a suspended service', $output);
    }

    public function test_its_invoice_lines_are_its_own_and_paying_its_renewal_renews_it_not_its_parent(): void
    {
        $parent = $this->service(1692, expires: '2027-05-18');
        $this->addon(123, 1692, ['nextduedate' => '2026-10-17', 'nextinvoicedate' => '2026-10-17']);
        $this->addon(124, 1692, ['status' => 'Terminated']);
        // Its history: last year's renewal paid, this year's unpaid.
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (899, {$this->clientId}, '', '2025-09-27', '2025-10-17', '2025-10-01', 'Paid', 24, 0, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (900, {$this->clientId}, '', '2026-09-27', '2026-10-17', NULL, 'Unpaid', 24, 0, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (901, {$this->clientId}, '', '2025-09-27', '2025-10-17', '2025-10-01', 'Paid', 24, 0, 0)");
        $this->line(899, 123, 24);
        $renewal = $this->line(900, 123, 24);
        $ended = $this->line(901, 124, 24);

        $this->import(['importAddons', 'importInvoices', 'importInvoiceItems', 'importPayments']);

        $addon = $this->addonService(123);
        $this->assertSame([Service::class, $addon->id, null], [InvoiceItem::query()->find($renewal)->reference_type, (int) InvoiceItem::query()->find($renewal)->reference_id,
            InvoiceItem::query()->find($renewal)->kind], 'its renewal, as a service\'s Hosting line');
        $this->assertSame([Service::class, $parent->id, InvoiceItem::KIND_CHARGE], [InvoiceItem::query()->find($ended)->reference_type,
            (int) InvoiceItem::query()->find($ended)->reference_id, InvoiceItem::query()->find($ended)->kind], 'an ended one\'s: its parent\'s charge, as before');

        (new RecordPaymentService)->handle(Invoice::query()->findOrFail(900), null, 24.0, transactionId: 'wire-900');

        $this->assertSame('2027-10-17', $addon->fresh()->expires_at->toDateString());
        $this->assertSame('2027-05-18', $parent->fresh()->expires_at->toDateString());
    }

    public function test_its_notes_are_on_its_own_service_not_on_its_parents(): void
    {
        $parent = $this->service(1413);
        $this->addon(106, 1413, ['notes' => 'IP 10.0.0.6']);
        $this->addon(107, 1413, ['status' => 'Terminated', 'notes' => 'Was 10.0.0.7']);
        $this->import();

        (new WhmcsNoteImport($this->whmcs))->run();

        $this->assertSame('IP 10.0.0.6', $this->addonService(106)->notes);
        $this->assertStringNotContainsString('10.0.0.6', (string) $parent->fresh()->notes);
        $this->assertStringContainsString('Was 10.0.0.7', (string) $parent->fresh()->notes, 'an ended one\'s, on its parent as before');
    }

    public function test_parity_compares_each_live_addon_with_its_service(): void
    {
        $this->service(1648);
        $this->service(1550, Service::STATUS_SUSPENDED);
        $this->addon(104, 1648);
        $this->addon(161, 1550);
        $this->addon(165, 9999);
        $this->import();

        $records = new WhmcsRecordParity($this->whmcs);
        (new ReflectionMethod($records, 'addons'))->invoke($records);
        $checks = (new ReflectionProperty($records, 'checks'))->getValue($records);
        $this->assertSame(2, count($checks['Addons']['records']));
        $this->assertSame([], $checks['Addons']['examples'], 'suspended with its parent is no difference');
        $this->assertStringContainsString('#165', implode("\n", $records->notes()));

        // WHMCS's automation off here (no tblconfiguration), and so here: the days compared are «never» on both sides.
        config(['settings.cronjob_suspend_enabled' => false, 'settings.cronjob_terminate_enabled' => false, 'settings.cronjob_invoice' => 0]);
        $forecast = new WhmcsForecastParity($this->whmcs);
        (new ReflectionMethod($forecast, 'addons'))->invoke($forecast);
        $differences = (new ReflectionProperty($forecast, 'differences'))->getValue($forecast);
        $summary = (new ReflectionProperty($forecast, 'summary'))->getValue($forecast);
        $this->assertSame([], $differences);
        $this->assertSame(2, $summary['addon · renewal amount']['total']);

        // An addon priced otherwise here is a difference.
        $this->addonService(104)->forceFill(['price' => 30])->saveQuietly();
        $records = new WhmcsRecordParity($this->whmcs);
        (new ReflectionMethod($records, 'addons'))->invoke($records);
        $this->assertStringContainsString('addon #104: 24.00 in WHMCS, 30.00 here', implode("\n", (new ReflectionProperty($records, 'checks'))->getValue($records)['Addons']['examples']));
    }

    /** Code review 1 of #647: a parent's end-of-period request reaches its addons, which are never billed again. */
    public function test_a_parents_end_of_period_cancellation_is_its_addons_too(): void
    {
        $this->service(1692, expires: '2026-11-20');
        $this->service(1693, expires: '2026-11-20');
        $this->addon(123, 1692, ['nextduedate' => '2026-11-10']);
        $this->addon(124, 1693, ['nextduedate' => '2026-11-10']);
        $this->whmcs->exec("INSERT INTO tblcancelrequests VALUES (1, '2026-10-30', 1692, 'Moving away', 'End of Billing Period'), (2, '2026-10-30', 1693, 'Done', 'Immediate')");

        $this->import(['importAddons', 'importCancellations']);

        $copy = ServiceCancellation::query()->where('service_id', $this->addonService(123)->id)->sole();
        $this->assertSame(['end_of_period', true, 'Moving away'], [$copy->type, (bool) $copy->with_parent, $copy->reason]);
        $this->assertFalse(ServiceCancellation::query()->where('service_id', $this->addonService(124)->id)->exists(),
            'an immediate one ends the parent, and the addons with it: no copy');
        $this->assertSame(0, (new RenewalBilling)->billRenewal($this->addonService(123)->id, Carbon::parse('2026-11-10')));
    }

    /** Code review 2 of #647: an addon never takes the id of a service WHMCS deleted, whose lines still name it. */
    public function test_an_addon_never_takes_the_id_of_a_service_whmcs_deleted(): void
    {
        $this->service(1648);
        $this->addon(104, 1648);
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (900, {$this->clientId}, '', '2026-09-27', '2026-12-17', NULL, 'Unpaid', 10, 0, 0)");
        $deleted = $this->line(900, 1649, 10, 'Hosting');

        $this->import(['importAddons', 'importInvoices', 'importInvoiceItems']);

        $this->assertGreaterThan(1649, $this->addonService(104)->id);
        $this->assertNotSame($this->addonService(104)->id, (int) InvoiceItem::query()->findOrFail($deleted)->reference_id);
    }

    /** Code review 3 of #647: a live addon of an ended service ends with it here, never renewed on its own. */
    public function test_a_live_addon_of_an_ended_service_ends_with_it(): void
    {
        $this->service(1700, Service::STATUS_TERMINATED);
        $this->addon(180, 1700);

        $output = $this->import();

        $this->assertSame(Service::STATUS_TERMINATED, $this->addonService(180)->status);
        $this->assertStringContainsString('imported terminated with it', $output);
        $records = new WhmcsRecordParity($this->whmcs);
        (new ReflectionMethod($records, 'addons'))->invoke($records);
        $this->assertSame([], (new ReflectionProperty($records, 'checks'))->getValue($records)['Addons']['examples']);
    }

    /** Code review 6 of #647: parity checks the tax an addon is billed with, and a negative amount is told to staff. */
    public function test_parity_checks_its_tax_and_a_negative_amount_is_told(): void
    {
        $this->service(1328);
        $this->addon(105, 1328, ['tax' => '0']);
        $this->addon(106, 1328, ['recurring' => -5]);

        $output = $this->import();

        $this->assertStringContainsString('WHMCS addon #106: its recurring amount -5', $output);
        $catalog = Product::query()->where('whmcs_addon_id', 26)->sole();
        $this->addonService(105)->forceFill(['product_id' => $catalog->id])->saveQuietly();
        $records = new WhmcsRecordParity($this->whmcs);
        (new ReflectionMethod($records, 'addons'))->invoke($records);
        $this->assertContains('addon #105: not taxed in WHMCS, taxed here', (new ReflectionProperty($records, 'checks'))->getValue($records)['Addons']['examples']);
    }
}
