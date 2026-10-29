<?php

namespace Tests\Feature;

use App\Jobs\Server\CreateJob;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Order;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\AcceptOrder;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Round-3 review of #532 (combined): the «renew» task of a paid Offline SSL renewal, decided in
 * RenewServiceService::activate by «the payment moves the paid date on».
 */
class OfflineSslRound3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-01-01 10:00:00');
        config(['settings.default_currency' => 'USD']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * ADV-532-R3-1. A certificate set up on staff's Accept (Auto Setup «on accept», WHMCS «on»; or «start it unpaid»):
     * staff accept the order before it is paid. The create becomes a «create» task (staff buy the certificate at its
     * provider) and the service starts at once (startCreated), its paid-until date the order date (datesOnStart). The
     * customer then pays the order's own invoice: that line moves the paid date from the order date to a period later,
     * so `$renewal` is true and a «renew» task follows, «renew this certificate at its provider (paid until …)». It is the
     * first period, the one the create task already bought: staff are told to buy the certificate twice (R2-1 covered only
     * the case where staff had already moved the date over the first period).
     */
    public function test_adv_532_r3_1_the_order_invoice_paid_after_staff_accepted_it_unpaid_is_no_renewal(): void
    {
        $server = Server::query()->create(['name' => 'TheSSLStore (offline)', 'extension' => 'OfflineSsl', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct()->product;
        $product->update(['autosetup' => Product::AUTOSETUP_ACCEPT, 'server_id' => $server->id, 'stock' => 10]);
        $product = $product->fresh();
        $placed = (new PlaceOrderService)->place(User::factory()->create()->client(), 'USD',
            [new OrderLine($product, $product->plans()->first(), price: '10.00')]);
        $order = $placed->order->fresh();
        $service = $order->services()->sole();

        // Staff accept it unpaid: the create is a task for staff, and the service starts.
        AcceptOrder::handle($order, null, sendEmail: false);
        (new CreateJob($service->fresh()))->handle();
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertSame(1, ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'create')->count(),
            'staff buy the certificate by hand (create)');

        // The customer pays the order's invoice: its first period, the certificate already bought.
        $invoice = Invoice::query()->whereHas('items', fn ($items) => $items->where('reference_id', $service->id)
            ->where('reference_type', Service::class))->firstOrFail();
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->total, transactionId: 'bank-' . $invoice->id);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $renew = ModuleAction::query()->open()->where('service_id', $service->id)->where('action', ModuleAction::RENEW)->first();
        $this->assertNull($renew, 'the order\'s own invoice of a certificate staff set up on Accept became a «renew» task next to its '
            . '«create» task: the certificate would be bought twice: ' . $renew?->last_error);
        Http::assertNothingSent();
    }
}
