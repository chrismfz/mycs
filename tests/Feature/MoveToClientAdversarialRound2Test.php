<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use App\Services\Order\CancelOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #357, round 2 (on dd848c7a, «the reviews of #357»): one test per finding, each failing on the
 * PR as written. The setup is MoveToClientTest's.
 */
class MoveToClientAdversarialRound2Test extends TestCase
{
    use RefreshDatabase;

    private User $from;

    private User $to;

    private Service $service;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'EUR', 'settings.invoice_snapshot' => true]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->from = User::factory()->create();
        $this->to = User::factory()->create();
        $product = $this->createProduct();
        $this->service = Service::factory()->create(['client_id' => $this->from->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 10, 'expires_at' => now()->addMonth()]);
        $this->staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
    }

    private function invoice(User $owner, array $lines): Invoice
    {
        $invoice = Invoice::query()->create(['client_id' => $owner->client()->id, 'currency_code' => 'EUR', 'due_at' => now(), 'status' => Invoice::STATUS_UNPAID]);
        foreach ($lines as $line) {
            $invoice->items()->create($line + ['quantity' => 1, 'price' => 10, 'description' => 'Line']);
        }

        return $invoice->fresh();
    }

    private function serviceLine(array $extra = []): array
    {
        return $extra + ['reference_type' => Service::class, 'reference_id' => $this->service->id];
    }

    /**
     * R2-1 (medium-high). The order the service was bought with stays the first client's when its invoice is paid (only
     * an unpaid order invoice of this item alone moves), and services.order_id still points at it («history, harmless»,
     * CR-9 of round 1). It is not harmless: staff's «Cancel Order» / «Cancel & Refund» / «Set as Fraud» on the first
     * client's order (CancelOrder::cancel) takes every service with that order_id, whoever owns it now, and cancels it
     * (and terminates it with «terminate»), cancels its open invoices (now the new client's) and, with a refund, gives
     * the first client back what it paid for a service the new client keeps paying for. One click on the old account
     * ends the new client's service.
     * Fix: CancelOrder acts only on the order's services and domains still of the order's client (or MoveToClient clears
     * order_id of an item whose order stays behind).
     */
    public function test_cancelling_the_first_clients_order_does_not_cancel_the_moved_service(): void
    {
        $paid = $this->invoice($this->from, [$this->serviceLine()]);
        Invoice::query()->whereKey($paid->id)->update(['status' => Invoice::STATUS_PAID]);
        $order = Order::query()->create(['client_id' => $this->from->client()->id, 'currency_code' => 'EUR']);
        Order::query()->whereKey($order->id)->update(['invoice_id' => $paid->id]);
        $this->service->forceFill(['order_id' => $order->id])->save();
        $renewal = $this->invoice($this->from, [$this->serviceLine()]);

        app(MoveToClient::class)->service($this->service, $this->to->client(), $this->staff);
        $this->assertSame($this->from->client()->id, (int) $order->fresh()->client_id, 'a paid order stays with the first client');
        $this->assertSame($this->to->client()->id, (int) $renewal->fresh()->client_id);

        CancelOrder::handle($order->fresh());

        $this->assertSame(Service::STATUS_ACTIVE, $this->service->fresh()->status, 'the first client\'s order cancelled the new client\'s service');
        $this->assertSame(Invoice::STATUS_UNPAID, $renewal->fresh()->status, 'and the new client\'s open invoice of it');
    }

    /**
     * R2-2 (medium). A cancelled invoice of the service stays the first client's («its history»). Staff reopen it
     * (cancelled → unpaid is allowed: InvoiceObserver checks only that its period is not billed elsewhere) and the first
     * client has an open invoice again that bills, and when paid renews, the service of another client: the case
     * AUTH-8 forbids (an invoice never renews another customer's service) and InvoiceItemReference refuses for a new
     * line. Nothing checks it on a reopening, and before this PR a service never changed client under its invoices.
     * Fix: InvoiceObserver refuses a cancelled (or paid → unpaid) invoice going open again when one of its lines
     * references an item of another client (InvoiceItemReference::ownerOf), as on a change of client_id.
     */
    public function test_a_cancelled_invoice_left_behind_is_not_reopened_to_bill_the_moved_service(): void
    {
        $invoice = $this->invoice($this->from, [$this->serviceLine(['period_start' => now()->addMonth()->toDateString(), 'period_end' => now()->addMonths(2)->toDateString()])]);
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        app(MoveToClient::class)->service($this->service, $this->to->client(), $this->staff);
        $this->assertSame($this->from->client()->id, (int) $invoice->fresh()->client_id, 'a cancelled invoice stays where it is');

        rescue(fn () => Invoice::query()->findOrFail($invoice->id)->update(['status' => Invoice::STATUS_UNPAID]), null, false);

        $fresh = $invoice->fresh();
        $this->assertFalse($fresh->status === Invoice::STATUS_UNPAID && (int) $fresh->client_id === $this->from->client()->id,
            'the first client has an open invoice again that renews the new client\'s service');
    }
}
