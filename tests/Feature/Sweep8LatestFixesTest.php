<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\SplitInvoice;
use App\Services\Order\CancelOrder;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Sweep 8, adversarial verification of «latest-fixes» (#780, staff freedom on invoices). Failing tests for what was
 * confirmed; nothing here fixes app code.
 */
class Sweep8LatestFixesTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        config(['settings.credits_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * S8-LATEST-FIXES-1. An imported unpaid invoice with a payment of it processing (a SEPA debit): staff tick «paid
     * without payment», the service renews; the payment then succeeds. One payment must not buy two periods: either the
     * tick is refused while the payment is on its way, or the payment that lands is this invoice's (no account credit).
     */
    public function test_s8_latest_fixes_1_a_processing_payment_landing_on_an_invoice_paid_by_hand_is_not_given_as_credit(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($user->client()->id, $product->product->id);
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsInvoice(502, 'Unpaid', [31 => 10.0], due: '2026-10-10');
        $this->importWhmcs();
        $invoice = $this->imported(502);

        ExtensionHelper::addProcessingPayment($invoice, null, 10.00, null, 'sepa-1');

        $marked = $invoice->fresh();
        $marked->paid_without_payment = true;
        try {
            $marked->update(['status' => Invoice::STATUS_PAID]);
        } catch (DisplayException) {
            // Refused while its payment is on its way: one of the two acceptable outcomes.
            $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
            $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString());

            return;
        }
        $this->assertSame('2026-11-10', Service::query()->findOrFail(31)->expires_at->toDateString(), 'renewed by staff\'s word');

        ExtensionHelper::addPayment($invoice->fresh(), null, 10.00, null, 'sepa-1'); // the debit settles

        $fresh = $invoice->fresh();
        $payment = $fresh->transactions()->sole();
        $credit = number_format((float) (Credit::query()->where('client_id', $fresh->client_id)->value('amount') ?? 0), 2, '.', '');
        $this->assertSame('2026-11-10', Service::query()->findOrFail(31)->expires_at->toDateString(), 'one period for one payment');
        $this->assertSame(['0.00', 0, 0], [$credit, RecordPaymentService::toCents($payment->credited_amount), RecordPaymentService::toCents($fresh->remaining)],
            'the payment of this invoice pays this invoice: given as account credit, it buys a second period while the invoice still owes all of it');
        $this->assertNull($fresh->paid_by_staff_at, 'its money arrived: no longer «paid by hand, no payment»');
    }

    /**
     * S8-LATEST-FIXES-2. An order's own invoice is split (allowed since #780; ItemPaymentMethod does it too), both parts
     * are paid, staff press «Cancel & Refund». Every service is cancelled, so every paid part of the order is refunded,
     * or staff are told which was not.
     */
    public function test_s8_latest_fixes_2_cancel_and_refund_of_an_order_whose_invoice_was_split_refunds_or_tells_staff(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $user = User::factory()->create();
        $product = $this->createProduct()->product;
        $product->update(['autosetup' => Product::AUTOSETUP_PAYMENT, 'server_id' => null, 'stock' => 10]);
        $product = $product->fresh();
        $order = (new PlaceOrderService)->place($user->client(), 'USD',
            array_fill(0, 2, new OrderLine($product, $product->plans()->first(), price: '10.00')))->order->fresh();
        $own = Invoice::query()->findOrFail($order->invoice_id);
        $services = $order->services()->orderBy('id')->pluck('id')->all();
        $this->assertCount(2, $services);
        $second = InvoiceItem::query()->where('invoice_id', $own->id)->where('reference_type', Service::class)->where('reference_id', $services[1])->sole();

        try {
            $part = (new SplitInvoice)->handle($own->id, [$second->id]);
        } catch (DisplayException) {
            $this->assertSame(1, Invoice::query()->count()); // an order's invoice is not split: an acceptable outcome

            return;
        }
        $this->assertNotSame($own->id, $part->id);
        $this->assertSame($own->id, (int) $order->fresh()->invoice_id);
        foreach ([$own, $part] as $invoice) {
            (new RecordPaymentService)->handle($invoice->fresh(), null, (float) $invoice->fresh()->total, transactionId: 'bank-' . $invoice->id);
            $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        }

        $left = CancelOrder::handle($order->fresh(), refund: 'manual');

        $this->assertSame(['cancelled', 'cancelled'], Service::query()->whereKey($services)->orderBy('id')->pluck('status')->all());
        $this->assertSame(0.0, (float) $own->transactions()->sole()->refundable_amount, 'the order\'s invoice was refunded');
        $kept = (float) $part->transactions()->sole()->refundable_amount;
        $told = collect(array_keys($left))->contains(fn (string $key) => str_contains($key, "#{$part->id}"))
            || collect($left)->contains(fn (\Throwable $e) => str_contains($e->getMessage(), "#{$part->id}"));
        $this->assertTrue($kept === 0.0 || $told,
            "the split-off part #{$part->id} of the order keeps {$kept} for a cancelled service, still «{$part->fresh()->status}», and staff were told nothing");
    }
}
