<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Events\InvoiceTransaction\Updated;
use App\Listeners\SendMailListener;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Money\GatewayReference;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The neighbours of the three sweep-10 fixes (S10-PAYMENTS-1, S10-PAYMENTS-2, S10-MONEY-LEDGER-1): what must stay as it
 * was, and the other answers of the new rules. Invented fixtures only.
 */
class Sweep10PaymentsLedgerFixTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_SECRET = 'whsec_sweep10fix';

    private const PAYPAL_API = 'https://api-m.sandbox.paypal.com';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        Cache::flush();
        $this->user = User::factory()->create();
    }

    // ---- S10-PAYMENTS-1: Stripe's payment_failed (and canceled) on a payment that is processing here ----

    private function stripeInvoice(): Invoice
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        foreach (['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::STRIPE_SECRET] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => now()->toDateString()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);

        return $invoice->fresh();
    }

    /** @return array<string, mixed> */
    private function intent(Invoice $invoice, string $status): array
    {
        return ['id' => 'pi_s10fix', 'object' => 'payment_intent', 'amount' => 1000, 'currency' => 'usd', 'status' => $status,
            'metadata' => ['mycs' => GatewayReference::mark(), 'invoice_id' => (string) $invoice->id]];
    }

    private function stripeWebhook(string $id, string $type, array $object)
    {
        $payload = json_encode(['id' => $id, 'created' => 1794304800, 'type' => $type, 'data' => ['object' => $object]]);
        $time = time();

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1=" . hash_hmac('sha256', $time . '.' . $payload, self::STRIPE_SECRET),
        ], $payload);
    }

    private function stripeSays(string|int $answer): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents/pi_s10fix' => is_int($answer)
            ? Http::response(['error' => ['message' => 'unavailable']], $answer)
            : Http::response(['id' => 'pi_s10fix', 'object' => 'payment_intent', 'status' => $answer])]);
    }

    private function failedMails(): int
    {
        return Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === SendMailListener::class
            && $job->data[0] instanceof Updated && $job->data[0]->invoiceTransaction->status === InvoiceTransactionStatus::Failed)->count();
    }

    /** A bank debit that bounced: Stripe, asked, says the intent wants a new method. Failed, the customer mailed, once. */
    public function test_a_failure_stripe_confirms_fails_the_processing_payment_and_mails_the_customer_once(): void
    {
        $invoice = $this->stripeInvoice();
        $this->stripeWebhook('evt_fix_1', 'payment_intent.processing', $this->intent($invoice, 'processing'))->assertOk();
        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status);
        Http::assertNothingSent(); // no failed payment of the intent: nothing to ask

        $this->stripeSays('requires_payment_method');
        $this->stripeWebhook('evt_fix_2', 'payment_intent.payment_failed', $this->intent($invoice, 'requires_payment_method'))->assertOk();

        $this->assertSame(InvoiceTransactionStatus::Failed, $invoice->transactions()->sole()->status);
        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()));
        $this->assertSame(1, $this->failedMails());
        Http::assertSentCount(1);

        // The same event again: the payment is failed already, Stripe is not asked, nothing more is mailed.
        $this->stripeWebhook('evt_fix_2', 'payment_intent.payment_failed', $this->intent($invoice, 'requires_payment_method'))->assertOk();
        $this->assertSame(InvoiceTransactionStatus::Failed, $invoice->transactions()->sole()->status);
        $this->assertSame(1, $this->failedMails());
        Http::assertSentCount(1);
    }

    public function test_a_cancelled_intent_fails_the_processing_payment(): void
    {
        $invoice = $this->stripeInvoice();
        $this->stripeWebhook('evt_fix_1', 'payment_intent.processing', $this->intent($invoice, 'processing'))->assertOk();
        $this->stripeSays('canceled');

        $this->stripeWebhook('evt_fix_2', 'payment_intent.canceled', $this->intent($invoice, 'canceled'))->assertOk();

        $this->assertSame(InvoiceTransactionStatus::Failed, $invoice->transactions()->sole()->status);
    }

    /** No payment of the intent here: the failed one is recorded as before, and Stripe is not asked. */
    public function test_a_failure_of_an_intent_with_no_payment_here_records_the_failed_payment(): void
    {
        $invoice = $this->stripeInvoice();

        $this->stripeWebhook('evt_fix_1', 'payment_intent.payment_failed', $this->intent($invoice, 'requires_payment_method'))->assertOk();

        $payment = $invoice->transactions()->sole();
        $this->assertSame(InvoiceTransactionStatus::Failed, $payment->status);
        $this->assertSame('pi_s10fix', $payment->transaction_id);
        Http::assertNothingSent();
    }

    /** The money came at Stripe and its «succeeded» is still on its way here: an old failure does not fail the payment. */
    public function test_a_failure_for_an_intent_that_succeeded_at_stripe_leaves_the_payment_processing(): void
    {
        $invoice = $this->stripeInvoice();
        $this->stripeWebhook('evt_fix_1', 'payment_intent.processing', $this->intent($invoice, 'processing'))->assertOk();
        $this->stripeSays('succeeded');

        $this->stripeWebhook('evt_fix_2', 'payment_intent.payment_failed', $this->intent($invoice, 'requires_payment_method'))->assertOk();

        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status);
        $this->assertSame(0, $this->failedMails());

        $this->stripeWebhook('evt_fix_3', 'payment_intent.succeeded', $this->intent($invoice, 'succeeded'))->assertOk();
        $this->assertSame(InvoiceTransactionStatus::Succeeded, $invoice->transactions()->sole()->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** Stripe does not answer: nothing changes and the webhook fails, so Stripe sends the event again. */
    public function test_a_failure_while_stripe_cannot_be_asked_changes_nothing_and_is_retried(): void
    {
        $invoice = $this->stripeInvoice();
        $this->stripeWebhook('evt_fix_1', 'payment_intent.processing', $this->intent($invoice, 'processing'))->assertOk();
        $this->stripeSays(503);

        $this->stripeWebhook('evt_fix_2', 'payment_intent.payment_failed', $this->intent($invoice, 'requires_payment_method'))->assertStatus(500);

        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status);
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()));
    }

    /** The mirror (S6-PAYMENTS-1) reads the intent the same way: a late «processing» on a failed payment stays failed. */
    public function test_a_late_processing_on_a_failed_payment_stays_failed_unless_stripe_says_processing(): void
    {
        $invoice = $this->stripeInvoice();
        $this->stripeWebhook('evt_fix_1', 'payment_intent.payment_failed', $this->intent($invoice, 'requires_payment_method'))->assertOk();
        $this->stripeSays('requires_payment_method');

        $this->stripeWebhook('evt_fix_2', 'payment_intent.processing', $this->intent($invoice, 'processing'))->assertOk();

        $this->assertSame(InvoiceTransactionStatus::Failed, $invoice->transactions()->sole()->status);
        Http::assertSentCount(1);
    }
}
