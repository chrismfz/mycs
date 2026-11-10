<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\ChangePackage;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Admin\Resources\ServiceResource\Pages\ListService;
use App\Console\Commands\ImportFromWhmcs;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\ApiKey;
use App\Models\Category;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Services\Service\AddAddon;
use App\Services\Service\DeleteService;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\FlakyServer\FlakyServer;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use Throwable;

/**
 * Staff's freedom on services (owner 2026-11-10, docs/staff-freedom.md): three refusals loosened, each with what staff
 * can do now, what still refuses, and what is billed.
 * 1. A main service is deleted with its running addons, once staff confirm (the API: with_addons=1).
 * 2. «Upgrade/Downgrade» stays in the menu, disabled with why and the way through, when it does not apply.
 * 3. «Add addon» on a service with a cancellation request; a pending one says why not, and what to do.
 */
class StaffFreedomServicesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $this->client = User::factory()->create()->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function parent(string $expires = '2026-12-01', string $status = 'active', array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create([
            'client_id' => $this->client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
            'domain' => 'vm104.example.test', ...$attributes,
        ]);
    }

    private function addon(Service $parent, string $status = 'active', ?int $stock = null, string $name = 'Extra IPv4'): Service
    {
        $product = $this->createProduct();
        $product->product->update(['name' => $name, 'is_addon' => true, 'stock' => $stock]);

        return Service::factory()->create([
            'client_id' => $parent->client_id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'parent_service_id' => $parent->id, 'status' => $status, 'expires_at' => $parent->expires_at, 'currency_code' => 'USD',
            'price' => 3.00, 'quantity' => 1,
        ]);
    }

    /** @return array{0: Product, 1: Plan} an addon product at 3.00 USD a month */
    private function addonProduct(string $unit = 'month'): array
    {
        $made = $this->createProduct();
        $made->product->update(['name' => 'Extra IPv4', 'is_addon' => true]);
        $made->plan->update(['billing_unit' => $unit]);
        Price::query()->where('plan_id', $made->plan->id)->update(['price' => 3.00]);

        return [$made->product->fresh(), $made->plan->fresh()];
    }

    private function openInvoiceFor(Service ...$services): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $services[0]->client_id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        foreach ($services as $service) {
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
                'quantity' => 1, 'price' => $service->price, 'period_start' => $service->expires_at, 'period_end' => $service->expires_at->copy()->addMonth()]);
        }

        return $invoice;
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'All' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function refused(callable $do): string
    {
        try {
            $do();
        } catch (DisplayException $e) {
            return $e->getMessage();
        }
        $this->fail('it was not refused');
    }

    // ---- 1. Delete a main service with its running addons ----

    public function test_confirmed_a_main_service_is_deleted_with_its_running_addons_and_nothing_is_left_payable(): void
    {
        $parent = $this->parent();
        $active = $this->addon($parent, stock: 2);
        $pending = $this->addon($parent, 'pending', name: 'Extra disk');
        $ended = $this->addon($parent, 'cancelled', name: 'Old backup');
        $renewal = $this->openInvoiceFor($parent, $active); // their renewal, one invoice
        $first = $this->openInvoiceFor($pending);           // the pending addon's own first invoice
        $paid = $this->openInvoiceFor($parent, $active);
        DB::table('invoices')->where('id', $paid->id)->update(['status' => Invoice::STATUS_PAID]);

        (new DeleteService)->handle($parent, withAddons: true);

        $this->assertSame([$ended->id], Service::query()->pluck('id')->all(), 'the service and its running addons are gone; the ended one stays');
        $this->assertNull($ended->fresh()->parent_service_id, 'on its own, as history');
        $this->assertSame(Invoice::STATUS_CANCELLED, $renewal->fresh()->status, 'no renewal left to pay for services that are gone');
        $this->assertSame(Invoice::STATUS_CANCELLED, $first->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $paid->fresh()->status, 'what was paid stays as it is');
        $this->assertSame(2, $paid->items()->count());
        $this->assertSame(0, Credit::query()->count(), 'nothing is credited or refunded by a delete');
        $this->assertSame(3, (int) $active->product->fresh()->stock, 'the addon\'s stock is back');
    }

    /**
     * Their common renewal (10.00 + 3.00) with 10.00 of the customer's credit on it. Deleted one by one, the addon's line
     * would go first and the credit would pay the 10.00 left: a renewal paid for a service deleted a moment later, the
     * credit spent. Cancelled as one: the invoice goes whole and the credit is back on the account.
     */
    public function test_credit_on_their_common_renewal_goes_back_and_pays_for_nothing(): void
    {
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        $parent = $this->parent();
        $addon = $this->addon($parent);
        $renewal = $this->openInvoiceFor($parent, $addon);
        (new CreditLedger)->add((int) $parent->client_id, 'USD', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'test');
        (new RecordPaymentService)->payWithCredit($renewal->id, upToCents: 1000);
        $this->assertSame(Invoice::STATUS_UNPAID, $renewal->fresh()->status, 'partly paid by credit');
        $this->assertSame('0.00', number_format((float) Credit::query()->where('client_id', $parent->client_id)->value('amount'), 2, '.', ''));

        (new DeleteService)->handle($parent, withAddons: true);

        $this->assertSame(0, Service::query()->count());
        $this->assertSame(Invoice::STATUS_CANCELLED, $renewal->fresh()->status, 'never paid: not for the service, not for the addon');
        $this->assertSame('10.00', number_format((float) Credit::query()->where('client_id', $parent->client_id)->value('amount'), 2, '.', ''),
            'all of the credit is back');
    }

    public function test_without_the_confirmation_it_is_refused_as_before_and_nothing_changes(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent);
        $renewal = $this->openInvoiceFor($parent, $addon);

        $this->assertSame(__('services.addons.delete_parent'), $this->refused(fn () => (new DeleteService)->handle($parent)));

        $this->assertNotNull($parent->fresh());
        $this->assertNotNull($addon->fresh());
        $this->assertSame(Invoice::STATUS_UNPAID, $renewal->fresh()->status);
    }

    public function test_an_addon_with_money_on_its_invoice_stops_the_whole_delete_and_is_named(): void
    {
        $parent = $this->parent();
        $free = $this->addon($parent, name: 'Extra disk');
        $withMoney = $this->addon($parent);
        $clean = $this->openInvoiceFor($free);
        $invoice = $this->openInvoiceFor($withMoney);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 1.00, null, 'pi_addon_part', currency: 'usd');

        $message = $this->refused(fn () => (new DeleteService)->handle($parent, withAddons: true));

        $this->assertStringContainsString("#{$withMoney->id} (Extra IPv4)", $message);
        $this->assertStringContainsString("#{$invoice->id}", $message, 'with the addon\'s own reason');
        $this->assertSame(3, Service::query()->count(), 'nothing was deleted: not the parent, not the addon that could go');
        $this->assertSame(Invoice::STATUS_UNPAID, $clean->fresh()->status, 'nor its invoice cancelled');
        $this->assertSame(Service::STATUS_ACTIVE, $parent->fresh()->status);
        $this->assertSame('1.00', number_format((float) $invoice->fresh()->transactions()->sum('amount'), 2, '.', ''), 'the money is where it was');
    }

    public function test_an_addon_with_an_open_task_stops_the_whole_delete_and_is_named(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent);
        ExtensionHelper::terminateServer($addon); // an addon's own termination: staff's task on its parent's machine
        $this->assertTrue(ModuleAction::openTaskKeeps($addon->id));

        $message = $this->refused(fn () => (new DeleteService)->handle($parent, withAddons: true));

        $this->assertSame(__('services.addons.delete_addon_refused', ['id' => $addon->id, 'addon' => 'Extra IPv4', 'reason' => __('services.delete_open_task')]), $message);
        $this->assertSame(2, Service::query()->count());
    }

    public function test_when_the_server_refuses_the_main_services_account_its_addons_are_kept_too(): void
    {
        require_once __DIR__ . '/../Fixtures/FalseServer.php';
        $parent = $this->parent();
        $server = Server::query()->create(['name' => 'Legacy', 'extension' => 'FalseServer', 'type' => 'server', 'enabled' => true]);
        $parent->product->update(['server_id' => $server->id]);
        $addon = $this->addon($parent);
        $renewal = $this->openInvoiceFor($parent, $addon);

        $thrown = null;
        try {
            (new DeleteService)->handle($parent->fresh(), terminate: true, withAddons: true);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'deleted although the server refused');
        $this->assertSame(Service::STATUS_ACTIVE, $addon->fresh()?->status, 'an addon is not deleted before its service\'s account is gone: it would never be billed again');
        $this->assertSame(Service::STATUS_ACTIVE, $parent->fresh()?->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $renewal->fresh()->status);
        $this->assertSame(0, ModuleAction::query()->where('service_id', $addon->id)->count(), 'and no task of its own: its service\'s machine covers it');
    }

    public function test_with_the_account_deleted_on_the_server_the_addons_go_with_no_task_of_their_own(): void
    {
        require_once __DIR__ . '/../Fixtures/FlakyServer.php';
        FlakyServer::$failures = 0;
        FlakyServer::$calls = [];
        $parent = $this->parent();
        $server = Server::query()->create(['name' => 'Flaky', 'extension' => 'FlakyServer', 'type' => 'server', 'enabled' => true]);
        $parent->product->update(['server_id' => $server->id]);
        $this->addon($parent);

        (new DeleteService)->handle($parent->fresh(), terminate: true, withAddons: true);

        $this->assertSame(['terminate'], FlakyServer::$calls, 'the main service\'s account, once: an addon asks no module');
        $this->assertSame(0, Service::query()->count());
        $this->assertSame(0, ModuleAction::query()->open()->count());
    }

    public function test_the_page_names_the_addons_before_staff_confirm_and_deletes_them_with_it(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent);
        $this->addon($parent, 'terminated', name: 'Old backup');

        $this->livewireAs($this->staff())->test(EditService::class, ['record' => $parent->getRouteKey()])
            ->mountAction('delete')
            ->assertMountedActionModalSee("#{$addon->id} Extra IPv4")
            ->assertMountedActionModalDontSee('Old backup')
            ->callMountedAction();

        $this->assertNull($parent->fresh());
        $this->assertNull($addon->fresh());
        $this->assertSame(1, Service::query()->count(), 'the ended one stays');
    }

    public function test_bulk_delete_follows_the_same_rule(): void
    {
        $kept = $this->parent();
        $keptAddon = $this->addon($kept);
        $this->livewireAs($this->staff())->test(ListService::class)
            ->callTableBulkAction('delete', [$kept], ['deleteExtensionServer' => false])
            ->assertNotified(__('Not deleted'));
        $this->assertNotNull($kept->fresh(), 'not ticked: a service with running addons is kept, as before');
        $this->assertNotNull($keptAddon->fresh());

        // Ticked, the addon selected too: the main service takes it, whatever the order of the rows.
        $this->livewireAs($this->staff())->test(ListService::class)
            ->callTableBulkAction('delete', [$keptAddon, $kept], ['deleteExtensionServer' => false, 'withAddons' => true]);
        $this->assertSame(0, Service::query()->count());
    }

    public function test_the_api_needs_its_flag(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent);
        $renewal = $this->openInvoiceFor($parent, $addon);
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 'test', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $token), 'user_id' => $this->staff()->id, 'permissions' => ['admin.services.delete']]);

        $this->withToken($token)->deleteJson(route('api.v1.admin.services.destroy', $parent))
            ->assertUnprocessable()->assertJsonPath('message', __('services.addons.delete_parent'));
        $this->assertSame(2, Service::query()->count());

        $this->withToken($token)->deleteJson(route('api.v1.admin.services.destroy', $parent) . '?with_addons=1')->assertNoContent();
        $this->assertSame(0, Service::query()->count());
        $this->assertSame(Invoice::STATUS_CANCELLED, $renewal->fresh()->status);
    }

    /**
     * An imported WHMCS service with its imported addon, through the importer's own steps: their paid invoice (lines
     * without a period, never marked applied) stays paid, their open renewal is cancelled, nothing is credited.
     */
    public function test_an_imported_service_is_deleted_with_its_imported_addon(): void
    {
        [$command, $whmcs] = $this->whmcs();
        $product = Product::factory()->create(['id' => 11, 'name' => 'Business 10',
            'category_id' => Category::create(['name' => 'Shared Hosting', 'slug' => 'shared-hosting'])->id]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'setup_fee' => 0, 'currency_code' => 'USD']);
        $whmcs->exec("INSERT INTO tblhosting VALUES (1692, {$this->client->id}, 'Active', '2026-12-15', '2026-12-15')");
        $parent = Service::factory()->create(['id' => 1692, 'client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-12-15',
            'domain' => 'site1692.example.test']);
        $whmcs->exec("INSERT INTO tblhostingaddons (id, hostingid, addonid, userid, name, recurring, billingcycle, tax, status, regdate, nextduedate, nextinvoicedate)
            VALUES (123, 1692, 26, {$this->client->id}, '', 3, 'Monthly', '1', 'Active', '2025-05-15', '2026-12-15', '2026-12-15')");
        // Paid: 2026-11-15 to 12-15, both on one invoice. Open: the next renewal, made early.
        $whmcs->exec("INSERT INTO tblinvoices VALUES (950, {$this->client->id}, '', '2026-11-01', '2026-11-15', '2026-11-10 09:00:00', 'Paid', 13, 0, 0)");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (6000, 950, {$this->client->id}, 'Hosting', 1692, 'Business 10', 10, 0, '2026-11-15')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (6001, 950, {$this->client->id}, 'Addon', 123, 'Dedicated IP', 3, 0, '2026-11-15')");
        $whmcs->exec("INSERT INTO tblinvoices VALUES (951, {$this->client->id}, '', '2026-11-18', '2026-12-15', NULL, 'Unpaid', 13, 0, 0)");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (6002, 951, {$this->client->id}, 'Hosting', 1692, 'Business 10', 10, 0, '2026-12-15')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (6003, 951, {$this->client->id}, 'Addon', 123, 'Dedicated IP', 3, 0, '2026-12-15')");
        foreach (['importAddons', 'importInvoices', 'importInvoiceItems'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
        $addon = Service::query()->where('whmcs_addon_id', 123)->sole();
        $this->assertSame(1692, (int) $addon->parent_service_id);
        $this->assertSame('2026-12-15', $addon->expires_at->toDateString());
        $lines = InvoiceItem::query()->where('invoice_id', 950)->get();
        $this->assertCount(2, $lines);
        $this->assertTrue($lines->every(fn (InvoiceItem $line) => $line->period_start === null && $line->applied_at === null),
            'as the importer leaves them: no period, never applied');
        $this->assertSame(Invoice::STATUS_PAID, Invoice::query()->findOrFail(950)->status);
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->findOrFail(951)->status);

        $this->assertSame(__('services.addons.delete_parent'), $this->refused(fn () => (new DeleteService)->handle($parent->fresh())));
        (new DeleteService)->handle($parent->fresh(), withAddons: true);

        $this->assertSame(0, Service::query()->count());
        $this->assertSame(Invoice::STATUS_PAID, Invoice::query()->findOrFail(950)->status, 'the paid invoice of WHMCS is history: untouched');
        $this->assertSame('13.00', number_format((float) InvoiceItem::query()->where('invoice_id', 950)->sum('price'), 2, '.', ''));
        $this->assertSame(Invoice::STATUS_CANCELLED, Invoice::query()->findOrFail(951)->status, 'the open renewal of both is not left to pay');
        $this->assertSame(0, Credit::query()->count());
    }

    /** @return array{0: ImportFromWhmcs, 1: PDO} the importer on an in-memory WHMCS with one addon product (26, 3.00 monthly) */
    private function whmcs(): array
    {
        $whmcs = new PDO('sqlite::memory:');
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
        $whmcs->exec("INSERT INTO tblclients (id, currency) VALUES ({$this->client->id}, 1)");
        $whmcs->exec("INSERT INTO tbladdons (id, packages, name, description, billingcycle, tax) VALUES (26, ',11,', 'Dedicated IP', '', 'recurring', 1)");
        $whmcs->exec("INSERT INTO tblpricing (type, currency, relid, monthly, quarterly, semiannually, annually, biennially, triennially) VALUES
            ('addon', 1, 26, 3, -1, -1, 36, -1, -1)");
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        return [$command, $whmcs];
    }

    // ---- 2. «Upgrade/Downgrade» stays in the menu, disabled with its reason ----

    public function test_upgrade_downgrade_is_shown_disabled_with_why_and_the_way_through(): void
    {
        $staff = $this->staff();
        $other = $this->createProduct()->product;
        $cases = [
            'pending' => [$this->parent(status: 'pending'), __('services.change_package.refused.not_running', ['status' => 'pending'])],
            'no due date' => [$this->parent(attributes: ['expires_at' => null]), __('services.change_package.refused.no_due_date')],
            'cancelling' => [$cancelling = $this->parent(), __('services.change_package.refused.cancelling')],
            'a change waits' => [$waiting = $this->parent(), __('services.change_package.refused.pending_change')],
            'cycle change set' => [$cycle = $this->parent(), __('services.upgrade_cycle_change_pending')],
        ];
        ServiceCancellation::query()->create(['service_id' => $cancelling->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);
        ServiceUpgrade::query()->create(['service_id' => $waiting->id, 'plan_id' => $other->plans()->first()->id, 'product_id' => $other->id,
            'status' => ServiceUpgrade::STATUS_PENDING]);
        $cycle->forceFill(['next_plan_id' => $other->plans()->first()->id])->save();
        $notStarted = $this->parent();
        $notStarted->forceFill(['plan_from' => '2026-12-01'])->save();
        $cases['cycle not started'] = [$notStarted, PlaceUpgrade::cycleNotStarted($notStarted->fresh()) . ' ' . __('services.change_package.refused.cycle_not_started_staff')];
        $this->assertStringContainsString('01/12/2026', $cases['cycle not started'][1]);

        foreach ($cases as $name => [$service, $why]) {
            $page = $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
                ->assertActionVisible('changePackage')
                ->assertActionDisabled('changePackage');
            $this->assertSame($why, (string) $page->instance()->getAction('changePackage')->getTooltip(), $name);
            $this->assertSame($why, ChangePackage::refusal($service->fresh()), $name);
            // Disabled is refused by the server too, not only greyed out: nothing is placed.
            try {
                $page->callAction('changePackage', ['product_id' => $other->id]);
            } catch (Throwable) {
                // the testing helper cannot call a disabled action: what counts is what was made
            }
        }

        $this->assertSame(1, ServiceUpgrade::query()->count(), 'only the one that was waiting already');
        $this->assertSame(0, Invoice::query()->count(), 'nothing billed');
        // Every reason names a way through for staff.
        foreach (['no_plan', 'not_running', 'pending_change', 'cancelling', 'order_unpaid', 'no_due_date'] as $key) {
            $this->assertMatchesRegularExpression('/form|«Package changes»/', __("services.change_package.refused.{$key}", locale: 'en'));
            $this->assertNotSame("services.change_package.refused.{$key}", __("services.change_package.refused.{$key}", locale: 'el'));
        }
    }

    public function test_upgrade_downgrade_when_it_applies_is_enabled_and_bills_the_difference_as_before(): void
    {
        $service = $this->parent('2026-12-01');
        $service->plan->update(['billing_unit' => 'month', 'billing_period' => 1]);
        $larger = $this->createProduct();
        $larger->plan->update(['billing_unit' => 'month', 'billing_period' => 1]);
        Price::query()->where('plan_id', $larger->plan->id)->update(['price' => 40.00]);
        $this->assertNull(ChangePackage::refusal($service->fresh()));

        $this->livewireAs($this->staff())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertActionEnabled('changePackage');
        $placed = PlaceUpgrade::handle($service->fresh(), $larger->product, [], ServiceUpgrade::SOURCE_STAFF);

        $this->assertSame(PlaceUpgrade::INVOICED, $placed->outcome);
        $line = $placed->invoice->items()->sole();
        // (40 − 10) × 11 days left of the 30 of 1 November to 1 December, on an invoice of its own, due now.
        $this->assertSame('11.00', number_format((float) $line->price, 2, '.', ''));
        $this->assertSame($service->product_id, (int) $service->fresh()->product_id, 'applied once it is paid, as before');
    }

    // ---- 3. «Add addon» on a service with a cancellation request ----

    public function test_staff_add_an_addon_to_a_service_that_is_to_be_cancelled_and_it_ends_with_it(): void
    {
        $parent = $this->parent('2026-12-01');
        $parent->plan->update(['billing_unit' => 'month', 'billing_period' => 1]);
        ServiceCancellation::query()->create(['service_id' => $parent->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);
        [$product] = $this->addonProduct();
        $this->assertNull(AddAddon::refusal($parent->fresh()));

        $this->livewireAs($this->staff())->test(EditService::class, ['record' => $parent->getRouteKey()])
            ->assertActionEnabled('addAddon')
            ->mountAction('addAddon')
            ->assertMountedActionModalSee(__('services.addons.add.cancelling_note', ['date' => '01/12/2026']))
            ->setActionData(['product_id' => $product->id, 'quantity' => 1, 'start' => AddAddon::PRORATED])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $addon = Service::query()->where('parent_service_id', $parent->id)->sole();
        $this->assertSame(Service::STATUS_PENDING, $addon->status);
        $invoice = $addon->invoices()->sole();
        $line = $invoice->items()->sole();
        // What is billed: 3.00 × 11 of the 30 days from 1 November, today to its service's due date, on its own invoice, now.
        $this->assertSame('1.10', number_format((float) $line->price, 2, '.', ''));
        $this->assertSame(['2026-11-20', '2026-12-01'], [$line->period_start->toDateString(), $line->period_end->toDateString()]);
        $this->assertSame('2026-11-20', $invoice->due_at->toDateString());
        $this->assertTrue((bool) $addon->cancellation?->with_parent, 'the request is its too: it ends with its service');

        (new RecordPaymentService)->handle($invoice->fresh(), null, $invoice->fresh()->remaining, transactionId: 'addon-cancelling');
        $addon->refresh();
        $this->assertSame(Service::STATUS_ACTIVE, $addon->status);
        $this->assertSame('2026-12-01', $addon->expires_at->toDateString());

        // Never renewed: neither it nor its service is billed for the period after the request.
        Carbon::setTestNow('2026-11-30 10:00:00');
        $this->assertSame(0, (new RenewalBilling)->billRenewal($addon->id, Carbon::parse('2026-12-01')));
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_added_with_no_invoice_to_one_that_is_to_be_cancelled_it_runs_to_the_same_day(): void
    {
        $parent = $this->parent('2026-12-01');
        $parent->plan->update(['billing_unit' => 'month', 'billing_period' => 1]);
        ServiceCancellation::query()->create(['service_id' => $parent->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);
        [$product] = $this->addonProduct();

        $made = (new AddAddon)->handle($parent, $product, 1, null, false, AddAddon::NO_INVOICE);

        $this->assertNull($made['invoice']);
        $this->assertSame(Service::STATUS_ACTIVE, $made['service']->fresh()->status);
        $this->assertSame('2026-12-01', $made['service']->fresh()->expires_at->toDateString());
        $this->assertTrue($made['service']->fresh()->cancellation()->exists());
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_what_add_addon_still_refuses(): void
    {
        $parent = $this->parent('2026-12-01');
        $parent->plan->update(['billing_unit' => 'month', 'billing_period' => 1]);
        [$product] = $this->addonProduct();
        [$yearly, $yearlyPlan] = $this->addonProduct('year');
        $addon = $this->addon($parent);
        $pending = $this->parent(status: 'pending', attributes: ['expires_at' => null]);
        $ended = $this->parent(status: 'terminated');

        // An addon has no addons of its own.
        $this->assertSame(__('services.addons.add.refused_addon'), $this->refused(fn () => (new AddAddon)->handle($addon, $product, 1, null, false, AddAddon::PRORATED)));
        // The addon product has no price for its service's cycle: staff add it in the catalogue (the rule of 2026-11-09).
        $this->assertSame(__('services.addons.add.no_cycle_plan', ['addon' => 'Extra IPv4', 'cycle' => $parent->plan->name]),
            $this->refused(fn () => (new AddAddon)->handle($parent, $yearly, 1, null, false, AddAddon::PRORATED)));
        $this->assertStringContainsString('catalogue', __('services.addons.add.no_cycle_plan', locale: 'en'));
        // One not started: no period to prorate to. Said with the way through, not hidden.
        $this->assertSame(__('services.addons.add.refused_pending'), $this->refused(fn () => (new AddAddon)->handle($pending, $product, 1, null, false, AddAddon::NO_INVOICE)));
        $this->assertSame(__('services.addons.add.refused_status'), $this->refused(fn () => (new AddAddon)->handle($ended, $product, 1, null, false, AddAddon::PRORATED)));
        $this->assertSame(1, Service::query()->whereNotNull('parent_service_id')->count(), 'only the one there was');
        $this->assertSame(0, Invoice::query()->count());

        $staff = $this->staff();
        $page = $this->livewireAs($staff)->test(EditService::class, ['record' => $pending->getRouteKey()])
            ->assertActionVisible('addAddon')->assertActionDisabled('addAddon');
        $this->assertSame(__('services.addons.add.refused_pending'), (string) $page->instance()->getAction('addAddon')->getTooltip());
        $this->livewireAs($staff)->test(EditService::class, ['record' => $addon->getRouteKey()])->assertActionHidden('addAddon');
        $this->livewireAs($staff)->test(EditService::class, ['record' => $ended->getRouteKey()])->assertActionHidden('addAddon');
    }
}
