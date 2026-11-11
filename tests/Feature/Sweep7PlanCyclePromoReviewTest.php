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
use App\Models\Setting;
use App\Models\User;
use App\Services\Money\MoneyTrail;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\ServiceUpgrade\ChangeCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Independent and adversarial review of #776 (sweep 7: cycle changes, upgrades, «Requires» promotions). Each test is a
 * defect found, REV-776-n, and fails until it is fixed (a refusal that names staff's way through is a right outcome
 * wherever the test says so). Fake data only.
 */
class Sweep7PlanCyclePromoReviewTest extends TestCase
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

    /** A line of $service on $invoice (a new unpaid one when null); the invoice's status set after. */
    private function line(Service $service, ?string $start, ?string $end, float $price, string $status, array $more = [], ?Invoice $invoice = null): InvoiceItem
    {
        $invoice ??= Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $line = $invoice->items()->create($more + ['description' => 'Service', 'price' => $price, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $start, 'period_end' => $end]);
        if ($status !== Invoice::STATUS_UNPAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status]);
        }

        return $line;
    }

    private function money($amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /** What the account still owes: its open invoices' totals. */
    private function owed(Service $service): string
    {
        return $this->money(Invoice::query()->where('client_id', $service->client_id)->whereIn('status', Invoice::OPEN_STATUSES)->get()
            ->sum(fn (Invoice $invoice) => (float) $invoice->total));
    }

    /**
     * An imported yearly service (120.00), due 2026-08-01, with the renewal WHMCS billed before the cut-over still open:
     * its Hosting line as the importer writes it (ImportFromWhmcs::serviceLineReference: the service's, no kind, **no
     * period**: «its period given when paid»), and an older paid one.
     *
     * @return array{0: Service, 1: Plan, 2: InvoiceItem}
     */
    private function importedWithItsWhmcsRenewalOpen(): array
    {
        Carbon::setTestNow('2026-07-26 10:00:00');
        $product = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($product, 'year', 120.00);
        $monthly = $this->plan($product, 'month', 12.00);
        $service = $this->service($product, $yearly, 120.00, '2026-08-01');
        $order = Order::query()->create(['client_id' => $service->client_id, 'currency_code' => 'USD']);
        $service->update(['order_id' => $order->id]);
        $this->line($service, null, null, 120.00, Invoice::STATUS_PAID); // 2025's, paid in WHMCS
        $renewal = $this->line($service, null, null, 120.00, Invoice::STATUS_UNPAID); // 2026-08-01 → 2027-08-01, billed by WHMCS
        $service = $service->fresh();
        $this->assertFalse($service->orderUnpaid(), 'not its order\'s invoice: a renewal');
        $this->assertTrue($service->renewalInvoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->exists(), 'what the cron takes for its renewal on its way');

        return [$service, $monthly, $renewal];
    }

    /**
     * REV-776-1 (regression of S7-PLAN-CYCLE-ADDONS-1): `ChangeCycle::openRenewals` takes a renewal for an open invoice
     * with a **period** line. A renewal imported from WHMCS has none, so a change of cycle no longer cancels it (it did
     * on main: every open invoice of the service went). Staff change it «now» on 2026-07-26: 1.97 credited for the 6
     * days left, a month billed from today (12.00), and the year WHMCS billed (120.00) is still asked for: 132.00 owed
     * where 12.00 is; paid, the 120.00 line renews a monthly service.
     */
    public function test_rev1_a_change_of_cycle_now_cancels_the_open_renewal_imported_from_whmcs(): void
    {
        [$service, $monthly, $renewal] = $this->importedWithItsWhmcsRenewalOpen();

        if (ChangeCycle::refusal($service, $monthly, ChangeCycle::NOW) !== null) {
            $this->assertSame(Invoice::STATUS_UNPAID, $renewal->invoice->fresh()->status);

            return; // refused until staff settle the renewal: a right outcome
        }
        ChangeCycle::apply($service, $monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertSame($monthly->id, (int) $service->fresh()->plan_id);
        $this->assertSame(Invoice::STATUS_CANCELLED, $renewal->invoice->fresh()->status,
            'the yearly renewal WHMCS billed (120.00, from 2026-08-01) goes with the cycle it was for');
        $this->assertSame('12.00', $this->owed($service), 'one month from today, not that and the year (132.00)');
    }

    /**
     * REV-776-1, from the next renewal (the customer's own page): the open renewal of the old cycle is not cancelled, and
     * the cron bills nothing while it is open (RenewalBilling: `renewalInvoices` open): the change the customer was
     * told comes «at the next renewal» does not: he is still asked 120.00 for a year, and the monthly cycle starts a
     * year later if he pays it.
     */
    public function test_rev1_a_change_of_cycle_from_the_next_renewal_cancels_the_open_renewal_imported_from_whmcs(): void
    {
        [$service, $monthly, $renewal] = $this->importedWithItsWhmcsRenewalOpen();

        try {
            ChangeCycle::apply($service, $monthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);
        } catch (DisplayException) {
            $this->assertNull($service->fresh()->next_plan_id);

            return; // refused: a right outcome
        }

        $this->assertSame($monthly->id, (int) $service->fresh()->next_plan_id);
        $this->assertSame(Invoice::STATUS_CANCELLED, $renewal->invoice->fresh()->status,
            'the open renewal of the old cycle is cancelled, so the cron bills the period again in the new one (ChangeCycle, class comment)');
    }

    /**
     * REV-776-2: staff may change the cycle of a service set up before its order was paid, and the order's invoice
     * «stays as it is». From the next renewal that holds together (the cron bills nothing while it is open). «Now», on
     * the order's day, it does not: a yearly order (120.00 + 50.00 setup = 170.00, unpaid) changed to monthly bills a
     * month from today (12.00) beside the order's invoice, which still asks for the year: 182.00 owed where 62.00 is
     * (a month and the setup fee). ChangePackage refuses staff outright here («Its order is unpaid.»).
     */
    public function test_rev2_staff_changing_the_cycle_now_of_a_service_with_its_order_unpaid_do_not_bill_both_cycles(): void
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
        $this->assertSame('170.00', $this->owed($service));

        if (($why = ChangeCycle::refusal($service, $monthly, ChangeCycle::NOW)) !== null) {
            $this->assertSame(Invoice::STATUS_UNPAID, $orderLine->invoice->fresh()->status);
            $this->assertSame('170.00', $this->owed($service), "refused ({$why}): the order stands as placed");

            return; // a right outcome: its order paid (or cancelled and placed again) first
        }
        ChangeCycle::apply($service, $monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertSame($monthly->id, (int) $service->fresh()->plan_id);
        $this->assertLessThanOrEqual(62.00, (float) $this->owed($service),
            'a month (12.00) and the setup fee (50.00): not the year of the order (120.00) on top of the month');
    }

    private function product(float $price, string $name, array $attributes = []): Product
    {
        $product = Product::factory()->create($attributes + ['name' => $name]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'year1',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function orderLine(Product $product): OrderLine
    {
        return new OrderLine($product->fresh(), $product->plans()->firstOrFail()->load('prices'), 1, []);
    }

    /**
     * REV-776-3 (S7-CLIENT-PORTAL-1 not closed): `Service::neededByOrderPromotion` asks for a **pending** service. A
     * product with Auto Setup «as soon as an order is placed» (SetUpOnOrder) is active at once, its order unpaid: the
     * customer cancels the required A (100.00) from its page, its line leaves the order's invoice, and B (200.00,
     * «50% off, requires A») is left billed at 100.00, exactly the finding.
     */
    public function test_rev3_the_required_service_set_up_on_order_is_not_cancelled_alone_while_the_order_is_unpaid(): void
    {
        $a = $this->product(100.00, 'Controller', ['autosetup' => Product::AUTOSETUP_ORDER, 'server_id' => null]);
        $b = $this->product(200.00, 'Agent');
        $coupon = Coupon::create(['code' => 'REQ' . uniqid(), 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 1]);
        $coupon->products()->sync([$b->id]);
        $coupon->requiredProducts()->sync([$a->id]);
        $user = User::factory()->create();
        $placed = (new PlaceOrderService)->place($user->client(), 'USD', [$this->orderLine($a), $this->orderLine($b)], $coupon->fresh());
        $invoice = $placed->invoice;
        $serviceA = $placed->order->services()->where('product_id', $a->id)->sole();
        $serviceB = $placed->order->services()->where('product_id', $b->id)->sole();
        $this->assertSame('200.00', $this->money($invoice->fresh()->total), 'A 100.00 + B at half price');
        $this->assertSame(Service::STATUS_ACTIVE, $serviceA->fresh()->status, 'set up on order, before payment');
        $this->assertTrue($serviceA->fresh()->orderUnpaid());

        $this->livewireAs($user)->test(Cancel::class, ['service' => $serviceA->fresh()])
            ->set('type', 'immediate')->set('reason', 'changed my mind')->call('cancelService');

        $invoice->refresh();
        if ($serviceA->fresh()->status === Service::STATUS_ACTIVE && $this->money($invoice->total) === '200.00') {
            return; // refused: the order stands as placed
        }
        $this->assertFalse(in_array($invoice->status, Invoice::OPEN_STATUSES, true) && $this->money($invoice->total) === '100.00'
            && $serviceB->fresh()->status === Service::STATUS_PENDING,
            'the required A is cancelled unpaid, and the order\'s invoice still sells B at half price (100.00 of 200.00)');
    }

    /**
     * REV-776-4 (the author's open point, with amounts): an open invoice with the service's renewal (12.00, 2026-07-10 →
     * 2026-08-10) and a charge of the same service (30.00: kind `charge`, no period). A charge alone on an invoice is
     * kept by this PR (never billed again); beside the renewal it is cancelled with it, and the cron bills only the
     * period again: 30.00 lost. Right outcomes: the charge is still owed, or the change is refused for staff to settle.
     */
    public function test_rev4_a_charge_beside_the_renewal_on_one_invoice_is_not_lost_by_a_change_of_cycle(): void
    {
        Carbon::setTestNow('2026-07-05 10:00:00');
        $product = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($product, 'year', 120.00);
        $monthly = $this->plan($product, 'month', 12.00);
        $service = $this->service($product, $monthly, 12.00, '2026-07-10');
        $this->line($service, '2026-06-10', '2026-07-10', 12.00, Invoice::STATUS_PAID);
        $renewal = $this->line($service, '2026-07-10', '2026-08-10', 12.00, Invoice::STATUS_UNPAID);
        $this->line($service, null, null, 30.00, Invoice::STATUS_UNPAID, ['kind' => InvoiceItem::KIND_CHARGE], $renewal->invoice);
        $this->assertSame('42.00', $this->owed($service));

        try {
            ChangeCycle::apply($service->fresh(), $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);
        } catch (DisplayException) {
            $this->assertSame('42.00', $this->owed($service));

            return; // refused: staff settle the invoice first
        }

        $charges = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->where('kind', InvoiceItem::KIND_CHARGE)->whereHas('invoice', fn ($invoice) => $invoice->whereIn('status', Invoice::OPEN_STATUSES))->sum('price');
        $this->assertSame('30.00', $this->money($charges), 'the charge is still owed: the cron bills the period again, never the charge');
    }

    /**
     * REV-776-5: a change of cycle no longer touches an invoice with only a charge of the service, yet
     * `ChangeCycle::blocked` still refuses for a payment on **any** open invoice of it. A charge (30.00) paid in part
     * (10.00) stops the customer's change from the next renewal, with a message that names no way through, for an invoice
     * the change would leave as it is.
     */
    public function test_rev5_a_part_payment_on_an_invoice_the_change_leaves_alone_does_not_stop_it(): void
    {
        Carbon::setTestNow('2026-07-05 10:00:00');
        $product = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($product, 'year', 120.00);
        $monthly = $this->plan($product, 'month', 12.00);
        $service = $this->service($product, $monthly, 12.00, '2026-07-10');
        $this->line($service, '2026-06-10', '2026-07-10', 12.00, Invoice::STATUS_PAID);
        $charge = $this->line($service, null, null, 30.00, Invoice::STATUS_UNPAID, ['kind' => InvoiceItem::KIND_CHARGE]);
        ExtensionHelper::addPayment($charge->invoice_id, null, 10.00, null, 'rev776-5', currency: 'USD');
        $this->assertSame(Invoice::STATUS_UNPAID, $charge->invoice->fresh()->status);

        $this->assertNull(ChangeCycle::refusal($service->fresh(), $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM),
            'nothing of this change touches the charge\'s invoice: its part payment is no reason to refuse');
    }
}
