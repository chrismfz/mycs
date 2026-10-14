<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Livewire\Services\Upgrade;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Adversarial review of SVC-14 (branch fix/upgrade-proration): the upgrade charge is
 * (new recurring net price − services.price) × quantity × days left / days of the real period.
 * Tests named `test_finding_*` failed on the branch as reviewed (a finding); the others are probes that held. F1–F5
 * are fixed in the same PR (F4: the rest is billed on an invoice of its own, so its assertion counts every upgrade line).
 * F6: decided 2026-10-14, each side with its own tax (docs/decisions.md §7).
 */
class UpgradeProrationAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-02 10:00:00');
        Queue::fake();
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_on_downgrade' => [true, 'boolean'],
            'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']]);
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

    private function tax(string $type = 'exclusive'): void
    {
        $this->settings(['tax_enabled' => [true, 'boolean'], 'tax_type' => [$type, 'string']]);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->client()->primaryContact->update(['country' => 'GR']);

        return $user;
    }

    /** @param array<string, float> $prices currency => price */
    private function product(array $prices, string $unit = 'year', ?int $period = 1, string $type = 'recurring', bool $taxable = true): Product
    {
        $product = Product::factory()->create(['name' => 'P' . implode('/', $prices), 'taxable' => $taxable]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Plan',
            'billing_unit' => $type === 'recurring' ? $unit : null, 'billing_period' => $type === 'recurring' ? $period : null, 'type' => $type]);
        foreach ($prices as $currency => $price) {
            Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => $currency]);
        }

        return $product->fresh();
    }

    private function service(User $user, Product $product, float $price, array $extra = []): Service
    {
        return Service::factory()->create($extra + ['client_id' => $user->client()->id, 'product_id' => $product->id,
            'plan_id' => $product->plans()->first()->id, 'price' => $price, 'quantity' => 1, 'currency_code' => 'USD',
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2027-01-01']);
    }

    private function link(Product $from, Product $to): void
    {
        DB::table('product_upgrades')->insert(['product_id' => $from->id, 'upgrade_id' => $to->id]);
    }

    private function option(string $name, array $values, int $productId, string $unit = 'year'): ConfigOption
    {
        $option = ConfigOption::create(['name' => $name, 'type' => 'select', 'sort' => 0, 'upgradable' => true, 'hidden' => false]);
        $option->products()->attach($productId);
        foreach ($values as $sort => [$valueName, $price]) {
            $value = ConfigOption::create(['name' => $valueName, 'sort' => $sort, 'parent_id' => $option->id]);
            $plan = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => $valueName,
                'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
            Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);
        }

        return $option->load('children');
    }

    private function upgradeTo(User $user, Service $service, Product $to): void
    {
        $this->livewireAs($user)->test(Upgrade::class, ['service' => $service])->set('upgrade', $to->id)->call('doUpgrade');
    }

    private function charged(): float
    {
        return (float) ServiceUpgrade::query()->sole()->invoice->items()->sole()->price;
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->sum('amount')), 2, '.', '');
    }

    private function paidInvoiceFor(Service $service, float $amount): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'description' => 'Renewal', 'price' => $amount, 'quantity' => 1,
            'reference_type' => Service::class, 'reference_id' => $service->id]);
        // As the WHMCS importer writes a paid invoice (the observer only lets a recorded payment mark one paid).
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
    }

    private function runCronRefresh(int $upgradeId): void
    {
        $cron = app(CronJob::class);
        $method = new ReflectionMethod($cron, 'refreshUpgrade');
        DB::transaction(fn () => $method->invoke($cron, $upgradeId));
    }

    // ------------------------------------------------------------------------------------------------ findings

    /**
     * F1 (high). The new product's plan has no price in the service's currency (it is priced in the default currency
     * only). It is still offered (productUpgrades and availablePlans() look at the session/default currency, not the
     * service's), its price in the service's currency reads as 0.00, so an UPGRADE to a dearer product is priced as a
     * downgrade to zero: applied at once, the recurring amount becomes 0.00, and the customer is credited the unused
     * part of what they paid.
     */
    public function test_finding_an_upgrade_to_a_plan_without_a_price_in_the_service_currency_is_not_free(): void
    {
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        $user = $this->user();
        $from = $this->product(['EUR' => 62.00, 'USD' => 70.00]);
        $to = $this->product(['USD' => 300.00]);
        $this->link($from, $to);
        $service = $this->service($user, $from, 62.00, ['currency_code' => 'EUR']);

        $this->upgradeTo($user, $service, $to);

        $this->assertSame(0, (int) Credit::query()->count(), 'no credit for moving to a dearer product');
        $this->assertNotSame('0.00', number_format((float) $service->fresh()->price, 2, '.', ''),
            'the recurring amount must not drop to 0.00');
    }

    /**
     * F2 (medium). The service pays more than the current catalog (an imported WHMCS price, or the catalog went down).
     * The customer changes one upgradable option to a value that costs 1.00 more: the "upgrade" re-prices the whole
     * service at the catalog, so it becomes a downgrade with account credit and a lower recurring amount. An option
     * that adds 1.00 a year gives the customer (63 − 100) × 183 / 365 × 1.24 = 23.00 credit.
     */
    public function test_finding_adding_a_dearer_option_never_credits_the_customer(): void
    {
        $this->tax();
        $user = $this->user();
        $product = $this->product(['USD' => 62.00]);
        $disk = $this->option('Disk', [['10 GB', 0], ['20 GB', 1]], $product->id);
        $service = $this->service($user, $product, 100.00);
        $service->configs()->create(['config_option_id' => $disk->id, 'config_value_id' => $disk->children[0]->id]);

        $this->livewireAs($user)->test(Upgrade::class, ['service' => $service->fresh()])
            ->set("configOptions.{$disk->id}", $disk->children[1]->id)->call('doUpgrade');

        $this->assertSame('0.00', $this->credit(), 'an option that costs more is not a refund');
        $this->assertGreaterThanOrEqual(100.00, (float) $service->fresh()->price, 'an upgrade does not lower the recurring amount');
    }

    /**
     * F3 (medium). The service is in its last discounted cycle (a 50% coupon for 2 cycles; 2 paid invoices, so the
     * current period is cycle 2 at 31.00, and the next renewal is undiscounted). An upgrade from 62 to 64 is priced as
     * (64 − 31) × 183/365 = 16.55: the customer pays back the discount of the period they are in, which they were
     * owed. The current period's difference is (32 − 31) × 183/365 = 0.50.
     */
    public function test_finding_a_coupon_that_ends_at_the_next_renewal_is_not_charged_back_for_the_current_period(): void
    {
        $user = $this->user();
        $from = $this->product(['USD' => 62.00]);
        $to = $this->product(['USD' => 64.00]);
        $this->link($from, $to);
        $coupon = Coupon::create(['code' => 'HALF2', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 2]);
        $service = $this->service($user, $from, 31.00, ['coupon_id' => $coupon->id]);
        $this->paidInvoiceFor($service, 31.00);
        $this->paidInvoiceFor($service, 31.00);

        $this->upgradeTo($user, $service, $to);

        $this->assertEqualsWithDelta(0.50, $this->charged(), 0.01, 'the period being upgraded keeps its discount');
    }

    /**
     * F4 (medium). A renewal paid after the upgrade invoice was made, but before the upgrade invoice is paid (the renewal
     * at the OLD price moves expires_at a year on). Paying the upgrade invoice applies it at the price it was made
     * for (183 days), so the whole next year runs on the new product at the old price: 110.00 never charged.
     * Nothing at payment time (ServiceUpgradeService::handle) re-checks the charge; only the cron re-prices it, and
     * only while the invoice is unpaid.
     */
    public function test_finding_a_renewal_paid_between_order_and_payment_is_charged_the_difference(): void
    {
        $user = $this->user();
        $from = $this->product(['USD' => 62.00]);
        $to = $this->product(['USD' => 172.00]);
        $this->link($from, $to);
        $service = $this->service($user, $from, 62.00);
        $this->upgradeTo($user, $service, $to);
        $this->assertSame(55.15, $this->charged());

        // The renewal (at 62.00) is paid first: the service now runs to 2028-01-01.
        $service->forceFill(['expires_at' => '2028-01-01'])->save();
        $upgrade = ServiceUpgrade::query()->sole();
        $applied = (new ServiceUpgradeService)->handle($upgrade->fresh());

        $billed = (float) InvoiceItem::query()->where('reference_type', ServiceUpgrade::class)->where('reference_id', $upgrade->id)->sum('price');
        $this->assertTrue(!$applied || abs($billed - 165.15) < 0.005,
            'applied for 55.15 while 548 days at the old price are left (110 × 548 / 365 = 165.15)');
    }

    /**
     * F5 (medium). A one-time service (no expiry) is moved to a cheaper one-time product: the whole difference
     * comes back as account credit, however long ago it was bought and used (the old code never refunded a service
     * without a due date: getMaxRefundAmount() = 0).
     */
    public function test_finding_a_one_time_downgrade_gives_no_credit(): void
    {
        $user = $this->user();
        $from = $this->product(['USD' => 100.00], type: 'one-time');
        $to = $this->product(['USD' => 10.00], type: 'one-time');
        $this->link($from, $to);
        $service = $this->service($user, $from, 100.00, ['expires_at' => null]);

        $this->upgradeTo($user, $service, $to);

        $this->assertSame((int) $to->id, (int) $service->fresh()->product_id, 'the downgrade itself happens');
        $this->assertSame('0.00', $this->credit(), 'a one-time purchase is not refunded by downgrading it');
    }

    /**
     * F6 (low, Δ1 design). A downgrade from an untaxed product (paid 10.00, no tax) to a taxed one (2.00 + 24% =
     * 2.48) credits the net difference WITH the new product's tax: 8.00 × 1.24 = 9.92, more than the 10.00 − 2.48
     * = 7.52 the customer paid and does not use. The reverse (taxed → untaxed) under-credits by the same 2.40.
     */
    public function test_finding_a_downgrade_from_untaxed_to_taxed_credits_no_more_than_was_paid(): void
    {
        $this->tax();
        $user = $this->user();
        $from = $this->product(['USD' => 10.00], unit: 'month', taxable: false);
        $to = $this->product(['USD' => 2.00], unit: 'month');
        $this->link($from, $to);
        $service = $this->service($user, $from, 10.00, ['expires_at' => now()->addMonthNoOverflow()]);

        $this->upgradeTo($user, $service, $to);

        $this->assertSame('7.52', $this->credit());
    }

    /** F6, the other way (owner's test): from a taxed product (10.00 + 24% paid) to one sold without tax (2.00). */
    public function test_a_downgrade_from_taxed_to_untaxed_credits_what_was_paid_less_the_new_price(): void
    {
        $this->tax();
        $user = $this->user();
        $from = $this->product(['USD' => 10.00], unit: 'month');
        $to = $this->product(['USD' => 2.00], unit: 'month', taxable: false);
        $this->link($from, $to);
        $service = $this->service($user, $from, 10.00, ['expires_at' => now()->addMonthNoOverflow()]);

        $this->upgradeTo($user, $service, $to);

        $this->assertSame('10.40', $this->credit(), '12.40 − 2.00 (it was 8.00 with no tax: 2.40 short)');
    }

    // ------------------------------------------------------------------------------------------------ probes that held

    public function test_quantity_with_per_unit_option_and_product_change(): void
    {
        $user = $this->user();
        $from = $this->product(['USD' => 62.00]);
        $to = $this->product(['USD' => 172.00]);
        $this->link($from, $to);
        $ip = $this->option('IP', [['None', 0], ['1 IP', 10]], $from->id);
        $ip->products()->attach($to->id);
        $service = $this->service($user, $from, 72.00, ['quantity' => 3]);
        $service->configs()->create(['config_option_id' => $ip->id, 'config_value_id' => $ip->children[1]->id]);

        $this->livewireAs($user)->test(Upgrade::class, ['service' => $service->fresh()])->set('upgrade', $to->id)
            ->call('nextStep')->call('doUpgrade');

        // (182 − 72) × 3 × 183 / 365 = 165.45
        $this->assertSame(165.45, $this->charged());
    }

    public function test_an_option_the_new_product_drops_is_neither_priced_nor_kept(): void
    {
        $user = $this->user();
        $from = $this->product(['USD' => 62.00]);
        $to = $this->product(['USD' => 172.00]);
        $this->link($from, $to);
        $ip = $this->option('IP', [['None', 0], ['1 IP', 10]], $from->id);
        $service = $this->service($user, $from, 72.00);
        $service->configs()->create(['config_option_id' => $ip->id, 'config_value_id' => $ip->children[1]->id]);

        $this->upgradeTo($user, $service->fresh(), $to);
        $this->assertSame(50.14, $this->charged(), '(172 − 72) × 183 / 365');

        $this->assertTrue((new ServiceUpgradeService)->handle(ServiceUpgrade::query()->sole()));
        $this->assertSame('172.00', number_format((float) $service->fresh()->price, 2, '.', ''));
        $this->assertSame(0, $service->fresh()->configs()->count());
    }

    public function test_inclusive_tax_upgrade_is_the_net_difference(): void
    {
        $this->tax('inclusive');
        $user = $this->user();
        $from = $this->product(['USD' => 12.40], unit: 'month');
        $to = $this->product(['USD' => 24.80], unit: 'month');
        $this->link($from, $to);
        $service = $this->service($user, $from, 10.00, ['expires_at' => now()->addMonthNoOverflow()]);

        $this->upgradeTo($user, $service, $to);

        $this->assertSame(10.00, $this->charged());
    }

    public function test_tax_exempt_client_downgrade_credit_has_no_tax(): void
    {
        $this->tax();
        $user = $this->user();
        $user->client()->update(['tax_exempt' => true]);
        $from = $this->product(['USD' => 10.00], unit: 'month');
        $to = $this->product(['USD' => 2.00], unit: 'month');
        $this->link($from, $to);
        $service = $this->service($user, $from, 10.00, ['expires_at' => now()->addMonthNoOverflow()]);

        $this->upgradeTo($user->fresh(), $service, $to);

        $this->assertSame('8.00', $this->credit());
    }

    public function test_quarterly_plan_uses_the_real_quarter(): void
    {
        $user = $this->user();
        $from = $this->product(['USD' => 30.00], unit: 'month', period: 3);
        $to = $this->product(['USD' => 60.00], unit: 'month', period: 3);
        $this->link($from, $to);
        $service = $this->service($user, $from, 30.00, ['expires_at' => '2026-08-15']);

        $this->upgradeTo($user, $service, $to);

        // 2026-05-15 .. 2026-08-15 = 92 days, 44 left: 30 × 44 / 92 = 14.35
        $this->assertSame(14.35, $this->charged());
    }

    public function test_end_of_february_monthly(): void
    {
        Carbon::setTestNow('2026-02-27 09:00:00');
        $user = $this->user();
        $from = $this->product(['USD' => 10.00], unit: 'month');
        $to = $this->product(['USD' => 41.00], unit: 'month');
        $this->link($from, $to);
        $service = $this->service($user, $from, 10.00, ['expires_at' => '2026-03-31']);

        $this->upgradeTo($user, $service, $to);

        // 2026-02-28 .. 2026-03-31 = 31 days, 32 left (paid ahead by a day): 31 × 32 / 31
        $this->assertSame(32.00, $this->charged());
    }

    public function test_expiring_tomorrow_charges_one_day(): void
    {
        $user = $this->user();
        $from = $this->product(['USD' => 62.00]);
        $to = $this->product(['USD' => 427.00]);
        $this->link($from, $to);
        $service = $this->service($user, $from, 62.00, ['expires_at' => '2026-07-03']);

        $this->upgradeTo($user, $service, $to);

        $this->assertSame(1.00, $this->charged(), '365 × 1 / 365');
    }

    public function test_the_cron_reprices_a_pending_upgrade_as_days_pass(): void
    {
        $user = $this->user();
        $from = $this->product(['USD' => 62.00]);
        $to = $this->product(['USD' => 172.00]);
        $this->link($from, $to);
        $service = $this->service($user, $from, 62.00);
        $this->upgradeTo($user, $service, $to);
        $this->assertSame(55.15, $this->charged());

        Carbon::setTestNow('2026-08-01 10:00:00');
        $this->runCronRefresh(ServiceUpgrade::query()->sole()->id);

        $this->assertSame(46.11, $this->charged(), '110 × 153 / 365');
    }

    public function test_handle_twice_applies_once(): void
    {
        $user = $this->user();
        $from = $this->product(['USD' => 62.00]);
        $to = $this->product(['USD' => 172.00]);
        $this->link($from, $to);
        $service = $this->service($user, $from, 62.00);
        $this->upgradeTo($user, $service, $to);
        $upgrade = ServiceUpgrade::query()->sole();

        $this->assertTrue((new ServiceUpgradeService)->handle($upgrade));
        $this->assertFalse((new ServiceUpgradeService)->handle($upgrade->fresh()));
        $this->assertSame('172.00', number_format((float) $service->fresh()->price, 2, '.', ''));
    }
}
