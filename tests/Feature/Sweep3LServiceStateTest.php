<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Order\AcceptOrder;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * Sweep 3, lens «l-service-state» (adversarial verifier). Each test fails on main @ ba71ac62 for the reason in its doc.
 */
class Sweep3LServiceStateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-10-12 10:00:00');
        config([
            'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14,
            'settings.cronjob_terminate_min_suspended_days' => 1, 'settings.cronjob_invoice' => 7,
            'settings.credits_auto_use' => false,
        ]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function stripe(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        foreach (['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => 'whsec_test'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        (new Stripe(['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => 'whsec_test']))->boot();
        Http::fake();
    }

    private function cronUntil(string $day): void
    {
        while (now()->toDateString() < $day) {
            Carbon::setTestNow(now()->addDay());
            $this->artisan('app:cron-job')->assertExitCode(0);
        }
    }

    /**
     * L-SERVICE-STATE-1. A paid renewal charged back is unpaid again, its line still applied. Its open invoice keeps the
     * next period from being billed; the service is suspended past Suspend Days; the customer then pays that invoice
     * (all they were ever billed) and the payment neither unsuspends it (the line was applied already) nor lets the next
     * period be billed (paidSinceSuspended reads the old applied_at). Termination Days later the cron terminates it,
     * with nothing ever open for the customer to pay.
     */
    public function test_l_service_state_1_a_chargeback_paid_again_does_not_end_in_termination_with_nothing_billed(): void
    {
        $this->markTestIncomplete('L-SERVICE-STATE-1: fixed in lens l-ledger-invariant (fix/sweep3-l-ledger-invariant), not on this branch.');
        $this->stripe();
        $created = $this->createProduct();
        Price::query()->where('plan_id', $created->plan->id)->update(['price' => 10.00]);
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $created->plan->id, 'product_id' => $created->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-10-15', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ]);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-10-15']);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10, 'period_start' => '2026-10-15', 'period_end' => '2026-11-15']);

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, transactionId: 'pi_l1', currency: 'usd');
        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString(), 'renewed by the payment');

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_l1', 'dp_l1', 10.00, 'usd'));
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'owed again');

        $this->cronUntil('2026-11-19'); // expires_at + 4 > Suspend Days 2
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'suspended for the unpaid (charged-back) invoice');

        // The customer pays the only invoice they were sent.
        ExtensionHelper::addPayment($invoice->id, null, 10.00, null, 'bank-l1');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $this->cronUntil('2026-12-01'); // expires_at + 16 > Termination Days 14

        $nextBilled = $service->fresh()->invoiceItems()->whereDate('period_start', '2026-11-15')->exists();
        $this->assertNotSame(Service::STATUS_TERMINATED, $service->fresh()->status,
            'terminated although the customer paid everything billed: the next period was ' . ($nextBilled ? '' : 'never ') . 'billed, '
            . 'and the payment of the charged-back invoice neither unsuspended it nor let the next period be billed');
    }

    /**
     * L-SERVICE-STATE-2. A one-time plan of a product set up on Accept, ordered and not paid: Accept with its defaults
     * starts it (started_unpaid_at), and nothing ever acts on its unpaid invoice: no due date to suspend from (one-time),
     * not pending for the unpaid-order cancellation. SetUpOnOrder refuses the same start for this reason.
     */
    public function test_l_service_state_2_an_accepted_unpaid_one_time_service_is_not_left_active_for_ever(): void
    {
        config(['settings.cronjob_order_cancel' => 7]);
        $created = $this->createProduct(['autosetup' => Product::AUTOSETUP_ACCEPT, 'server_id' => null]);
        $created->plan->update(['type' => 'one-time']);
        $product = $created->product->fresh();
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')]);
        $order = $placed->order->fresh();
        $service = $order->services()->sole();
        $invoice = $service->invoices()->firstOrFail();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);

        AcceptOrder::handle($order, null); // staff's defaults
        // Fixed: Accept leaves it waiting for its payment (it was started unpaid here before the fix).
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status, 'left waiting for its payment, not started unpaid');

        $this->cronUntil('2027-01-10'); // 90 days

        $this->assertFalse($service->fresh()->status === Service::STATUS_ACTIVE && $invoice->fresh()->status === Invoice::STATUS_UNPAID,
            'still active 90 days on, its only invoice unpaid: nothing suspends, terminates or cancels an unpaid one-time service started on Accept');
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status, 'never paid: cancelled by the cron as any unpaid order');
    }

    /**
     * L-SERVICE-STATE-2, the other side: Accept leaves the unpaid one-time service waiting for its payment, and that
     * payment starts it (no second Accept comes for an order accepted already), while a never-paid one is cancelled.
     */
    public function test_l_service_state_2_a_one_time_service_accept_left_waiting_starts_when_paid(): void
    {
        $created = $this->createProduct(['autosetup' => Product::AUTOSETUP_ACCEPT, 'server_id' => null]);
        $created->plan->update(['type' => 'one-time']);
        $product = $created->product->fresh();
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')]);
        $order = $placed->order->fresh();
        $service = $order->services()->sole();
        $invoice = $service->invoices()->firstOrFail();
        $this->assertTrue(AcceptOrder::waitsForPayment($service), 'unpaid one-time: offered to staff under «start unpaid»');

        AcceptOrder::handle($order, null);
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status, 'left waiting for its payment');
        $this->assertNull($service->fresh()->started_unpaid_at);

        ExtensionHelper::addPayment($invoice->id, null, 10.00, null, 'bank-l2');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'its payment starts it: its order was accepted already');
    }

    /** A one-time service paid before Accept, of a product set up on Accept, still waits for staff (unchanged). */
    public function test_l_service_state_2_a_one_time_service_paid_before_accept_still_waits_for_staff(): void
    {
        $created = $this->createProduct(['autosetup' => Product::AUTOSETUP_ACCEPT, 'server_id' => null]);
        $created->plan->update(['type' => 'one-time']);
        $product = $created->product->fresh();
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')]);
        $order = $placed->order->fresh();
        $service = $order->services()->sole();

        ExtensionHelper::addPayment($service->invoices()->firstOrFail()->id, null, 10.00, null, 'bank-l2b');
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status, 'paid, it waits for staff');

        AcceptOrder::handle($order->fresh(), null);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'Accept sets it up');
    }

    /** Staff may still start it unpaid on purpose (decision 4). */
    public function test_l_service_state_2_staff_can_still_start_a_one_time_service_unpaid(): void
    {
        $created = $this->createProduct(['autosetup' => Product::AUTOSETUP_ACCEPT, 'server_id' => null]);
        $created->plan->update(['type' => 'one-time']);
        $product = $created->product->fresh();
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')]);
        $order = $placed->order->fresh();
        $service = $order->services()->sole();

        AcceptOrder::handle($order, null, startUnpaid: true);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertNotNull($service->fresh()->started_unpaid_at);
    }
}
