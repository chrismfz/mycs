<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Mail\Mail as MyCSMail;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Order\AcceptOrder;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\Service\WelcomeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep 3, lens «l-service-state», independent review of the fixes.
 */
class Rev3LServiceStateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * REV3-LSS-1. A one-time plan of a product staff set up by hand (Auto Setup «never»), on a server, ordered unpaid.
     * Staff Accept the order with «Run module create» unticked (built by hand). The fix now leaves the service pending
     * for its payment and, through $acceptedWaitingForPayment in RenewServiceService::activate, the payment runs the
     * module create on the server by itself: staff's «never automatically» and their unticked «Run module create» are
     * both lost (before the fix Accept started it as built by hand, and no create ever ran).
     */
    public function test_rev3_lss_1_a_never_product_accepted_without_module_create_gets_no_create_on_payment(): void
    {
        $server = Server::query()->create(['name' => 'Box', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $created = $this->createProduct(['autosetup' => Product::AUTOSETUP_NEVER, 'server_id' => $server->id]);
        $created->plan->update(['type' => 'one-time']);
        $product = $created->product->fresh();
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')]);
        $order = $placed->order->fresh();
        $service = $order->services()->sole();
        $this->assertTrue($service->hasServerActions(), 'precondition: on a server');
        $invoice = $service->invoices()->firstOrFail();

        AcceptOrder::handle($order, null, runCreate: false, sendEmail: false);
        Queue::assertNotPushed(CreateJob::class);

        ExtensionHelper::addPayment($invoice->id, null, 10.00, null, 'bank-rev3-1');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $this->assertCount(0, Queue::pushed(CreateJob::class), 'staff accepted it with «Run module create» unticked on a product set up «never» automatically: its payment must not run the module create');
    }

    /**
     * REV3-LSS-1, the fix: Accept records staff's choices on the service it leaves waiting, and its payment follows them.
     * «Run module create» ticked, «Send welcome email» unticked: the payment runs the create as staff's, without the mail.
     */
    public function test_rev3_lss_1_b_the_payment_runs_the_create_staff_ticked_on_accept_with_their_email_choice(): void
    {
        $server = Server::query()->create(['name' => 'Box', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $created = $this->createProduct(['autosetup' => Product::AUTOSETUP_ACCEPT, 'server_id' => $server->id]);
        $created->plan->update(['type' => 'one-time']);
        $product = $created->product->fresh();
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')]);
        $order = $placed->order->fresh();
        $service = $order->services()->sole();
        $invoice = $service->invoices()->firstOrFail();

        AcceptOrder::handle($order, null, runCreate: true, sendEmail: false);
        Queue::assertNotPushed(CreateJob::class);
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);

        ExtensionHelper::addPayment($invoice->id, null, 10.00, null, 'bank-rev3-1b');

        Queue::assertPushed(CreateJob::class, fn (CreateJob $job) => $job->service->is($service) && $job->sendNotification === false && $job->byStaff);
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status, 'active once its account exists');
        $this->assertNull($service->fresh()->accepted_run_create, 'its Accept\'s choices used once');
    }

    /** Built by hand on Accept («Run module create» unticked): paid, it turns active now, with staff's welcome email. */
    public function test_rev3_lss_1_c_built_by_hand_on_accept_turns_active_when_paid_with_the_welcome_email(): void
    {
        $server = Server::query()->create(['name' => 'Box', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $created = $this->createProduct(['autosetup' => Product::AUTOSETUP_NEVER, 'server_id' => $server->id]);
        $created->plan->update(['type' => 'one-time']);
        $product = $created->product->fresh();
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')]);
        $order = $placed->order->fresh();
        $service = $order->services()->sole();
        $invoice = $service->invoices()->firstOrFail();

        config(['settings.mail_disable' => false]);
        AcceptOrder::handle($order, null, runCreate: false, sendEmail: true);
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);

        ExtensionHelper::addPayment($invoice->id, null, 10.00, null, 'bank-rev3-1c');

        Queue::assertNotPushed(CreateJob::class);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'built by hand: active once paid');
        $this->assertCount(1, Mail::queued(MyCSMail::class, fn (MyCSMail $mail) => $mail->templateKey === WelcomeMail::TEMPLATE), 'staff\'s «Send welcome email»');
    }
}
