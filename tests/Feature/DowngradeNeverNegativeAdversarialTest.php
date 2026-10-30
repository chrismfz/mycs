<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Console\Commands\ImportFromWhmcs;
use App\Livewire\Services\Upgrade;
use App\Models\ClientGroup;
use App\Models\ConfigOption;
use App\Models\Coupon;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Adversarial review of #551 (fix/downgrade-never-negative @ da6b83d1): a customer's options-only change prices the
 * service at `services.price + Δoptions`, or, when that is negative, at the catalog with the options it keeps.
 *
 * `test_bug_*` fail on the branch as reviewed (a finding, ADV-551-N); `test_holds_*` are attacks that held.
 *
 * Catalog (setUp): Hosting, yearly, 20.00 USD; option Backup: None 0.00, Weekly 10.00, Daily 30.00 (USD and EUR). Today is
 * 2026-07-02; the period paid runs 2026-01-01 → 2027-01-01 (183 of 365 days left).
 */
class DowngradeNeverNegativeAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const LEFT = 183 / 365;

    private User $user;

    private Product $product;

    private Plan $plan;

    private ConfigOption $backup;

    /** @var array<string, int> value name => id */
    private array $values = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-02 10:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_on_downgrade' => [true, 'boolean'],
            'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']]);
        $this->user = User::factory()->create();
        $this->user->client()->primaryContact->update(['country' => 'GR']);
        $this->product = Product::factory()->create(['name' => 'Hosting', 'server_id' => null, 'stock' => null]);
        $this->plan = Plan::factory()->create(['priceable_id' => $this->product->id, 'priceable_type' => Product::class, 'name' => 'Yearly',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $this->plan->id, 'price' => 20.00, 'setup_fee' => 0, 'currency_code' => 'USD']);
        $this->backup = ConfigOption::create(['name' => 'Backup', 'type' => 'select', 'sort' => 0, 'upgradable' => true, 'hidden' => false]);
        $this->backup->products()->attach($this->product->id);
        foreach ([['None', 0], ['Weekly', 10], ['Daily', 30]] as $sort => [$name, $price]) {
            $value = ConfigOption::create(['name' => $name, 'sort' => $sort, 'parent_id' => $this->backup->id]);
            $valuePlan = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => $name,
                'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
            foreach (['USD', 'EUR'] as $currency) {
                Price::factory()->create(['plan_id' => $valuePlan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => $currency]);
            }
            $this->values[$name] = $value->id;
        }
        $this->backup->load('children');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $values): void
    {
        foreach ($values as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    /** A service at $price per unit with backups $value, its year 2026 paid ($price × quantity). */
    private function service(float $price, string $value = 'Daily', array $extra = [], bool $paid = true): Service
    {
        $service = Service::factory()->create($extra + ['client_id' => $this->user->client()->id, 'product_id' => $this->product->id,
            'plan_id' => $this->plan->id, 'price' => $price, 'quantity' => 1, 'currency_code' => 'USD',
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2027-01-01']);
        $service->configs()->create(['config_option_id' => $this->backup->id, 'config_value_id' => $this->values[$value]]);
        if ($paid) {
            $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID,
                'currency_code' => $service->currency_code]);
            $invoice->items()->create(['description' => 'Period', 'price' => $price, 'quantity' => (int) $service->quantity,
                'reference_id' => $service->id, 'reference_type' => Service::class, 'period_start' => '2026-01-01', 'period_end' => '2027-01-01']);
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        }

        return $service->fresh();
    }

    /** The customer sets the backups to $value on the Upgrade page. */
    private function choose(Service $service, string $value): Service
    {
        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()])
            ->set("configOptions.{$this->backup->id}", $this->values[$value])->call('doUpgrade');

        return $service->fresh();
    }

    /** Pays the upgrade's invoice as a payment would, and applies it. */
    private function payPending(Service $service): float
    {
        $upgrade = ServiceUpgrade::query()->where('service_id', $service->id)->where('status', ServiceUpgrade::STATUS_PENDING)->sole();
        $amount = (float) InvoiceItem::query()->where('invoice_id', $upgrade->invoice_id)->sum('price');
        DB::table('invoices')->where('id', $upgrade->invoice_id)->update(['status' => Invoice::STATUS_PAID]);
        $this->assertTrue((new ServiceUpgradeService)->handle($upgrade));

        return $amount;
    }

    private function price(Service $service): string
    {
        return number_format((float) $service->fresh()->price, 2, '.', '');
    }

    private function credit(): float
    {
        return round((float) Credit::query()->where('client_id', $this->user->client()->id)->sum('amount'), 2);
    }

    // ------------------------------------------------------------------------------------------------ findings

    /**
     * ADV-551-1 (High). The fallback prices the service at its plan's catalog price in the service's currency; a plan with
     * no price row in it (priced in the default currency only: a WHMCS import, a currency staff added later) reads as
     * 0.00 (Plan::price returns a null price). The kept path then failed (25 − 30 < 0) and the fallback made a paying
     * EUR service free for good, with the unused 25 € of the year credited. The same class as F1 of the SVC-14 review,
     * now reached through an options-only change (targetPlan() keeps the service's own plan whatever its currencies).
     */
    public function test_bug_the_fallback_reads_a_plan_without_a_price_in_the_services_currency_as_free(): void
    {
        $service = $this->service(25.00, 'Daily', ['currency_code' => 'EUR']);

        $service = $this->choose($service, 'None');

        $this->assertNotSame('0.00', $this->price($service), 'a paying service is not made free because its plan has no EUR row');
        $this->assertLessThan(round(25 * self::LEFT, 2) - 0.05, $this->credit(), 'nor credited the whole of what is left of its year');
    }

    /**
     * ADV-551-2 (Medium). The rule is on the sign: 30.00 with Daily backups (under the catalog's 50) less the option's 30
     * is exactly 0.00 and is kept, so the service renews free from then on (RenewalBilling: a price ≤ 0 renews at once),
     * while 29.99 falls back to the catalog's 20.00. A customer whose price equals the sum of options it can drop (with
     * several values, any subset of their prices) reaches it from the Upgrade page.
     */
    public function test_bug_an_options_only_change_that_lands_exactly_at_zero_makes_a_paying_service_free(): void
    {
        $atZero = $this->choose($this->service(30.00), 'None');
        $justUnder = $this->choose($this->service(29.99), 'None');

        $this->assertSame('20.00', $this->price($justUnder), 'one cent less falls back to the catalog');
        $this->assertNotSame('0.00', $this->price($atZero), 'a paying hosting service made free by dropping its backups');
    }

    /**
     * ADV-551-3 (Low). A negative recurring amount still comes in through the WHMCS import: tblhosting.amount is copied
     * as is (no floor, no notice), and only the customer's options-only change is guarded. Such a service renews free
     * (price ≤ 0) and every other money path then reads a negative services.price.
     */
    public function test_bug_the_whmcs_import_brings_a_negative_recurring_amount_in(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $pdo->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $pdo->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, regdate TEXT, domain TEXT, qty INT, amount REAL,
            billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT, dedicatedip TEXT,
            assignedips TEXT, overideautosuspend INT, overidesuspenduntil TEXT)');
        $pdo->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $clientId = $this->user->client()->id;
        $pdo->exec("INSERT INTO tblclients VALUES ({$clientId}, 1)");
        $pdo->exec("INSERT INTO tblhosting VALUES (9551, {$clientId}, NULL, {$this->product->id}, '2024-01-01', 'example.test', 1, -5.00, 'Annually',
            '2027-01-01', 'Active', '', '', '', '', '', '', 0, '0000-00-00')");
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $pdo);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        (new ReflectionMethod($command, 'importServices'))->invoke($command);

        $this->assertGreaterThanOrEqual(0, (float) Service::query()->findOrFail(9551)->price, 'a recurring amount below zero is floored or refused');
    }

    // ------------------------------------------------------------------------------------------------ held

    /**
     * Down into the fallback and back up, over and over in the same period: the credits less the upgrades paid never pass
     * what the first downgrade was worth ((25 − 20) for the days left). DowngradeCredit's ceiling holds.
     */
    public function test_holds_toggling_through_the_fallback_farms_no_credit(): void
    {
        $service = $this->service(25.00);
        $paidUpgrades = 0.0;
        for ($round = 0; $round < 3; $round++) {
            $service = $this->choose($service, 'None');
            $this->assertSame('20.00', $this->price($service));
            $service = $this->choose($service, 'Daily');
            $this->assertSame('20.00', $this->price($service), 'not applied before it is paid');
            $paidUpgrades += $this->payPending($service);
            $this->assertSame('50.00', $this->price($service));
        }
        $service = $this->choose($service, 'None');

        $this->assertSame('20.00', $this->price($service));
        $this->assertLessThanOrEqual(round(5 * self::LEFT, 2) + 0.03, round($this->credit() - $paidUpgrades, 2), 'no money made by toggling');
    }

    /**
     * Daily → Weekly (kept: 25 − 20 = 5, credit 20 for the days left) → None (5 − 10 < 0: the catalog's 20, billed 15 for
     * the days left): once paid, the same 20.00 and the same net credit (5 for the days left) as one step to None.
     */
    public function test_holds_two_steps_down_end_where_one_does(): void
    {
        $service = $this->choose($this->service(25.00), 'Weekly');
        $this->assertSame('5.00', $this->price($service));
        $service = $this->choose($service, 'None');
        $this->assertSame('5.00', $this->price($service), 'up to the catalog: an invoice first');
        $paid = $this->payPending($service);

        $this->assertSame('20.00', $this->price($service));
        $this->assertEqualsWithDelta(round(5 * self::LEFT, 2), round($this->credit() - $paid, 2), 0.03);
    }

    /** Quantity 2: the fallback is per unit (20.00) and the credit is twice (25 − 20) for the days left. */
    public function test_holds_quantity_two_falls_back_per_unit(): void
    {
        $service = $this->choose($this->service(25.00, 'Daily', ['quantity' => 2]), 'None');

        $this->assertSame('20.00', $this->price($service));
        $this->assertEqualsWithDelta(round(10 * self::LEFT, 2), $this->credit(), 0.02);
    }

    /** A 50% group: the kept path halves the option (10 − 15 < 0), the fallback is the catalog's 20 at 50%: 10, no credit. */
    public function test_holds_a_groups_member_falls_back_to_its_group_price(): void
    {
        $group = ClientGroup::query()->create(['name' => 'Half', 'discount_percent' => 50]);
        $this->user->client()->forceFill(['client_group_id' => $group->id])->save();
        $service = $this->service(10.00, 'Daily', ['group_percent' => 50]);

        $service = $this->choose($service, 'None');

        $this->assertSame('10.00', $this->price($service));
        $this->assertSame(0.0, $this->credit());
    }

    /** A recurring coupon (50%, every cycle) is the fallback's as a plan change's: 20 at 50% = 10, credit 15 for the days left. */
    public function test_holds_a_recurring_coupon_is_kept_by_the_fallback(): void
    {
        $coupon = Coupon::create(['code' => 'HALF', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 0]);
        $service = $this->service(25.00, 'Daily', ['coupon_id' => $coupon->id]);

        $service = $this->choose($service, 'None');

        $this->assertSame('10.00', $this->price($service));
        $this->assertGreaterThanOrEqual(0, (float) $service->price);
    }

    /** Tax inclusive (24%): the catalog 24.80 / 37.20 are 20 / 30 net; 25 net less 30 falls back to 20.00 net. */
    public function test_holds_tax_inclusive_falls_back_to_the_net_catalog(): void
    {
        $this->settings(['tax_enabled' => [true, 'boolean'], 'tax_type' => ['inclusive', 'string']]);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
        Price::query()->where('plan_id', $this->plan->id)->update(['price' => 24.80]);
        Price::query()->whereHas('plan', fn ($q) => $q->where('priceable_type', ConfigOption::class)->where('priceable_id', $this->values['Daily']))
            ->update(['price' => 37.20]);

        $service = $this->choose($this->service(25.00), 'None');

        $this->assertSame('20.00', $this->price($service));
    }

    /**
     * A deep discount (5) less the option is billed up to the catalog's 20; the cron reprices that pending line as days
     * pass: lower, never below zero, and never a credit.
     */
    public function test_holds_the_cron_reprices_a_pending_fallback_line_down_never_below_zero(): void
    {
        $service = $this->choose($this->service(5.00), 'None');
        $upgrade = ServiceUpgrade::query()->where('service_id', $service->id)->sole();
        $line = InvoiceItem::query()->where('reference_type', ServiceUpgrade::class)->where('reference_id', $upgrade->id)->sole();
        $first = (float) $line->price;
        $this->assertEqualsWithDelta(15 * self::LEFT, $first, 0.02);

        Carbon::setTestNow('2026-12-30 10:00:00');
        $cron = app(CronJob::class);
        DB::transaction(fn () => (new ReflectionMethod($cron, 'refreshUpgrade'))->invoke($cron, $upgrade->id));

        $now = (float) $line->fresh()->price;
        $this->assertLessThan($first, $now);
        $this->assertGreaterThanOrEqual(0, $now);
        $this->assertSame('5.00', $this->price($service), 'not applied before it is paid');
        $this->assertSame(0.0, $this->credit());
    }

    /** A hidden option staff set (not offered on the page) stays, and the fallback prices it at the catalog, as a plan change. */
    public function test_holds_a_hidden_option_staff_set_is_kept_and_priced_by_the_fallback(): void
    {
        $ip = ConfigOption::create(['name' => 'Extra IP', 'type' => 'select', 'sort' => 1, 'upgradable' => false, 'hidden' => true]);
        $ip->products()->attach($this->product->id);
        $one = ConfigOption::create(['name' => 'One', 'sort' => 0, 'parent_id' => $ip->id]);
        $ipPlan = Plan::factory()->create(['priceable_id' => $one->id, 'priceable_type' => ConfigOption::class, 'name' => 'One',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $ipPlan->id, 'price' => 4, 'setup_fee' => 0, 'currency_code' => 'USD']);
        $service = $this->service(25.00);
        $service->configs()->create(['config_option_id' => $ip->id, 'config_value_id' => $one->id]);

        $service = $this->choose($service, 'None');

        $this->assertSame('24.00', $this->price($service), 'the catalog 20 + the IP 4');
        $this->assertTrue($service->configs()->where('config_option_id', $ip->id)->exists());
    }

    /** An option value with no price in the service's currency counts as 0 on both sides: no negative, no fallback. */
    public function test_holds_an_option_priced_only_in_another_currency_never_goes_negative(): void
    {
        Price::query()->where('currency_code', 'EUR')->whereHas('plan', fn ($q) => $q->where('priceable_type', ConfigOption::class)
            ->where('priceable_id', $this->values['Daily']))->delete();
        Price::factory()->create(['plan_id' => $this->plan->id, 'price' => 20.00, 'setup_fee' => 0, 'currency_code' => 'EUR']);

        $service = $this->choose($this->service(25.00, 'Daily', ['currency_code' => 'EUR']), 'None');

        $this->assertSame('25.00', $this->price($service));
    }
}
