<?php

namespace Tests\Feature;

use App\Admin\Resources\OrderResource\Pages\EditOrder;
use App\Enums\DomainStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\CancelOrder;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Open item of the sweep-8 reviews (#793): plain «Cancel Order» (and «Set as Fraud»: no refund) on an order with a domain
 * already registered left the domain Active and said nothing. Now it tells staff as «Cancel & Refund» does: a warning
 * before they confirm, a notice, and the same «check this» task (ModuleAction::DOMAIN_REFUNDED, one open per domain),
 * in one text that claims nothing about refunds. The registrar is never called (Http::preventStrayRequests) and the domain keeps its status.
 */
class Sweep8OpenCancelOrderDomainTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Cache::flush();
        Carbon::setTestNow('2026-11-11 10:00:00');
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Order, 1: Invoice} an order of one service at 10.00 and its unpaid invoice */
    private function order(): array
    {
        $product = $this->createProduct()->product;
        $product->update(['autosetup' => Product::AUTOSETUP_PAYMENT, 'server_id' => null, 'stock' => 10]);
        $product = $product->fresh();
        $order = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')])->order->fresh();

        return [$order, Invoice::query()->findOrFail($order->invoice_id)];
    }

    private function domain(Order $order, DomainStatus $status, string $sld = 'cancelled-order'): Domain
    {
        return Domain::factory()->create(['client_id' => $this->user->client()->id, 'order_id' => $order->id, 'sld' => $sld, 'tld' => 'test',
            'fqdn' => "{$sld}.test", 'status' => $status]);
    }

    private function pay(Invoice $invoice): void
    {
        (new RecordPaymentService)->handle($invoice->fresh(), null, (float) $invoice->fresh()->total, transactionId: 'bank-' . $invoice->id);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** @return Collection<int, ModuleAction> */
    private function tasks(?Domain $domain = null)
    {
        return ModuleAction::query()->where('action', ModuleAction::DOMAIN_REFUNDED)->when($domain, fn ($query) => $query->where('domain_id', $domain->id))->orderBy('id')->get();
    }

    public function test_cancel_order_of_a_registered_domain_opens_a_task_that_names_it(): void
    {
        [$order, $invoice] = $this->order();
        $domain = $this->domain($order, DomainStatus::Active);
        $this->pay($invoice);

        $left = CancelOrder::handle($order->fresh());

        $this->assertSame(["domain #{$domain->id}"], array_keys($left), 'the notice staff see');
        $task = $this->tasks($domain)->sole();
        $this->assertSame(ModuleAction::STATUS_OPEN, $task->status);
        $this->assertTrue($task->isCheck(), 'a task to look at, not a failure');
        $this->assertSame($left["domain #{$domain->id}"]->getMessage(), $task->last_error);
        $this->assertSame(self::text('cancelled-order.test', $order), (string) $task->last_error);
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status, 'its status is not changed');
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'nothing was refunded');
        $this->assertSame('0.00', (string) $invoice->transactions()->sole()->refunded_amount);

        // Again (a second click, the bulk action), and then «Cancel & Refund»: no second open task for the domain.
        CancelOrder::handle($order->fresh());
        CancelOrder::handle($order->fresh(), refund: 'manual');
        $this->assertCount(1, $this->tasks($domain));
        $this->assertSame(1, ModuleAction::openChecks()['domains']);
    }

    public function test_set_as_fraud_tells_the_same_also_on_an_order_never_paid(): void
    {
        [$order] = $this->order();
        $domain = $this->domain($order, DomainStatus::Active); // never paid here (registered by hand): still registered

        $left = CancelOrder::handle($order->fresh(), fraud: true);

        $this->assertSame(Order::STATUS_FRAUD, $order->fresh()->status);
        $this->assertArrayHasKey("domain #{$domain->id}", $left);
        $this->assertCount(1, $this->tasks($domain));
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
    }

    /** The neighbours: no domain, a domain not sent yet (cancelled with the order, as before), or one ended: no task. */
    public function test_cancel_order_with_no_domain_a_pending_or_an_ended_one_opens_no_task(): void
    {
        [$plain] = $this->order();
        $this->assertSame([], CancelOrder::handle($plain->fresh()));

        [$withPending] = $this->order();
        $pending = $this->domain($withPending, DomainStatus::PendingRegister, 'pending-one');
        $this->assertSame([], CancelOrder::handle($withPending->fresh()));
        $this->assertSame(DomainStatus::Cancelled, $pending->fresh()->status);

        [$withEnded] = $this->order();
        $ended = $this->domain($withEnded, DomainStatus::Cancelled, 'ended-one');
        $this->assertSame([], CancelOrder::handle($withEnded->fresh()));
        $this->assertSame(DomainStatus::Cancelled, $ended->fresh()->status);

        $this->assertCount(0, $this->tasks());
    }

    /**
     * One text for every cancel, whatever went back (owner 2026-11-11; REV-793-6, REV-801-3): it claims no refund and no
     * absence of one. «Cancel & Refund» never paid, «Cancel & Refund» that refunded, «Cancel Order» after an earlier refund.
     */
    public function test_the_text_is_the_same_whatever_was_refunded(): void
    {
        [$unpaid] = $this->order();
        $never = $this->domain($unpaid, DomainStatus::Active, 'never-paid');
        $left = CancelOrder::handle($unpaid->fresh(), refund: 'manual');
        $this->assertSame(["domain #{$never->id}"], array_keys($left));
        $this->assertSame(self::text('never-paid.test', $unpaid), (string) $this->tasks($never)->sole()->last_error);

        [$order, $invoice] = $this->order();
        $domain = $this->domain($order, DomainStatus::Active, 'paid-one');
        $this->pay($invoice);
        $left = CancelOrder::handle($order->fresh(), refund: 'manual');
        $this->assertSame(self::text('paid-one.test', $order), $left["domain #{$domain->id}"]->getMessage());
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status, 'the refund staff asked for was made');
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);

        [$earlier, $paid] = $this->order();
        $kept = $this->domain($earlier, DomainStatus::Active, 'refunded-before');
        $this->pay($paid);
        ExtensionHelper::refundLocally($paid->transactions()->sole(), 10.00, notify: false);
        CancelOrder::handle($earlier->fresh());
        $this->assertSame(self::text('refunded-before.test', $earlier), (string) $this->tasks($kept)->sole()->last_error);
    }

    private static function text(string $fqdn, Order $order): string
    {
        return "{$fqdn} is already registered at the registrar: order #{$order->id} was cancelled, and that does not undo the registration"
            . ' (check its invoice for what was refunded). It stays active: keep it (and bill it), or delete it at the registrar, by hand.';
    }

    public function test_the_confirmation_warns_before_cancel_order_and_the_button_opens_the_task(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['*']])->id]);
        [$order, $invoice] = $this->order();
        $domain = $this->domain($order, DomainStatus::Active);
        $this->pay($invoice);
        [$other] = $this->order();

        $this->livewireAs($staff)->test(EditOrder::class, ['record' => $other->getRouteKey()])->mountAction('cancel')
            ->assertMountedActionModalDontSee('already registered at the registrar');
        $this->livewireAs($staff)->test(EditOrder::class, ['record' => $order->getRouteKey()])->mountAction('cancel')
            ->assertMountedActionModalSee('cancelled-order.test already registered at the registrar: cancelling the order does not undo the registration')
            ->callMountedAction(['terminate' => false])
            ->assertNotified('Order cancelled');

        $this->assertCount(1, $this->tasks($domain));
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
    }
}
