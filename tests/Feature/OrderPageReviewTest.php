<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\OrderResource\OrderSummary;
use App\Admin\Resources\OrderResource\Pages\EditOrder;
use App\Admin\Resources\ServiceResource;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Code review of 482a38a (the order page as WHMCS's order view). */
class OrderPageReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function product(string $name = 'WordPress Hosting'): Product
    {
        $product = Product::factory()->create(['name' => $name, 'autosetup' => Product::AUTOSETUP_NEVER, 'server_id' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10.00, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function staff(array $permissions = ['*']): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function order(): Order
    {
        $product = $this->product();

        return (new PlaceOrderService)->place(User::factory()->create()->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')])->order;
    }

    public function test_saving_the_notes_on_the_order_page_still_works(): void
    {
        $order = $this->order();

        $this->livewireAs($this->staff())->test(EditOrder::class, ['record' => $order->id])
            ->fillForm(['notes' => 'checked by phone'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('checked by phone', $order->fresh()->notes);
    }

    public function test_the_payment_status_follows_a_payment_made_after_the_page_was_first_read_in_the_same_process(): void
    {
        // once() in a static method is keyed by spl_object_id($order) in a process-wide store: a long-lived process (Octane,
        // a queue worker, a test) that reads the summary of an order object, then of another or of the same order after a
        // payment, may get the first invoice back.
        $order = $this->order();
        $this->assertSame('Unpaid', OrderSummary::paymentStatus(OrderSummary::invoice($order)));

        $invoice = Invoice::query()->findOrFail($order->invoice_id);
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->total, transactionId: 'bank-' . $invoice->id);

        $this->assertSame('Paid', OrderSummary::paymentStatus(OrderSummary::invoice($order)));
    }

    public function test_another_order_object_never_gets_the_first_orders_invoice(): void
    {
        $first = $this->order();
        $second = $this->order();
        $this->assertNotSame($first->invoice_id, $second->invoice_id);

        $this->assertSame($first->invoice_id, OrderSummary::invoice(Order::query()->findOrFail($first->id))->id);
        // the first object is freed: its spl_object_id may be reused by the next one
        $this->assertSame($second->invoice_id, OrderSummary::invoice(Order::query()->findOrFail($second->id))->id);
    }

    public function test_after_cancel_and_refund_on_the_page_it_shows_what_is_now_true(): void
    {
        $order = $this->order();
        $invoice = Invoice::query()->findOrFail($order->invoice_id);
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->total, transactionId: 'bank-' . $invoice->id);

        $page = $this->livewireAs($this->staff())->test(EditOrder::class, ['record' => $order->id])
            ->assertSeeHtml('>Paid<')
            ->callAction('cancelRefund', ['method' => 'manual', 'terminate' => false]);

        $now = Invoice::query()->findOrFail($order->invoice_id);
        $this->assertNotSame(Invoice::STATUS_PAID, $now->status, 'the refund changed the invoice');
        $page->assertDontSeeHtml('>Paid<')->assertSee(ucfirst((string) $now->status))->assertSee('Cancelled');
    }

    public function test_staff_who_cannot_open_the_invoice_or_the_service_get_no_link_to_a_403(): void
    {
        // As DomainResource / DomainRelationManager do (`canView(...) ? getUrl(...) : null`).
        $order = $this->order();
        $staff = $this->staff(['admin.orders.viewAny', 'admin.orders.update']);
        $serviceId = $order->services()->value('id');

        $this->livewireAs($staff)->test(EditOrder::class, ['record' => $order->id])
            ->assertSee('Order Items')
            ->assertDontSeeHtml(InvoiceResource::getUrl('view', ['record' => $order->invoice_id], panel: 'admin'))
            ->assertDontSeeHtml(ServiceResource::getUrl('edit', ['record' => $serviceId], panel: 'admin'));
    }
}
