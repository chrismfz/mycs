<?php

namespace Tests\Feature;

use App\Admin\Pages\PriceMismatches;
use App\Models\ConfigOption;
use App\Models\Coupon;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Billing\RenewalPrice;
use App\Services\Reports\PriceMismatch;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Round-3 (final) adversarial review of the Price mismatches report (PR #557, after round 2: the coupon requirement in
 * RenewalPrice, the per-language sweep under a lock, the Refresh limiter, optionsPriced). test_bug_* fail on the PR as it
 * stands; test_holds_* pin what held.
 */
class PriceMismatchesAdversarialR3Test extends TestCase
{
    use RefreshDatabase;

    private ?object $product = null;

    /** One product, its monthly plan at 20.00 USD. */
    private function product(): object
    {
        if ($this->product === null) {
            $this->product = $this->createProduct();
            Price::query()->where('plan_id', $this->product->plan->id)->update(['price' => 20.00, 'setup_fee' => 0]);
        }

        return $this->product;
    }

    private function service(float|string $price, array $attributes = []): Service
    {
        $product = $this->product();

        return Service::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => $price, 'quantity' => 1,
            'expires_at' => now()->addDays(5)->toDateString()]);
    }

    /** A coupon that requires a product nobody holds. */
    private function couponRequiringAGoneProduct(?int $recurring, string $code = 'NEEDS'): Coupon
    {
        $coupon = Coupon::create(['code' => $code, 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => $recurring]);
        $coupon->requiredProducts()->sync([Product::factory()->create()->id]);

        return $coupon;
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        return collect(PriceMismatch::rows())->keyBy(fn (array $row) => $row['type'] . $row['id'])->all();
    }

    private function asAdmin(?Staff $staff = null)
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $this->livewireAs($staff ?? Staff::factory()->admin()->create());
    }

    // ---------------------------------------------------------------------------------------------------------- bugs

    /**
     * ADV-PM-R3-1 (medium): a first-cycle coupon (recurring null, cycles() = 1) past its cycle whose required product is
     * gone. RenewalBilling's cycles rule runs for cycles() > 0: it records coupon_expired_at and leaves the price alone
     * («a first-cycle coupon never was in the renewal price»), so the requirement rule after it is skipped and the
     * renewal bills the stored 15.00. RenewalPrice's cycles rule runs only for cycles() > 1, so coupon_expired_at stays
     * null, the new requirement rule fires and re-prices at the catalog (20.00). The report then hides a real 15.00-vs-20.00
     * mismatch (both sides 20.00), and the forecast and Upcoming renewals show 20.00 where the billing charges 15.00.
     * Fix: RenewalPrice's cycles rule as RenewalBilling's (cycles() > 0, re-price only when > 1, always set expired_at).
     */
    public function test_bug_a_first_cycle_coupon_whose_requirement_is_gone_is_repriced_though_the_billing_keeps_the_price(): void
    {
        $coupon = $this->couponRequiringAGoneProduct(null);
        $expires = now()->addDays(5)->startOfDay();
        $service = $this->service('15.00', ['coupon_id' => $coupon->id, 'coupon_started_at' => $expires->copy()->subMonth(),
            'expires_at' => $expires->toDateString()]);

        $priced = RenewalPrice::for($service->fresh(), CarbonImmutable::parse($expires), true);
        DB::transaction(fn () => (new RenewalBilling)->billRenewal($service->id, now()->addDays(7)));
        $billed = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)->value('price');

        $this->assertSame('15.00', number_format((float) $billed, 2, '.', ''), 'the billing keeps the stored price');
        $this->assertSame('15.00', number_format((float) $priced->price, 2, '.', ''),
            'RenewalPrice re-priced it at the catalog: the report, the forecast and Upcoming renewals disagree with the billing');
    }

    /**
     * ADV-PM-R3-2 (low): every read takes the sweep lock, also when this language's sweep is already kept: rows() and
     * sweptAt() each lock on every render. While one staff member's sweep runs (or a sweep killed by max_execution_time
     * or OOM leaves the lock held for its 300 s), every page view and filter click waits up to 120 s and then throws
     * LockTimeoutException (a 500), though the answer is in the cache. Fix: Cache::get first, the lock only to sweep.
     */
    public function test_bug_a_kept_sweep_still_waits_on_the_lock(): void
    {
        $this->service('15.00');
        PriceMismatch::kept();
        $held = Cache::lock('reports:price-mismatch:sweep', 300);
        $this->assertTrue($held->get(), 'another sweep holds the lock');
        Sleep::fake(syncWithCarbon: true);

        try {
            $rows = PriceMismatch::kept()['rows'];
        } catch (\Throwable $e) {
            $this->fail('a kept sweep waited on the lock and threw ' . $e::class);
        } finally {
            $held->release();
        }

        $this->assertCount(1, $rows);
    }

    /**
     * ADV-PM-R3-3 (low): a lock timeout reaches the page. With nothing kept and the lock held past 120 s (a slow sweep,
     * or one killed while holding it: the lock outlives it for 300 s), kept() throws LockTimeoutException out of the
     * render: a 500 for staff. Under FPM the 120 s wait also outlasts nginx's fastcgi_read_timeout (60 s default): a 504
     * before it. Fix: a short block, and on timeout sweep without the lock (or show «a sweep is running, try again»).
     */
    public function test_bug_a_lock_timeout_is_an_error_page(): void
    {
        $this->service('15.00');
        $held = Cache::lock('reports:price-mismatch:sweep', 300);
        $this->assertTrue($held->get());
        Sleep::fake(syncWithCarbon: true);

        try {
            $this->asAdmin()->test(PriceMismatches::class)->assertOk();
        } catch (\Throwable $e) {
            $this->fail('the page threw ' . $e::class);
        } finally {
            $held->release();
        }
    }

    // ------------------------------------------------------------------------------------------------------- holds

    /** R2-1 itself: a «for N cycles» coupon whose requirement is gone renews at the catalog, as RenewalBilling bills it. */
    public function test_holds_a_recurring_coupon_whose_requirement_is_gone_matches_the_billing(): void
    {
        $coupon = $this->couponRequiringAGoneProduct(0);
        $expires = now()->addDays(5)->startOfDay();
        $service = $this->service('10.00', ['coupon_id' => $coupon->id, 'coupon_started_at' => $expires->copy()->subMonth(),
            'expires_at' => $expires->toDateString()]);

        $priced = RenewalPrice::for($service->fresh(), CarbonImmutable::parse($expires), true);
        DB::transaction(fn () => (new RenewalBilling)->billRenewal($service->id, now()->addDays(7)));
        $billed = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)->value('price');

        $this->assertSame('20.00', number_format((float) $billed, 2, '.', ''));
        $this->assertSame('20.00', number_format((float) $priced->price, 2, '.', ''));
    }

    /** Both rules at once (3 cycles past, requirement gone): the cycles rule ends it first, one full price, as the billing. */
    public function test_holds_cycles_then_requirement_in_the_billings_order(): void
    {
        $coupon = $this->couponRequiringAGoneProduct(3, 'BOTH');
        $expires = now()->addDays(5)->startOfDay();
        $service = $this->service('10.00', ['coupon_id' => $coupon->id, 'coupon_started_at' => $expires->copy()->subMonths(3),
            'expires_at' => $expires->toDateString()]);

        $priced = RenewalPrice::for($service->fresh(), CarbonImmutable::parse($expires), true);

        $this->assertSame('20.00', number_format((float) $priced->price, 2, '.', ''));
        $this->assertNotNull($priced->coupon_expired_at);
        $this->assertArrayNotHasKey("service{$service->id}", $this->rows());
    }

    /** A coupon with no requirement is left alone (the same model back, no query); the clone never touches the original. */
    public function test_holds_no_requirement_and_clone_semantics(): void
    {
        $plain = Coupon::create(['code' => 'PLAIN', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 0]);
        $service = $this->service('10.00', ['coupon_id' => $plain->id, 'coupon_started_at' => now()->subMonth()])->fresh();
        $this->assertSame($service, RenewalPrice::for($service, CarbonImmutable::parse($service->expires_at), true));

        $gone = $this->couponRequiringAGoneProduct(0, 'GONE');
        $other = $this->service('10.00', ['coupon_id' => $gone->id, 'coupon_started_at' => now()->subMonth()])->fresh();
        $priced = RenewalPrice::for($other, CarbonImmutable::parse($other->expires_at), true);

        $this->assertNotSame($other, $priced);
        $this->assertSame('10.00', number_format((float) $other->price, 2, '.', ''));
        $this->assertNull($other->coupon_expired_at);
        $this->assertFalse($other->isDirty());
    }

    /** No client loaded (null): requirementHeldBy(null) is false, the discount ends, as RenewalBilling would with it. */
    public function test_holds_a_null_client_ends_a_required_coupon_without_error(): void
    {
        $coupon = $this->couponRequiringAGoneProduct(0);
        $service = $this->service('10.00', ['coupon_id' => $coupon->id, 'coupon_started_at' => now()->subMonth()])->fresh();
        $service->setRelation('client', null);

        $priced = RenewalPrice::for($service, CarbonImmutable::parse($service->expires_at), true);

        $this->assertNotNull($priced->coupon_expired_at);
    }

    /** Refresh: three a minute per staff member, a fourth only warns; another staff member has their own three. */
    public function test_holds_refresh_is_limited_per_staff_member(): void
    {
        $service = $this->service('15.00');
        $page = $this->asAdmin()->test(PriceMismatches::class)->assertSee("Service #{$service->id}");
        foreach (range(1, 3) as $i) {
            $page->call('refresh')->assertNotNotified();
        }
        $page->call('refresh')->assertNotified(__('Swept a moment ago: try again in a minute.'));

        $this->asAdmin()->test(PriceMismatches::class)->call('refresh')->assertNotNotified();
        $this->travel(61)->seconds();
        $page->call('refresh')->assertNotNotified();
    }

    /** optionsPriced: an option with no plan for the service's cycle is free in every currency, compared, not skipped. */
    public function test_holds_an_option_not_priced_for_the_cycle_is_free_and_compared(): void
    {
        $option = ConfigOption::create(['name' => 'Disk', 'type' => 'radio', 'sort' => 0]);
        $option->products()->attach($this->product()->product->id);
        $value = ConfigOption::create(['name' => '10 GB', 'sort' => 0, 'parent_id' => $option->id]);
        $yearly = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => '10 GB',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $yearly->id, 'price' => 50.00, 'currency_code' => 'USD']);
        $service = $this->service('15.00');
        $service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $value->id]);

        $this->assertSame('-5.00', $this->rows()["service{$service->id}"]['difference']);
    }

    /** A «forever» coupon (cycles 0) is compared with its discount: its cycle is not needed, nor asked. */
    public function test_holds_a_forever_coupon_is_compared_with_its_discount(): void
    {
        $coupon = Coupon::create(['code' => 'EVER', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 0]);
        $even = $this->service('10.00', ['coupon_id' => $coupon->id, 'coupon_started_at' => now()->subYears(2)]);
        $off = $this->service('12.00', ['coupon_id' => $coupon->id, 'coupon_started_at' => now()->subYears(2)]);

        $rows = $this->rows();
        $this->assertArrayNotHasKey("service{$even->id}", $rows);
        $this->assertSame('2.00', $rows["service{$off->id}"]['difference']);
    }

    /** «As of» in the admin's d/m/Y H:i, and the same time in every language's sweep. */
    public function test_holds_as_of_format(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(9, 5));
        $this->service('15.00');

        $this->assertSame('31/10/2026 09:05', PriceMismatch::kept(fresh: true)['at']);
    }
}
