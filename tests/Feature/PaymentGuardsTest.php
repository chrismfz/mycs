<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Paymenter\Extensions\Gateways\PayPal\PayPal;
use Tests\TestCase;

/**
 * PR 2a (docs/payments.md): every payment goes through RecordPaymentService. Money the invoice cannot take
 * becomes account credit; replays and late webhooks change nothing; a cancelled invoice never turns paid.
 */
class PaymentGuardsTest extends TestCase
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
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 10.00]);

        return Service::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => '2026-10-15',
            'currency_code' => 'USD',
            'price' => 10.00,
        ], $attributes));
    }

    private function invoiceFor(Service $service, string $start, string $end, string $status = 'unpaid', float $price = 10.00): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => $status === Invoice::STATUS_PAID ? Invoice::STATUS_UNPAID : $status, 'currency_code' => 'USD']);
        $invoice->items()->create([
            'reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => $price, 'period_start' => $start, 'period_end' => $end,
        ]);

        // A paid fixture (a period paid in the past): stored paid, its lines added while it was open (MT-1c: a paid
        // invoice keeps its lines, and is never created paid).
        if ($status === Invoice::STATUS_PAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        }

        return $invoice->fresh();
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->where('user_id', $this->user->id)->where('currency_code', 'USD')->value('amount') ?? 0), 2, '.', '');
    }

    private function expires(Service $service): string
    {
        return $service->fresh()->expires_at->toDateString();
    }

    public function test_a_gateway_payment_pays_the_invoice_and_renews(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');

        $transaction = ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1', currency: 'usd');

        $this->assertNotNull($transaction);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('2026-11-15', $this->expires($service));
        $this->assertSame('0.00', $this->credit());
    }

    public function test_a_zero_or_negative_amount_writes_nothing(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');

        $this->assertNull(ExtensionHelper::addPayment($invoice->id, 'Stripe', 0, null, 'pi_zero'));
        $this->assertNull(ExtensionHelper::addPayment($invoice->id, 'Stripe', -10, null, 'pi_neg'));
        $this->assertNull(ExtensionHelper::addPayment($invoice->id, 'Stripe', 'abc', null, 'pi_text'));

        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_a_payment_in_another_currency_writes_nothing(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');

        $this->assertNull(ExtensionHelper::addPayment($invoice->id, 'PayPal', 10.00, null, 'cap_1', currency: 'EUR'));

        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame('2026-10-15', $this->expires($service));
    }

    public function test_a_replayed_webhook_is_recorded_once(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1');

        $this->assertSame(1, InvoiceTransaction::query()->count());
        $this->assertSame('2026-11-15', $this->expires($service));
        $this->assertSame('0.00', $this->credit());
    }

    /** PayPal subscriptions pay «the latest invoice»: a replay after the next invoice exists must not pay it. */
    public function test_a_replay_routed_to_another_invoice_of_the_customer_is_recorded_once(): void
    {
        $service = $this->service();
        $october = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        ExtensionHelper::addPayment($october->id, 'PayPal', 10.00, null, 'SALE-1');
        $november = $this->invoiceFor($service, '2026-11-15', '2026-12-15');

        ExtensionHelper::addPayment($november->id, 'PayPal', 10.00, null, 'SALE-1');

        $this->assertSame(1, InvoiceTransaction::query()->count());
        $this->assertSame('unpaid', $november->fresh()->status);
        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_a_late_processing_webhook_never_undoes_a_success(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1');
        ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1');
        ExtensionHelper::addFailedPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1');

        $this->assertSame(InvoiceTransactionStatus::Succeeded, InvoiceTransaction::query()->sole()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_processing_then_succeeded_is_one_payment(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');

        ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1');
        $this->assertSame('unpaid', $invoice->fresh()->status);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1');

        $this->assertSame(1, InvoiceTransaction::query()->count());
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('2026-11-15', $this->expires($service));
        $this->assertSame('0.00', $this->credit());
    }

    /** Τ7: the money arrived; it is kept on the invoice, the invoice stays cancelled, the customer gets credit. */
    public function test_a_payment_on_a_cancelled_invoice_becomes_credit_and_renews_nothing(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $invoice->update(['status' => 'cancelled']);

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_late');

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame(1, $invoice->fresh()->transactions()->count());
        $this->assertSame('10.00', $this->credit());
        $this->assertSame('2026-10-15', $this->expires($service));
    }

    public function test_a_second_payment_of_a_paid_invoice_becomes_credit(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1');

        ExtensionHelper::addPayment($invoice->id, 'PayPal', 10.00, null, 'cap_2'); // the customer paid twice

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('10.00', $this->credit());
        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_an_overpayment_pays_the_invoice_and_credits_the_rest(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 4.00, null, 'pi_1');

        ExtensionHelper::addPayment($invoice->id, 'PayPal', 7.50, null, 'cap_2');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('1.50', $this->credit());
        $this->assertSame('2026-11-15', $this->expires($service));
    }

    /** Staff adding a transaction in the admin never turns a cancelled invoice paid (the listener guard). */
    public function test_a_transaction_added_by_staff_never_pays_a_cancelled_invoice(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $invoice->update(['status' => 'cancelled']);

        $invoice->fresh()->transactions()->create(['amount' => 10.00, 'status' => InvoiceTransactionStatus::Succeeded]);

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('2026-10-15', $this->expires($service));
    }

    public function test_paying_with_credits_still_pays_and_spends_the_credit(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $this->user->credits()->create(['currency_code' => 'USD', 'amount' => 25.00]);
        $credit = Credit::query()->where('user_id', $this->user->id)->sole();
        $credit->update(['amount' => 15.00]); // the caller deducts first, then records the payment

        ExtensionHelper::addPayment($invoice->id, null, amount: 10.00, isCreditTransaction: true);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('15.00', $this->credit());
        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_the_same_transaction_id_of_another_customer_is_their_own_payment(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'shared-id');

        $other = User::factory()->create();
        $otherInvoice = Invoice::factory()->create(['user_id' => $other->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $otherInvoice->items()->create(['description' => 'Other', 'quantity' => 1, 'price' => 5.00]);

        $this->assertNotNull(ExtensionHelper::addPayment($otherInvoice->id, 'Stripe', 5.00, null, 'shared-id'));
        $this->assertSame('paid', $otherInvoice->fresh()->status);
    }

    // ------------------------------------------------------------------ follow-up review (2026-10-12)

    /** A replayed or refused subscription webhook never spends credit the customer already had. */
    public function test_a_replayed_or_refused_paypal_sale_spends_no_existing_credit(): void
    {
        $a = $this->service(['subscription_id' => 'I-SUB']);
        $b = $this->service(['subscription_id' => 'I-SUB']);
        $this->invoiceFor($a, '2026-10-15', '2026-11-15');
        $invoiceB = $this->invoiceFor($b, '2026-10-15', '2026-11-15');
        $this->user->credits()->create(['currency_code' => 'USD', 'amount' => 50.00]); // the customer's own credit
        $paypal = new PayPal([]);

        // refused: wrong currency, nothing recorded, nothing spent
        $paypal->recordSubscriptionSale(['id' => 'SALE-X', 'billing_agreement_id' => 'I-SUB', 'amount' => ['total' => '20.00', 'currency' => 'EUR'], 'transaction_fee' => ['value' => '1.00']]);
        $this->assertSame('unpaid', $invoiceB->fresh()->status);
        $this->assertSame('50.00', $this->credit());

        // an old sale replayed after it was recorded: nothing spent
        ExtensionHelper::addPayment($invoiceB->id, 'PayPal', 5.00, null, 'SALE-OLD');
        $paypal->recordSubscriptionSale(['id' => 'SALE-OLD', 'billing_agreement_id' => 'I-SUB', 'amount' => ['total' => '20.00', 'currency' => 'USD'], 'transaction_fee' => ['value' => '1.00']]);
        $this->assertSame('unpaid', $invoiceB->fresh()->status);
        $this->assertSame('50.00', $this->credit());
    }

    /** A sale pays the other services only with what it left as credit, on their oldest open invoice. */
    public function test_a_paypal_sale_pays_siblings_only_with_its_own_excess(): void
    {
        $a = $this->service(['subscription_id' => 'I-SUB']);
        $b = $this->service(['subscription_id' => 'I-SUB']);
        $invoiceA = $this->invoiceFor($a, '2026-10-15', '2026-11-15');
        $octoberB = $this->invoiceFor($b, '2026-10-15', '2026-11-15');
        Carbon::setTestNow('2026-10-13 10:00:00');
        $novemberB = $this->invoiceFor($b, '2026-11-15', '2026-12-15'); // next cycle, already billed
        $this->user->credits()->create(['currency_code' => 'USD', 'amount' => 50.00]);

        (new PayPal([]))->recordSubscriptionSale(['id' => 'SALE-1', 'billing_agreement_id' => 'I-SUB', 'amount' => ['total' => '20.00', 'currency' => 'USD'], 'transaction_fee' => ['value' => '1.00']]);

        $this->assertSame('paid', $invoiceA->fresh()->status);
        $this->assertSame('paid', $octoberB->fresh()->status);
        $this->assertSame('unpaid', $novemberB->fresh()->status, 'the next cycle is not paid from the customer\'s own credit');
        $this->assertSame('50.00', $this->credit());
    }

    /** Nobody marks a cancelled invoice paid directly; reopened, it is paid normally. */
    public function test_a_cancelled_invoice_cannot_be_marked_paid_directly(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $invoice->update(['status' => 'cancelled']);

        try {
            $invoice->fresh()->update(['status' => 'paid']);
            $this->fail('cancelled -> paid was accepted');
        } catch (DisplayException) {
        }

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('2026-10-15', $this->expires($service));
    }

    /** What became account credit cannot also be refunded. */
    public function test_a_refund_never_includes_what_became_credit(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $transaction = ExtensionHelper::addPayment($invoice->id, 'Stripe', 15.00, null, 'pi_over'); // 5.00 to credit

        try {
            ExtensionHelper::refundLocally($transaction->fresh(), 15.00);
            $this->fail('a refund of 15.00 was accepted although 5.00 became credit');
        } catch (InvalidArgumentException) {
        }
        ExtensionHelper::refundLocally($transaction->fresh(), 10.00);

        $this->assertEquals(10.00, (float) $transaction->fresh()->refunded_amount);
        $this->assertSame('5.00', $this->credit());
    }
}
