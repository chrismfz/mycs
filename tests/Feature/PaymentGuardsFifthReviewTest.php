<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Fifth (independent, adversarial) review of PR 2a (docs/payments.md). Every test here fails on cf64f08 and
 * describes the expected behaviour.
 */
class PaymentGuardsFifthReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 10:00:00');
        config(['settings.credits_enabled' => true]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->where('client_id', $this->user->client()->id)->where('currency_code', 'USD')->value('amount') ?? 0), 2, '.', '');
    }

    private function creditInvoice(float $price): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Credit deposit', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function serviceInvoice(): Invoice
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 10.00]);
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-10-15', 'currency_code' => 'USD', 'price' => 10.00,
        ]);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create([
            'reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10.00, 'period_start' => '2026-10-15', 'period_end' => '2026-11-15',
        ]);

        return $invoice->fresh();
    }

    /**
     * prepareRefund() takes the "bought" credit back from the customer's balance whenever the invoice has a credit
     * line, even when the invoice was never paid and so never granted any credit (ProcessPaidInvoiceService runs
     * only on paid). Customer has 200 credit from earlier; a 100 credit deposit invoice gets a partial 50 wire;
     * staff refund the 50: the customer gets the 50 back AND loses 50 of the credit they already had.
     */
    public function test_refund_of_a_partial_payment_on_an_unpaid_credit_purchase_does_not_take_existing_credit(): void
    {
        Credit::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD', 'amount' => 200.00]);
        $invoice = $this->creditInvoice(100.00);
        $payment = ExtensionHelper::addPayment($invoice->id, null, 50.00, null, 'wire-partial');
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame('200.00', $this->credit());

        ExtensionHelper::refundLocally($payment->fresh(), 50.00);

        $this->assertSame('50.00', (string) $payment->fresh()->refunded_amount);
        $this->assertSame('200.00', $this->credit(), 'no credit was bought by an unpaid invoice: none may be taken back');
    }

    /**
     * Same root cause, other side: with no other credit the refund of that partial payment is refused outright
     * («the credit this payment bought was already used»), although it bought nothing.
     */
    public function test_refund_of_a_partial_payment_on_an_unpaid_credit_purchase_is_not_refused(): void
    {
        $invoice = $this->creditInvoice(100.00);
        $payment = ExtensionHelper::addPayment($invoice->id, null, 50.00, null, 'wire-partial-2');

        ExtensionHelper::refundLocally($payment->fresh(), 50.00);

        $this->assertSame('50.00', (string) $payment->fresh()->refunded_amount);
    }

    /**
     * The fourth round made a refund of a credit purchase take the bought credit back; deleting that payment does
     * not. Deposit of 50 paid by Stripe -> +50 credit; staff delete the transaction (no refunds, so allowed): the
     * invoice stays paid with no money on it and the customer keeps 50 of spendable credit backed by nothing.
     */
    public function test_deleting_the_payment_of_a_credit_purchase_takes_back_the_bought_credit(): void
    {
        $invoice = $this->creditInvoice(50.00);
        $payment = ExtensionHelper::addPayment($invoice->id, 'Stripe', 50.00, null, 'pi_deposit_del', currency: 'usd');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('50.00', $this->credit());

        try {
            (new RecordPaymentService)->deleteTransaction($payment->fresh());
        } catch (DisplayException) {
            // refusing the deletion is a valid fix
        }

        $stillThere = $payment->fresh() !== null;
        $this->assertTrue($stillThere || $this->credit() === '0.00', 'a deleted credit-purchase payment must take back the credit it bought (or be refused)');
    }

    /**
     * InvoiceObserver::deleting only looks at succeeded transactions. An invoice with a Processing payment (Stripe
     * SEPA debit, days in flight) is deletable: the row goes by DB cascade, and when payment_intent.succeeded arrives
     * the money is not recorded anywhere (lockInvoice -> ModelNotFound, no log, no staff notice, no credit).
     */
    public function test_an_invoice_with_a_payment_in_flight_is_not_deleted(): void
    {
        $invoice = $this->serviceInvoice();
        ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 10.00, null, 'pi_sepa', currency: 'usd');

        try {
            $invoice->delete();
        } catch (DisplayException) {
            // refused: the expected behaviour
        }

        $this->assertNotNull(Invoice::query()->find($invoice->id), 'an invoice with a processing payment must not be deleted');
        $recorded = ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_sepa', currency: 'usd');
        $this->assertNotNull($recorded);
    }

    // The fixes' own tests (the credit a line gave marks it: applied_at).

    /** A credit line gives its credit once: paid, back to unpaid by staff, paid again -> still 50. */
    public function test_a_credit_purchase_gives_its_credit_once(): void
    {
        $invoice = $this->creditInvoice(50.00);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 50.00, null, 'pi_once', currency: 'usd');
        $this->assertSame('50.00', $this->credit());

        $invoice->fresh()->update(['status' => 'unpaid']);
        $invoice->fresh()->update(['status' => 'paid']);

        $this->assertSame('50.00', $this->credit());
    }

    /** Deleting a partial payment of an unpaid credit purchase takes back none of the customer's other credit. */
    public function test_deleting_a_partial_payment_of_an_unpaid_credit_purchase_keeps_the_other_credit(): void
    {
        Credit::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD', 'amount' => 200.00]);
        $invoice = $this->creditInvoice(100.00);
        $payment = ExtensionHelper::addPayment($invoice->id, null, 50.00, null, 'wire-partial-del');

        (new RecordPaymentService)->deleteTransaction($payment->fresh());

        $this->assertNull($payment->fresh());
        $this->assertSame('200.00', $this->credit());
    }
}
