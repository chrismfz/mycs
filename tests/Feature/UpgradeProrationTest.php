<?php

namespace Tests\Feature;

use App\Livewire\Services\Upgrade;
use App\Models\Coupon;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\User;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SVC-14: an upgrade charges the difference between the service's new recurring amount and the one it has (its real
 * price, not the old product's catalog price), times its quantity, for the days left of its real period, as WHMCS
 * (`tblupgrades.recurringchange` × days left / days of the cycle). Its new recurring amount is the new product's catalog
 * price (decided 2026-10-14: a staff price is not carried over).
 */
class UpgradeProrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-02 10:00:00');
        Queue::fake();
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_on_downgrade' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean']]);
    }

    /** Stored, as the app reads them (code review 3: config() alone was reloaded from the database). */
    private function settings(array $values): void
    {
        foreach ($values as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(float $price, string $unit = 'year'): Product
    {
        $product = Product::factory()->create(['name' => 'P' . $price]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Plan',
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    /** @return array{User, Service, Product} a service of $from (a year from 2026-01-01 to 2027-01-01) and the product it can move to */
    private function setUpgrade(float $from, float $to, array $service = []): array
    {
        $user = User::factory()->create();
        $old = $this->product($from);
        $new = $this->product($to);
        DB::table('product_upgrades')->insert(['product_id' => $old->id, 'upgrade_id' => $new->id]);
        $model = Service::factory()->create($service + ['client_id' => $user->client()->id, 'product_id' => $old->id,
            'plan_id' => $old->plans()->first()->id, 'price' => $from, 'quantity' => 1, 'currency_code' => 'USD',
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2027-01-01']);

        return [$user, $model, $new];
    }

    private function upgradeTo(User $user, Service $service, Product $to): void
    {
        $this->livewireAs($user)->test(Upgrade::class, ['service' => $service])->set('upgrade', $to->id)->call('doUpgrade');
    }

    private function charged(): float
    {
        return (float) ServiceUpgrade::query()->sole()->invoice->items()->sole()->price;
    }

    public function test_the_difference_is_for_the_days_left_of_the_real_period(): void
    {
        [$user, $service, $to] = $this->setUpgrade(62.00, 172.00);

        $this->upgradeTo($user, $service, $to);

        // 183 of 365 days left: 110.00 × 183 / 365 (the old rule took 30-day months and 365-day years).
        $this->assertSame(55.15, $this->charged());
    }

    public function test_a_renewal_paid_ahead_is_charged_to_its_end(): void
    {
        // Renewed early to 2028-01-01: the new price is owed for every day paid at the old one.
        [$user, $service, $to] = $this->setUpgrade(62.00, 172.00, ['expires_at' => '2028-01-01']);

        $this->upgradeTo($user, $service, $to);

        $this->assertSame(165.15, $this->charged(), '110 × 548 / 365');
    }

    public function test_the_difference_is_from_the_price_the_service_has(): void
    {
        // Staff sold it at 40.00 (catalog 62.00): the customer pays up to the new price from what they pay.
        [$user, $service, $to] = $this->setUpgrade(62.00, 172.00, ['price' => 40.00]);

        $this->upgradeTo($user, $service, $to);

        $this->assertSame(66.18, $this->charged(), '(172 − 40) × 183 / 365');
    }

    public function test_the_difference_counts_every_unit(): void
    {
        [$user, $service, $to] = $this->setUpgrade(62.00, 172.00, ['quantity' => 3]);

        $this->upgradeTo($user, $service, $to);

        $this->assertSame(165.45, $this->charged(), '110 × 3 × 183 / 365');
    }

    public function test_the_new_recurring_amount_is_the_new_catalog_price(): void
    {
        [$user, $service, $to] = $this->setUpgrade(62.00, 172.00, ['price' => 40.00]);
        $this->upgradeTo($user, $service, $to);

        $this->assertTrue((new ServiceUpgradeService)->handle(ServiceUpgrade::query()->sole()));

        $this->assertSame('172.00', number_format((float) $service->fresh()->price, 2, '.', ''));
    }

    public function test_a_period_that_ended_pays_nothing_now(): void
    {
        // Overdue: no days of it are left to charge; its renewal is at the new price.
        [$user, $service, $to] = $this->setUpgrade(62.00, 172.00, ['expires_at' => '2026-06-01']);

        $this->upgradeTo($user, $service, $to);

        $upgrade = ServiceUpgrade::query()->sole();
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->status);
        $this->assertNull($upgrade->invoice_id);
        $this->assertSame((int) $to->id, (int) $service->fresh()->product_id);
        $this->assertSame('172.00', number_format((float) $service->fresh()->price, 2, '.', ''));
        $this->assertSame(0, Credit::query()->count(), 'no credit for days already past');
    }

    public function test_a_downgrade_credits_the_days_left_of_what_the_service_pays(): void
    {
        [$user, $service, $to] = $this->setUpgrade(172.00, 62.00, ['price' => 100.00]);

        $this->upgradeTo($user, $service, $to);

        // (62 − 100) × 183 / 365 = −19.05: from its price, not from the old catalog price (−55.15).
        $this->assertSame('19.05', number_format((float) Credit::query()->sole()->amount, 2, '.', ''));
        $this->assertSame((int) $to->id, (int) $service->fresh()->product_id);
    }

    public function test_a_downgrade_gives_no_credit_where_downgrade_credit_is_off(): void
    {
        // myip (WHMCS CreditOnDowngrade off): the downgrade happens, nothing is credited.
        $this->settings(['credits_on_downgrade' => [false, 'boolean']]);
        [$user, $service, $to] = $this->setUpgrade(172.00, 62.00);

        $this->upgradeTo($user, $service, $to);

        $this->assertSame((int) $to->id, (int) $service->fresh()->product_id);
        $this->assertSame(0, Credit::query()->count());
    }

    public function test_the_coupon_counts_where_it_covers_the_new_product(): void
    {
        $coupon = Coupon::create(['code' => 'HALF', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 0]);
        [$user, $service, $to] = $this->setUpgrade(62.00, 172.00, ['price' => 31.00, 'coupon_id' => $coupon->id]);

        $this->upgradeTo($user, $service, $to);

        // It renews at half the new price: (86 − 31) × 183 / 365.
        $this->assertSame(27.58, $this->charged());
    }

    public function test_a_coupon_for_other_products_does_not_follow_the_upgrade(): void
    {
        $coupon = Coupon::create(['code' => 'HALF', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 0]);
        [$user, $service, $to] = $this->setUpgrade(62.00, 172.00, ['price' => 31.00, 'coupon_id' => $coupon->id]);
        $coupon->products()->attach($service->product_id);

        $this->upgradeTo($user, $service, $to);

        $this->assertSame(70.69, $this->charged(), '(172 − 31) × 183 / 365');
    }

    /** Code review 1: the last cycle of a coupon «for 2 cycles» is not charged back by a move to a product of the same price. */
    public function test_a_coupons_last_discounted_cycle_is_not_charged_back(): void
    {
        $coupon = Coupon::create(['code' => 'HALF2', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 2]);
        [$user, $service, $to] = $this->setUpgrade(62.00, 62.00, ['price' => 31.00, 'coupon_id' => $coupon->id]);
        $this->paidRenewal($service, '2025-01-01', '2026-01-01', 31.00);
        $this->paidRenewal($service, '2026-01-01', '2027-01-01', 31.00);

        $this->upgradeTo($user, $service->fresh(), $to);

        $upgrade = ServiceUpgrade::query()->sole();
        $this->assertNull($upgrade->invoice_id, 'nothing to charge for a same-price move');
        $this->assertSame('62.00', number_format((float) $service->fresh()->price, 2, '.', ''), 'its next renewal is at the full price');
    }

    /** Code review 4: a period clamped to a short month counts its real days (31 May to 30 June: 30). */
    public function test_a_clamped_period_counts_its_real_days(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');
        [$user, $service, $to] = $this->setUpgrade(10.00, 40.00, ['expires_at' => '2026-06-30', 'billing_day' => 31]);
        DB::table('plans')->update(['billing_unit' => 'month']);
        $this->paidRenewal($service, '2026-05-31', '2026-06-30', 10.00);

        $this->upgradeTo($user, $service->fresh(), $to);

        $this->assertSame(29.00, $this->charged(), '30 × 29 / 30');
    }

    private function paidRenewal(Service $service, string $start, string $end, float $price): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Renewal', 'price' => $price, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $start, 'period_end' => $end]);
        // As the importer writes a paid invoice (the observer lets only a recorded payment mark one paid).
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
    }
}
