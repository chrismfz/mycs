<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Order\AcceptOrder;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Code review of 923e32d (orders pending until Accept): failing tests for what was found.
 */
class OrderPendingUntilAcceptReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-01-01 10:00:00');
        config(['settings.cronjob_order_cancel' => 7]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * docs/decisions.md (2026-10-19): a service waiting for its payment keeps waiting and «the cron cancels it if not
     * paid»; only staff's «Also start the services not paid yet» keeps the cron away. A product set up on Accept, started
     * unpaid by a plain Accept, sets orders.started_unpaid_at on the whole order: the other service of the same order,
     * which Accept left waiting for its payment, is then never cancelled by the cron (pending for ever).
     */
    public function test_a_product_set_up_on_accept_does_not_shield_the_unpaid_service_accept_left_waiting(): void
    {
        $onAccept = $this->createProduct(['autosetup' => Product::AUTOSETUP_ACCEPT])->product;
        $onPayment = $this->createProduct(['autosetup' => Product::AUTOSETUP_PAYMENT])->product;
        $user = User::factory()->create();
        $order = (new PlaceOrderService)->place($user->client(), 'USD', [
            new OrderLine($onAccept, $onAccept->plans()->first(), price: '10.00'),
            new OrderLine($onPayment, $onPayment->plans()->first(), price: '10.00'),
        ])->order->fresh();
        $waiting = Service::query()->where('order_id', $order->id)->where('product_id', $onPayment->id)->sole();

        AcceptOrder::handle($order, null); // a plain Accept: staff did not tick «start the unpaid ones»

        $this->assertSame(Order::STATUS_ACTIVE, $order->fresh()->status);
        $this->assertSame(Service::STATUS_PENDING, $waiting->fresh()->status, 'left waiting for its payment');

        Carbon::setTestNow('2026-01-20 10:00:00'); // Order Days Grace passed, never paid
        $this->artisan('app:cron-job');

        $this->assertSame(Service::STATUS_CANCELLED, $waiting->fresh()->status,
            'never paid and never started by staff: the cron cancels it (the order\'s started_unpaid_at, set by the other product, kept it pending for ever)');
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->findOrFail($order->invoice_id)->status, 'the started one keeps its lines due');
    }
}
