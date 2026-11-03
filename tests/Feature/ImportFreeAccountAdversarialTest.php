<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Livewire\Services\Upgrade;
use App\Models\ClientGroup;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Providers\SettingsProvider;
use App\Services\Billing\ClientGroupPricing;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Adversarial review of #594: a WHMCS «Free Account» of a product with no Free plan goes on the product's first plan
 * (a recurring one) at 0.00. Before, with no plan, the cron never dunned it (BIL-6) and the customer could not upgrade
 * it; WHMCS never suspends or invoices a «Free Account». On a recurring plan both change.
 */
#[Group('adversarial')]
class ImportFreeAccountAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const AUTOMATION = ['AutoSuspension' => 'on', 'AutoSuspensionDays' => '15', 'AutoTermination' => 'on', 'AutoTerminationDays' => '120',
        'AutoUnsuspend' => 'on', 'CreateInvoiceDaysBefore' => '14', 'DailyCronExecutionHour' => '10'];

    private User $user;

    private int $clientId;

    private int $productId;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-10-29 10:00:00');
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $this->user = User::factory()->create();
        $this->clientId = $this->user->client()->id;
        $product = $this->createProduct(['server_id' => null, 'stock' => null]);
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

    private function service(int $id, string $cycle, float $amount, string $due = '0000-00-00'): void
    {
        $this->whmcs->exec("INSERT INTO tblhosting VALUES ({$id}, {$this->clientId}, NULL, {$this->productId}, '2024-01-01', 'lic-{$id}', 1, {$amount}, '{$cycle}',
            '{$due}', 'Active', '', '', '', '', '', '', 0, '0000-00-00')");
    }

    private function import(): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        foreach (['importAutomationSettings', 'importServices', 'importInvoices', 'importInvoiceItems'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
        SettingsProvider::reload();
    }

    /**
     * ADV-594-1 (high): a «Free Account» that kept a next due date in WHMCS (a paid service staff turned free: WHMCS
     * leaves the date, and never suspends a «Free Account»). Here it is on a recurring plan at 0.00: the cron renews it
     * one period a night (no invoice), but the date is still past Suspend Days, so the same run suspends it «Overdue on
     * Payment»; suspended, it is never renewed again, and Termination Days later it is terminated. A free account
     * suspended and then terminated for a debt it cannot have. Before #594 (no plan) the cron left it alone (BIL-6).
     */
    public function test_adv_594_1_a_free_account_with_a_past_due_date_is_never_suspended(): void
    {
        $this->service(903, 'Free Account', 0, '2025-06-01');
        $this->import();
        $service = Service::query()->findOrFail(903);

        $this->artisan('app:cron-job');

        $service->refresh();
        $this->assertSame(0, Invoice::query()->count(), 'never invoiced');
        $this->assertSame(Service::STATUS_ACTIVE, $service->status, 'a «Free Account» suspended as unpaid (WHMCS never suspends one)');
        $this->assertNull($service->dunning_suspended_at);
    }

    /**
     * ADV-594-2 (medium): on a plan the «Free Account» is upgradable now (Service::upgradable needs a plan; before #594
     * it had none). With no due date (WHMCS's 0000-00-00), the upgrade charges the whole month's difference once
     * (ServiceUpgrade::calculatePrice: no period to prorate) and the service then holds the dearer product at its
     * catalog price, but with no expiry the cron never bills it (invoices_created needs expires_at): one month paid, the
     * bigger product for ever.
     */
    public function test_adv_594_2_an_upgraded_free_account_is_billed_for_the_dearer_product_after_its_first_month(): void
    {
        $big = Product::factory()->create(['name' => 'Big', 'server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $big->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => '30.00', 'setup_fee' => '0.00', 'currency_code' => 'USD']);
        DB::table('product_upgrades')->insert(['product_id' => $this->productId, 'upgrade_id' => $big->id]);

        $this->service(904, 'Free Account', 0);
        $this->import();
        $service = Service::query()->findOrFail(904);
        $this->assertNull($service->expires_at, 'WHMCS left no due date');

        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()])
            ->set('upgrade', $big->id)
            ->call('doUpgrade');
        $upgrade = ServiceUpgrade::query()->where('service_id', 904)->latest('id')->first();
        if ($upgrade?->invoice !== null) {
            $due = (float) $upgrade->invoice->items()->sum('line_total');
            (new RecordPaymentService)->handle($upgrade->invoice, null, $due, transactionId: 'adv594-up');
        }

        // Six weeks on, past what the upgrade paid for: the cron runs.
        Carbon::setTestNow('2026-12-10 10:00:00');
        $this->artisan('app:cron-job');

        $service->refresh();
        $renewals = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', 904)->count();
        $this->assertTrue((int) $service->product_id === $this->productId || $renewals > 0,
            "on the 30.00 product since the upgrade ({$service->price} a month), with no expiry ({$service->expires_at}): never billed again");
    }

    /**
     * ADV-594-3 (low): an account in a 100 % group (in-house, a partner given everything) holding a «Free Account». The
     * cron's group reconcile stamps the 0.00 with group_percent 100; once the account leaves the group, the 0.00 equals
     * «the catalog at 100 % off», so ClientGroupPricing takes it for a computed price and sets the catalog's 10.00 on the
     * free service. Before #594 (no plan) it stayed 0.00 (that branch needs a plan).
     */
    public function test_adv_594_3_a_free_account_stays_free_when_its_account_leaves_a_whole_discount_group(): void
    {
        $this->service(905, 'Free Account', 0);
        $this->import();
        $group = ClientGroup::query()->create(['name' => 'In-house', 'discount_percent' => '100.00']);
        $this->user->client()->update(['client_group_id' => $group->id]);
        ClientGroupPricing::reconcile();
        $this->assertEquals(0, (float) Service::query()->findOrFail(905)->price);

        $this->user->client()->update(['client_group_id' => null]);
        ClientGroupPricing::reconcile();

        $this->assertEquals(0, (float) Service::query()->findOrFail(905)->price, 'the free account took the catalog price');
    }
}
