<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Service\AddAddon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #637 («Add addon», docs/addons.md step 3): each test is a defect found, failing on the branch.
 * Today is 2026-11-20 unless a test travels.
 */
class Adv637Test extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-11-20 10:00:00');
        config([
            'settings.cronjob_invoice' => 7,
            'settings.cronjob_order_suspend' => 8,
            'settings.cronjob_order_terminate' => 60,
            'settings.cronjob_order_cancel' => 0,
            'settings.credits_enabled' => false,
        ]);
        $this->client = User::factory()->create()->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function parent(string $expires = '2026-12-01', string $unit = 'month', ?int $billingDay = null): Service
    {
        $product = $this->createProduct();
        $product->plan->update(['billing_unit' => $unit]);
        $service = Service::factory()->create([
            'client_id' => $this->client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
            'domain' => 'vm104.example.test',
        ]);
        if ($billingDay !== null) {
            $service->forceFill(['billing_day' => $billingDay])->save();
        }

        return $service->fresh();
    }

    /** @return array{0: Product, 1: Plan} an addon product at 3.00 USD a cycle */
    private function addonProduct(string $unit = 'month', array $product = [], array $price = []): array
    {
        $made = $this->createProduct();
        $made->product->update(['name' => 'Extra IPv4', 'is_addon' => true, 'allow_quantity' => 'combined'] + $product);
        $made->plan->update(['billing_unit' => $unit]);
        Price::query()->where('plan_id', $made->plan->id)->update(['price' => 3.00] + $price);

        return [$made->product->fresh(), $made->plan->fresh()];
    }

    private function pay(Invoice $invoice): void
    {
        (new RecordPaymentService)->handle($invoice->fresh(), null, $invoice->fresh()->remaining, transactionId: 'adv637-' . $invoice->id . '-' . uniqid());
    }

    /**
     * ADV-637-1 (medium). A monthly addon of a yearly service that renews on the 31st (expires 2027-03-31): counted back
     * month by month from 31 March, the walk clamps to 28 February and stays on the 28th (Jan 28, Dec 28, Nov 28), so the
     * prorated period ends on 2026-11-28 and the addon renews on the 28th for good, never on its parent's day (31 March
     * next year: a separate invoice, days apart). Expected: the parent's day, clamped only in short months: 2026-11-30.
     */
    public function test_adv_637_1_a_monthly_addon_of_a_yearly_service_on_the_31st_is_prorated_to_the_30th_not_the_28th(): void
    {
        $parent = $this->parent('2027-03-31', 'year', billingDay: 31);
        [, $plan] = $this->addonProduct();

        $this->assertSame('2026-11-30', AddAddon::alignedEnd($parent, $plan)?->toDateString(),
            'the 31st, clamped to 30 November: not the 28th the walk back through February left it on');
    }

    /**
     * ADV-637-2 (medium). An addon prorated to a parent on the 31st (monthly, billing_day 31, expires 2026-11-30) gets
     * the parent's billing day (31). Its payment then remembers the prorated line's start (RenewServiceService ->
     * rememberBillingDay(2026-11-20)): billing_day becomes 20, and from 30 November it renews to 30 December while its
     * parent renews to 31 December: on different days, on separate invoices, from then on. Expected: the addon keeps its
     * parent's day; its first renewal ends on 2026-12-31 as its parent's.
     */
    public function test_adv_637_2_paid_the_addon_of_a_31st_parent_keeps_the_31st(): void
    {
        $parent = $this->parent('2026-11-30', billingDay: 31);
        [$product, $plan] = $this->addonProduct();
        $made = (new AddAddon)->handle($parent, $product, $plan, 1, null, false, AddAddon::PRORATED);
        $this->assertSame('2026-11-30', $made['invoice']->items()->sole()->period_end->toDateString());
        $this->pay($made['invoice']);

        $billing = new RenewalBilling;
        $billing->billRenewal($parent->id, Carbon::parse('2026-12-05'));
        $billing->billRenewal($made['service']->id, Carbon::parse('2026-12-05'));
        $billing->finishRenewalInvoices();
        $parentLine = $parent->invoiceItems()->whereDate('period_start', '2026-11-30')->sole();
        $addonLine = $made['service']->invoiceItems()->whereDate('period_start', '2026-11-30')->sole();
        $this->assertSame('2026-12-31', $parentLine->period_end->toDateString());
        $this->assertSame($parentLine->period_end->toDateString(), $addonLine->period_end->toDateString(), 'renewed with its parent');
    }

    /**
     * ADV-637-3 (medium). An addon product with stock (e.g. 5 extra IPv4 left): «Add addon» neither checks nor takes the
     * stock (PlaceOrderService does both), but every end gives it back (AddonCascade::end, the unpaid-orders cron,
     * a cancellation): adding 2 and ending them leaves 7 in stock, and staff can add more than there are. Expected: the
     * quantity is taken from the stock when it is added (refused when there is not enough), and given back when it ends.
     */
    public function test_adv_637_3_adding_an_addon_takes_its_quantity_from_the_stock(): void
    {
        $parent = $this->parent();
        [$product, $plan] = $this->addonProduct(product: ['stock' => 5]);

        (new AddAddon)->handle($parent, $product, $plan, 2, null, false, AddAddon::NO_INVOICE, Carbon::parse('2026-12-01'));
        $this->assertSame(3, (int) $product->fresh()->stock, 'two of five taken');

        $parent->fresh()->update(['status' => Service::STATUS_TERMINATED]);
        $this->assertSame(5, (int) $product->fresh()->stock, 'given back when it ends: never more than there were');

        try {
            (new AddAddon)->handle($this->parent(), $product->fresh(), $plan, 6, null, false, AddAddon::NO_INVOICE, Carbon::parse('2026-12-01'));
            $this->fail('six of five: refused');
        } catch (DisplayException) {
            $this->assertSame(5, (int) $product->fresh()->stock);
        }
    }

    /**
     * ADV-637-4 (medium). The addon's plan has a setup fee (5.00 USD, as WHMCS's addons often do): the shop's order bills
     * it (OrderPricing), «Add addon» drops it silently: the prorated invoice is 1.10 and the fee is never billed, nor shown
     * in the summary, nor can staff enter it. Expected: the setup fee is on the first invoice (or a field for it), as an
     * order's.
     */
    public function test_adv_637_4_the_plans_setup_fee_is_billed_on_the_first_invoice(): void
    {
        $parent = $this->parent();
        [$product, $plan] = $this->addonProduct(price: ['setup_fee' => 5.00]);

        $made = (new AddAddon)->handle($parent, $product, $plan, 1, null, false, AddAddon::PRORATED);

        $this->assertSame('6.10', number_format((float) $made['invoice']->fresh()->items()->get()->sum(fn ($line) => (float) $line->price * (int) $line->quantity), 2, '.', ''),
            '1.10 for the 11 days to the parent\'s date, and its 5.00 setup fee');
    }

    /**
     * ADV-637-5 (medium). A prorated addon whose invoice is paid after the period it bills (added 20 November, prorated
     * to 1 December, paid 15 December): it starts already expired (expires_at 2026-12-01, past Suspend Days) and the same
     * night's cron bills its next period and suspends it, the day it was paid and its creation task was made. The pro rata
     * products had the same hole (D-ADV-4): their first period also ends on a fixed day, and RenewServiceService gives
     * them Suspend Days from the payment (suspend_hold_until). Expected: the same for an addon prorated to its parent's
     * date: not suspended the night it was paid.
     */
    public function test_adv_637_5_a_prorated_addon_paid_after_its_period_is_not_suspended_the_night_it_is_paid(): void
    {
        $parent = $this->parent('2027-05-01', 'year'); // paid ahead: its parent is not overdue
        [$product, $plan] = $this->addonProduct();
        $made = (new AddAddon)->handle($parent, $product, $plan, 1, null, false, AddAddon::PRORATED);
        $this->assertSame('2026-12-01', $made['invoice']->items()->sole()->period_end->toDateString());

        Carbon::setTestNow('2026-12-15 10:00:00');
        $this->pay($made['invoice']);
        $this->assertSame(Service::STATUS_ACTIVE, $made['service']->fresh()->status);
        $this->artisan('app:cron-job');

        $addon = $made['service']->fresh();
        $this->assertSame(Service::STATUS_ACTIVE, $addon->status, "paid today: Suspend Days from today, as a pro rata product's first period (suspended: {$addon->suspend_reason})");
    }

    /**
     * ADV-637-6 (medium, with «cancel unpaid orders after N days» on). An addon «from its own date» in the future
     * (2027-01-01): its invoice is due then. The cron's «orders never paid» (CronJob::unpaidOrders) counts from the
     * service's creation, not the invoice's due date: 7 days after it was added, a month before its invoice is even due,
     * the addon is cancelled and its invoice with it. Expected: an invoice not yet due is not an order never paid; the
     * addon waits.
     */
    public function test_adv_637_6_an_addon_from_a_future_date_is_not_cancelled_as_an_unpaid_order_before_its_invoice_is_due(): void
    {
        config(['settings.cronjob_order_cancel' => 7]);
        $parent = $this->parent('2027-05-01', 'year');
        [$product, $plan] = $this->addonProduct();
        $made = (new AddAddon)->handle($parent, $product, $plan, 1, null, false, AddAddon::OWN_DATE, Carbon::parse('2027-01-01'));
        $this->assertSame('2027-01-01', $made['invoice']->fresh()->due_at->toDateString());

        Carbon::setTestNow('2026-11-30 10:00:00');
        $this->artisan('app:cron-job');

        $this->assertSame(Service::STATUS_PENDING, $made['service']->fresh()->status, 'not cancelled a month before its invoice is due');
        $this->assertSame(Invoice::STATUS_UNPAID, $made['invoice']->fresh()->status);
    }

    /**
     * ADV-637-7 (low, owner's call). «From its own date» in the future (2027-01-01, a full period 1 Jan - 1 Feb): with
     * credit on the account the invoice is paid at once (finishRenewalInvoices), and any early payment starts the addon:
     * it is active today and staff get its creation task today, so it runs 6 weeks before the period it paid for, free.
     * Expected: it does not run before the first day it is paid for (it waits for its date, or the period starts when it
     * starts).
     */
    public function test_adv_637_7_an_addon_from_a_future_date_does_not_run_before_its_paid_period(): void
    {
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        $this->client->credits()->create(['currency_code' => 'USD', 'amount' => 50.00]);
        $parent = $this->parent('2027-05-01', 'year');
        [$product, $plan] = $this->addonProduct();

        $made = (new AddAddon)->handle($parent, $product, $plan, 1, null, false, AddAddon::OWN_DATE, Carbon::parse('2027-01-01'));

        $this->assertSame(Invoice::STATUS_PAID, $made['invoice']->fresh()->status, 'paid by credit at once');
        $line = $made['invoice']->items()->sole();
        $addon = $made['service']->fresh();
        $this->assertFalse($addon->status === Service::STATUS_ACTIVE && $line->period_start->isAfter(Carbon::today()),
            "active from today, its paid period starting {$line->period_start->toDateString()}: the days between are free");
        $this->assertFalse(ModuleAction::query()->where('service_id', $addon->id)->exists() && $line->period_start->isAfter(Carbon::today()),
            'its creation task before the period it paid for');
    }

    /**
     * ADV-637-8 (low). A price with more than two decimals (3.005, typed in the form, which only says «numeric»):
     * BigDecimal::toScale(2) without a rounding mode throws RoundingNecessaryException, which the action does not catch
     * (only DisplayException): staff get an error page instead of a message. Expected: refused with a DisplayException
     * (or rounded), nothing made.
     */
    public function test_adv_637_8_a_price_with_three_decimals_is_refused_with_a_message(): void
    {
        $parent = $this->parent();
        [$product, $plan] = $this->addonProduct();

        try {
            $made = (new AddAddon)->handle($parent, $product, $plan, 1, '3.005', false, AddAddon::PRORATED);
        } catch (DisplayException) {
            $this->assertSame(0, Service::query()->whereNotNull('parent_service_id')->count(), 'refused: nothing made');

            return;
        } catch (Throwable $e) {
            $this->fail('not a message for staff: ' . $e::class);
        }
        $this->assertContains(number_format((float) $made['service']->fresh()->price, 2, '.', ''), ['3.00', '3.01'], 'or rounded to cents');
    }
}
