<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Cancel;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\User;
use App\Services\Money\MoneyTrail;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\ServiceUpgrade\ChangeCycle;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Sweep 7, the fixes of «plan-cycle-addons» and «client-portal» (S7-PLAN-CYCLE-ADDONS-1..3, S7-CLIENT-PORTAL-1): each
 * rule, and that staff can still do what the customer is refused. Fake data only.
 */
class Sweep7PlanCyclePromoFixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'],
            'default_currency' => ['USD', 'string'], 'cronjob_invoice' => [7, 'integer']] as $key => [$value, $kind]) {
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

    private function plan(Product $product, string $unit, float $price): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => ucfirst($unit) . 'ly',
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $plan->fresh();
    }

    private function service(Product $product, Plan $plan, float $price, ?string $expires, array $attributes = []): Service
    {
        return Service::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id,
            'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => $price, 'quantity' => 1,
            'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $expires])->fresh();
    }

    private function line(Service $service, ?string $start, ?string $end, float $price, string $status, array $more = []): InvoiceItem
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $line = $invoice->items()->create($more + ['description' => 'Service', 'price' => $price, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $start, 'period_end' => $end]);
        if ($status !== Invoice::STATUS_UNPAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status]);
        }

        return $line;
    }

    /** A yearly service set up before its order was paid: active, due on the order's day, the order's line (with its setup fee) open. */
    private function setUpUnpaid(): array
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        $product = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($product, 'year', 120.00);
        $monthly = $this->plan($product, 'month', 12.00);
        $service = $this->service($product, $yearly, 120.00, '2026-07-02');
        $order = Order::query()->create(['client_id' => $service->client_id, 'currency_code' => 'USD']);
        $service->update(['order_id' => $order->id]);
        $orderLine = $this->line($service, null, null, 170.00, Invoice::STATUS_UNPAID, ['setup_fee' => 50.00]);
        $service = $service->fresh();
        $this->assertTrue($service->orderUnpaid());

        return [$service, $monthly, $orderLine];
    }

    // --- S7-PLAN-CYCLE-ADDONS-1 ---

    public function test_the_customer_is_told_to_pay_the_first_invoice_before_a_change_of_cycle(): void
    {
        [$service, $monthly, $orderLine] = $this->setUpUnpaid();

        $this->assertSame(__('services.change_cycle_order_unpaid'), ChangeCycle::refusal($service, $monthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM));
        try {
            ChangeCycle::apply($service, $monthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);
            $this->fail('refused for the customer');
        } catch (DisplayException $e) {
            $this->assertSame(__('services.change_cycle_order_unpaid'), $e->getMessage());
        }
        $this->assertNull($service->fresh()->next_plan_id);
        $this->assertSame(Invoice::STATUS_UNPAID, $orderLine->invoice->fresh()->status);
        foreach (['en', 'el'] as $locale) {
            $this->assertNotSame('services.change_cycle_order_unpaid', __('services.change_cycle_order_unpaid', [], $locale));
        }
    }

    public function test_staff_change_the_cycle_of_a_service_with_its_order_unpaid_and_the_orders_invoice_stays(): void
    {
        [$service, $monthly, $orderLine] = $this->setUpUnpaid();

        $this->assertNull(ChangeCycle::refusal($service, $monthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF));
        ChangeCycle::apply($service, $monthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);

        $this->assertSame($monthly->id, (int) $service->fresh()->next_plan_id, 'staff set the change');
        $invoice = $orderLine->invoice->fresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status, 'the order\'s invoice is not a renewal: never cancelled by a change of cycle');
        $this->assertSame('170.00', number_format((float) $invoice->total, 2, '.', ''), 'its setup fee still on it');
        $this->assertSame('50.00', number_format((float) $orderLine->fresh()->setup_fee, 2, '.', ''));
    }

    public function test_a_change_of_cycle_cancels_the_open_renewal_and_leaves_an_open_charge_of_the_service(): void
    {
        Carbon::setTestNow('2026-07-05 10:00:00');
        $product = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($product, 'year', 120.00);
        $monthly = $this->plan($product, 'month', 12.00);
        $service = $this->service($product, $monthly, 12.00, '2026-07-10');
        $this->line($service, '2026-06-10', '2026-07-10', 12.00, Invoice::STATUS_PAID);
        $renewal = $this->line($service, '2026-07-10', '2026-08-10', 12.00, Invoice::STATUS_UNPAID);
        // A charge of the service on its own invoice (an imported setup fee, #507): no period, not billed again.
        $charge = $this->line($service, null, null, 30.00, Invoice::STATUS_UNPAID, ['kind' => InvoiceItem::KIND_CHARGE]);

        ChangeCycle::apply($service->fresh(), $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);

        $this->assertSame(Invoice::STATUS_CANCELLED, $renewal->invoice->fresh()->status, 'the renewal of the old cycle goes: the cron bills it again');
        $this->assertSame(Invoice::STATUS_UNPAID, $charge->invoice->fresh()->status, 'the charge is still owed');
        $this->assertSame($yearly->id, (int) $service->fresh()->next_plan_id);
    }

    public function test_staff_are_refused_a_change_now_with_the_order_unpaid_and_told_the_way_through(): void
    {
        [$service, $monthly, $orderLine] = $this->setUpUnpaid();

        $this->assertSame(__('services.change_cycle_now_order_unpaid'), ChangeCycle::refusal($service, $monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF));
        foreach (['en', 'el'] as $locale) {
            $this->assertNotSame('services.change_cycle_now_order_unpaid', __('services.change_cycle_now_order_unpaid', [], $locale));
        }
        // A part payment on the order's invoice, which the change leaves alone, does not stop staff's «from the next renewal».
        ExtensionHelper::addPayment($orderLine->invoice_id, null, 20.00, null, 's7-pca-1', currency: 'USD');
        $this->assertNull(ChangeCycle::refusal($service->fresh(), $monthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF));
    }

    public function test_a_charge_beside_the_renewal_refuses_the_change_for_customer_and_staff_naming_the_invoice(): void
    {
        Carbon::setTestNow('2026-07-05 10:00:00');
        $product = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($product, 'year', 120.00);
        $monthly = $this->plan($product, 'month', 12.00);
        $service = $this->service($product, $monthly, 12.00, '2026-07-10');
        $this->line($service, '2026-06-10', '2026-07-10', 12.00, Invoice::STATUS_PAID);
        $renewal = $this->line($service, '2026-07-10', '2026-08-10', 12.00, Invoice::STATUS_UNPAID);
        $renewal->invoice->items()->create(['description' => 'Setup', 'price' => 30.00, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'kind' => InvoiceItem::KIND_CHARGE]);
        $number = $renewal->invoice->fresh()->number ?? $renewal->invoice_id;

        $this->assertSame(__('services.change_cycle_charge_on_renewal', ['invoice' => $number]),
            ChangeCycle::refusal($service->fresh(), $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM));
        $staff = ChangeCycle::refusal($service->fresh(), $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);
        $this->assertStringContainsString("#{$number}", (string) $staff);
        $this->assertStringContainsString('remove or cancel that charge by hand', (string) $staff, 'staff\'s way through');

        // A negative one (a promotion of the renewal) goes with it, as before.
        InvoiceItem::query()->where('invoice_id', $renewal->invoice_id)->where('kind', InvoiceItem::KIND_CHARGE)->update(['price' => -2.00]);
        $this->assertNull(ChangeCycle::refusal($service->fresh(), $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM));
    }

    // --- S7-PLAN-CYCLE-ADDONS-2 ---

    /** Monthly 12.00 paid to 2026-07-10, a change to yearly from the next renewal, applied by the cron on 2026-07-03. */
    private function switchedByTheCron(): array
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        $product = Product::factory()->create(['name' => 'Hosting', 'server_id' => null, 'stock' => null]);
        $monthly = $this->plan($product, 'month', 12.00);
        $yearly = $this->plan($product, 'year', 120.00);
        $bigger = Product::factory()->create(['name' => 'Bigger', 'server_id' => null, 'stock' => null]);
        $this->plan($bigger, 'month', 24.00);
        $this->plan($bigger, 'year', 240.00);
        DB::table('product_upgrades')->insert(['product_id' => $product->id, 'upgrade_id' => $bigger->id]);
        $service = $this->service($product, $monthly, 12.00, '2026-07-10');
        $this->line($service, '2026-06-10', '2026-07-10', 12.00, Invoice::STATUS_PAID);
        ChangeCycle::apply($service->fresh(), $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);
        Carbon::setTestNow('2026-07-03 10:00:00');
        $this->artisan('app:cron-job');
        $service = $service->fresh();
        $this->assertSame([$yearly->id, '2026-07-10', '2026-07-10'], [(int) $service->plan_id, $service->expires_at->toDateString(), $service->plan_from?->toDateString()]);

        return [$service, $bigger, $monthly];
    }

    public function test_a_package_change_is_refused_with_its_date_until_the_period_of_the_new_cycle_starts(): void
    {
        [$service, $bigger] = $this->switchedByTheCron();

        $this->assertSame('2026-07-10', $service->planStartsOn()?->toDateString());
        foreach ([ServiceUpgrade::SOURCE_CLIENT, ServiceUpgrade::SOURCE_STAFF] as $source) {
            $placed = PlaceUpgrade::handle($service, $bigger, [], $source);
            $this->assertSame(PlaceUpgrade::CYCLE_NOT_STARTED, $placed->outcome, "refused for {$source} too: no price is right until then");
        }
        $this->assertSame(0, ServiceUpgrade::query()->count());
        $this->assertSame(0, InvoiceItem::query()->where('reference_type', ServiceUpgrade::class)->count());
        foreach (['en', 'el'] as $locale) {
            app()->setLocale($locale);
            $this->assertStringContainsString('10/07/2026', PlaceUpgrade::cycleNotStarted($service));
            $this->assertStringNotContainsString('services.', PlaceUpgrade::cycleNotStarted($service));
        }
        app()->setLocale('en');
    }

    public function test_from_the_day_the_new_period_starts_the_package_change_is_priced_by_the_new_cycle(): void
    {
        [$service, $bigger] = $this->switchedByTheCron();
        $renewal = $service->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->sole();
        $this->assertSame('120.00', number_format((float) $renewal->total, 2, '.', ''), 'the first year, billed by the cron');
        ExtensionHelper::addPayment($renewal->id, null, 120.00, null, 's7-pca-2', currency: 'USD');
        $this->assertSame('2027-07-10', $service->fresh()->expires_at->toDateString());

        // Paid ahead, the old month still runs: still refused, to its day.
        $this->assertSame(PlaceUpgrade::CYCLE_NOT_STARTED, PlaceUpgrade::handle($service->fresh(), $bigger, [], ServiceUpgrade::SOURCE_CLIENT)->outcome);

        Carbon::setTestNow('2026-07-10 10:00:00');
        $this->assertNull($service->fresh()->planStartsOn());
        $placed = PlaceUpgrade::handle($service->fresh(), $bigger, [], ServiceUpgrade::SOURCE_CLIENT);
        $this->assertSame(PlaceUpgrade::INVOICED, $placed->outcome);
        $this->assertSame('120.00', number_format((float) $placed->invoice->fresh()->total, 2, '.', ''), 'the year\'s difference (240 − 120) for the whole year ahead');
    }

    public function test_a_change_of_cycle_now_starts_the_plan_today(): void
    {
        [$service, , $monthly] = $this->switchedByTheCron();

        // Staff put it back on the monthly cycle, now: the days left of the month paid are credited, a new month starts today.
        $this->assertNull(ChangeCycle::refusal($service, $monthly, ChangeCycle::NOW));
        ChangeCycle::apply($service, $monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $service = $service->fresh();
        $this->assertSame([$monthly->id, null], [(int) $service->plan_id, $service->plan_from]);
        $this->assertNull($service->planStartsOn());
    }

    // --- S7-PLAN-CYCLE-ADDONS-3 ---

    public function test_a_refunded_period_with_the_next_renewal_open_gives_no_credit(): void
    {
        Carbon::setTestNow('2026-07-26 10:00:00');
        $product = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($product, 'year', 120.00);
        $service = $this->service($product, $yearly, 120.00, '2026-08-01');
        // The period it is in was billed here and refunded; its next renewal is open: no day of it is paid for.
        $this->line($service, '2025-08-01', '2026-08-01', 120.00, Invoice::STATUS_REFUNDED);
        $this->line($service, '2026-08-01', '2027-08-01', 120.00, Invoice::STATUS_UNPAID);

        $this->assertSame('0.00', ChangeCycle::unused($service->fresh(), CarbonImmutable::today()));
    }

    public function test_the_imported_days_are_not_priced_by_the_next_cycles_plan_while_it_has_not_started(): void
    {
        Carbon::setTestNow('2026-07-26 10:00:00');
        $product = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($product, 'year', 120.00);
        // Imported monthly at 12.00, due 2026-08-01; the cron put it on the yearly plan (120.00) for the period from then.
        $service = $this->service($product, $yearly, 120.00, '2026-08-01');
        $service->forceFill(['plan_from' => '2026-08-01'])->save();
        $this->line($service, '2026-08-01', '2027-08-01', 120.00, Invoice::STATUS_UNPAID);

        $this->assertSame('0.00', ChangeCycle::unused($service->fresh(), CarbonImmutable::today()), 'never 6 days of the yearly price for days paid monthly');
    }

    public function test_a_period_paid_here_is_credited_from_its_line_with_the_next_renewal_open(): void
    {
        Carbon::setTestNow('2026-07-26 10:00:00');
        $product = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($product, 'year', 120.00);
        $service = $this->service($product, $yearly, 120.00, '2026-08-01');
        // Paid here at 73.00 (an offer), not the 120.00 it renews at: the credit is from what was paid.
        $this->line($service, '2025-08-01', '2026-08-01', 73.00, Invoice::STATUS_PAID);
        $this->line($service, '2026-08-01', '2027-08-01', 120.00, Invoice::STATUS_UNPAID);

        $this->assertSame('1.20', ChangeCycle::unused($service->fresh(), CarbonImmutable::today()), '6 of 365 days of 73.00');
    }

    // --- S7-CLIENT-PORTAL-1 ---

    private function product(float $price, string $name): Product
    {
        $product = Product::factory()->create(['name' => $name]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'year1',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function orderLine(Product $product): OrderLine
    {
        return new OrderLine($product->fresh(), $product->plans()->firstOrFail()->load('prices'), 1, []);
    }

    /** A (100.00) + B (200.00) ordered with «50% off B, requires A»: one unpaid invoice of 200.00, both pending. */
    private function orderWithRequires(?User $user = null): array
    {
        $a = $this->product(100.00, 'Controller');
        $b = $this->product(200.00, 'Agent');
        $coupon = Coupon::create(['code' => 'REQ' . uniqid(), 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 1]);
        $coupon->products()->sync([$b->id]);
        $coupon->requiredProducts()->sync([$a->id]);
        $user ??= User::factory()->create();
        $placed = (new PlaceOrderService)->place($user->client(), 'USD', [$this->orderLine($a), $this->orderLine($b)], $coupon->fresh());
        $serviceA = $placed->order->services()->where('product_id', $a->id)->sole();
        $serviceB = $placed->order->services()->where('product_id', $b->id)->sole();
        $this->assertSame('200.00', number_format((float) $placed->invoice->fresh()->total, 2, '.', ''));

        return [$user, $serviceA, $serviceB, $placed->invoice, $a];
    }

    public function test_the_customer_cannot_cancel_alone_the_pending_service_a_requires_discount_of_the_order_needs(): void
    {
        [$user, $serviceA, $serviceB, $invoice] = $this->orderWithRequires();

        $this->assertSame($serviceB->id, $serviceA->fresh()->neededByOrderPromotion()?->id);
        foreach (['immediate', 'end_of_period'] as $type) {
            $this->livewireAs($user)->test(Cancel::class, ['service' => $serviceA->fresh()])
                ->set('type', $type)->set('reason', 'changed my mind')->call('cancelService')
                ->assertNoRedirect();
            $this->assertSame(0, ServiceCancellation::query()->count(), "{$type}: no request made");
        }
        $this->assertSame(Service::STATUS_PENDING, $serviceA->fresh()->status);
        $invoice->refresh();
        $this->assertSame([Invoice::STATUS_UNPAID, '200.00', 2], [$invoice->status, number_format((float) $invoice->total, 2, '.', ''), $invoice->items()->count()],
            'the order stands as placed');
        foreach (['en', 'el'] as $locale) {
            $text = __('services.cancellation_needed_by_promotion', ['other' => 'Agent'], $locale);
            $this->assertStringContainsString('Agent', $text);
            $this->assertStringNotContainsString('services.', $text);
        }
    }

    public function test_the_customer_cancels_the_whole_order_the_discounted_service_first(): void
    {
        [$user, $serviceA, $serviceB, $invoice] = $this->orderWithRequires();

        // The discounted one first: nothing needs it. The invoice bills A alone, at its full price.
        $this->assertNull($serviceB->fresh()->neededByOrderPromotion());
        $this->livewireAs($user)->test(Cancel::class, ['service' => $serviceB->fresh()])
            ->set('type', 'immediate')->set('reason', 'not needed')->call('cancelService');
        $this->assertSame(Service::STATUS_CANCELLED, $serviceB->fresh()->status);
        $this->assertSame('100.00', number_format((float) $invoice->fresh()->total, 2, '.', ''));

        // Then the required one: no discount depends on it any more.
        $this->assertNull($serviceA->fresh()->neededByOrderPromotion());
        $this->livewireAs($user)->test(Cancel::class, ['service' => $serviceA->fresh()])
            ->set('type', 'immediate')->set('reason', 'not needed')->call('cancelService');
        $this->assertSame(Service::STATUS_CANCELLED, $serviceA->fresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }

    public function test_staff_cancel_the_required_pending_service_freely(): void
    {
        [, $serviceA, , $invoice] = $this->orderWithRequires();

        // As staff's «Cancellations» page makes it (ServiceCancellationResource): the request itself, no customer rule.
        ServiceCancellation::create(['service_id' => $serviceA->id, 'type' => 'immediate', 'reason' => 'by staff']);

        $this->assertSame(Service::STATUS_CANCELLED, $serviceA->fresh()->status);
        $this->assertSame(1, $invoice->fresh()->items()->count(), 'its line left the order\'s invoice, as before: staff decide the rest');
    }

    public function test_the_customer_cancels_the_pending_required_service_when_the_account_holds_one_already(): void
    {
        $user = User::factory()->create();
        [, $serviceA, , $invoice, $a] = $this->orderWithRequires($user);
        // One of the required product runs on the account already: the discount stands without the new one.
        Service::factory()->create(['client_id' => $user->client()->id, 'product_id' => $a->id, 'plan_id' => $a->plans()->firstOrFail()->id,
            'price' => 100.00, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addMonths(3)]);

        $this->assertNull($serviceA->fresh()->neededByOrderPromotion());
        $this->livewireAs($user)->test(Cancel::class, ['service' => $serviceA->fresh()])
            ->set('type', 'immediate')->set('reason', 'one is enough')->call('cancelService');
        $this->assertSame(Service::STATUS_CANCELLED, $serviceA->fresh()->status);
        $this->assertSame('100.00', number_format((float) $invoice->fresh()->total, 2, '.', ''));
    }

    public function test_a_pending_parent_is_not_cancelled_alone_when_its_addon_of_the_order_is_what_the_discount_requires(): void
    {
        [$user, $serviceA, $serviceB] = $this->orderWithRequires();
        // The required A is an addon of another pending service of the order: that one ending ends A with it.
        $host = $this->product(30.00, 'Host');
        $parent = Service::factory()->create(['client_id' => $user->client()->id, 'order_id' => $serviceA->order_id, 'product_id' => $host->id,
            'plan_id' => $host->plans()->firstOrFail()->id, 'price' => 30.00, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_PENDING]);
        $serviceA->forceFill(['parent_service_id' => $parent->id])->save();

        $this->assertSame($serviceB->id, $parent->fresh()->neededByOrderPromotion()?->id);
        $this->livewireAs($user)->test(Cancel::class, ['service' => $parent->fresh()])
            ->set('type', 'immediate')->set('reason', 'changed my mind')->call('cancelService');
        $this->assertSame(0, ServiceCancellation::query()->count());
        $this->assertSame(Service::STATUS_PENDING, $serviceA->fresh()->status);
    }

    public function test_once_the_order_is_paid_the_required_service_is_cancelled_as_any_other(): void
    {
        [$user, $serviceA, $serviceB, $invoice] = $this->orderWithRequires();
        ExtensionHelper::addPayment($invoice->id, null, 200.00, null, 's7-cp-1', currency: 'USD');

        $this->assertNull($serviceA->fresh()->neededByOrderPromotion(), 'bought: the first discount was earned');
        $this->livewireAs($user)->test(Cancel::class, ['service' => $serviceA->fresh()])
            ->set('type', 'immediate')->set('reason', 'not needed')->call('cancelService');
        $this->assertSame(Service::STATUS_CANCELLED, $serviceA->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(Service::STATUS_ACTIVE, $serviceB->fresh()->status);
    }
}
