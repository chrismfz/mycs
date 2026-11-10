<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\GatewayReference;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * S7-PAYMENTS-3 (owner 2026-11-10): a gateway's payment «processing» for 14 days is told to staff, once a week, with
 * since when, its gateway and its reference; nothing changes by itself. Staff settle it by hand with what the invoice's
 * Transactions already have: «Add payment» with its transaction id completes it, deleting it frees the invoice, and a
 * success the gateway reports later is still recorded. Stripe's payment_intent.canceled is a failure.
 */
class Sweep7ProcessingPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_s7';

    private Client $client;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->client = User::factory()->create()->client();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $this->invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()]);
        $this->invoice->items()->create(['description' => 'Hosting', 'price' => '10.00', 'quantity' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function processing(string $id = 'pi_never_settled'): InvoiceTransaction
    {
        ExtensionHelper::addProcessingPayment($this->invoice->id, 'Stripe', '10.00', null, $id, currency: 'USD');

        return InvoiceTransaction::query()->where('transaction_id', $id)->sole();
    }

    /** @return list<string> the bodies of the cron's notices to staff that name the payment */
    private function told(string $reference = 'pi_never_settled'): array
    {
        return EmailLog::query()->where('to', 'staff@example.test')->where('subject', 'like', '%needs staff attention%')
            ->where('body', 'like', "%{$reference}%")->pluck('body')->all();
    }

    private function cronAt(string $at): void
    {
        Carbon::setTestNow($at);
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    public function test_staff_are_told_after_fourteen_days_once_a_week_and_nothing_changes(): void
    {
        $payment = $this->processing();
        $this->assertSame(14, CronJob::PROCESSING_STALE_DAYS);

        $this->cronAt('2026-11-24 08:00:00'); // 13 days and 23 hours
        $this->assertSame([], $this->told(), 'not before 14 days');

        $this->cronAt('2026-11-25 08:00:00');
        $this->assertCount(1, $told = $this->told());
        $this->assertStringContainsString("Invoice #{$this->invoice->id} (client #{$this->client->id})", $told[0]);
        $this->assertStringContainsString('through Stripe', $told[0]);
        $this->assertStringContainsString('processing since 2026-11-10', $told[0]);
        $this->assertStringContainsString('10.00 USD', $told[0]);
        $this->assertStringContainsString('Add payment', $told[0]);
        $this->assertStringContainsString('delete the payment', $told[0]);

        $this->cronAt('2026-11-26 08:00:00');
        $this->cronAt('2026-11-30 08:00:00');
        $this->assertCount(1, $this->told(), 'not every day');

        $this->cronAt('2026-12-02 09:00:00');
        $this->assertCount(2, $this->told(), 'again a week later, while it is still processing');

        $this->assertSame(InvoiceTransactionStatus::Processing, $payment->fresh()->status, 'nothing automatic');
        $this->assertSame(Invoice::STATUS_UNPAID, $this->invoice->fresh()->status);
    }

    public function test_each_stale_payment_has_its_own_notice_and_a_settled_one_none(): void
    {
        $this->processing('pi_one');
        $other = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()]);
        $other->items()->create(['description' => 'Hosting', 'price' => '10.00', 'quantity' => 1]);
        ExtensionHelper::addProcessingPayment($other->id, 'Stripe', '10.00', null, 'pi_two', currency: 'USD');
        // A third that failed meanwhile, and a fourth that succeeded: no longer processing, never told.
        ExtensionHelper::addProcessingPayment($other->id, 'Stripe', '10.00', null, 'pi_failed', currency: 'USD');
        ExtensionHelper::addFailedPayment($other->id, 'Stripe', '10.00', null, 'pi_failed', currency: 'USD');

        $this->cronAt('2026-11-25 09:00:00');

        $this->assertCount(1, $this->told('pi_one'));
        $this->assertCount(1, $this->told('pi_two'));
        $this->assertSame([], $this->told('pi_failed'));
    }

    /** The money came: staff record it with the gateway's id (no gateway chosen), which completes the same payment. */
    public function test_staff_complete_a_processing_payment_with_its_transaction_id(): void
    {
        $payment = $this->processing();

        $recorded = (new RecordPaymentService)->handle($this->invoice->fresh(), null, '10.00', null, 'pi_never_settled', enteredByStaff: true);

        $this->assertSame($payment->id, $recorded?->id);
        $this->assertSame(InvoiceTransactionStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $this->invoice->fresh()->status);
        $this->assertSame(1, InvoiceTransaction::query()->where('invoice_id', $this->invoice->id)->count());
        $this->cronAt('2026-11-25 09:00:00');
        $this->assertSame([], $this->told());
    }

    /** It failed: staff delete it, the invoice can be paid again; the gateway's late success is still recorded. */
    public function test_staff_delete_a_processing_payment_and_a_late_success_is_still_recorded(): void
    {
        $payment = $this->processing();
        $this->assertFalse(SavedMethodCharge::openCheckout($this->invoice->fresh()), 'precondition: held');

        (new RecordPaymentService)->deleteTransaction($payment);

        $this->assertNull($payment->fresh());
        $this->assertTrue(SavedMethodCharge::openCheckout($this->invoice->fresh()), 'the invoice can be paid again');
        $this->assertSame(Invoice::STATUS_UNPAID, $this->invoice->fresh()->status);

        ExtensionHelper::addPayment($this->invoice->id, 'Stripe', '10.00', null, 'pi_never_settled', currency: 'USD');

        $late = InvoiceTransaction::query()->where('invoice_id', $this->invoice->id)->sole();
        $this->assertSame(InvoiceTransactionStatus::Succeeded, $late->status);
        $this->assertSame('10.00', number_format((float) $late->amount, 2, '.', ''));
        $this->assertSame(Invoice::STATUS_PAID, $this->invoice->fresh()->status);
    }

    /** A payment the gateway reported failed becomes succeeded when its success comes after all. */
    public function test_a_failed_payment_becomes_succeeded_on_a_late_success(): void
    {
        $payment = $this->processing();
        ExtensionHelper::addFailedPayment($this->invoice->id, 'Stripe', '10.00', null, 'pi_never_settled', currency: 'USD');
        $this->assertSame(InvoiceTransactionStatus::Failed, $payment->fresh()->status);

        ExtensionHelper::addPayment($this->invoice->id, 'Stripe', '10.00', null, 'pi_never_settled', currency: 'USD');

        $this->assertSame(InvoiceTransactionStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $this->invoice->fresh()->status);
    }

    private function stripeEvent(string $type, string $intent, mixed $mark)
    {
        $gateway = Gateway::query()->where('extension', 'Stripe')->sole();
        foreach (['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        (new Stripe(['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET]))->boot();
        Http::fake();
        $payload = (string) json_encode(['type' => $type, 'data' => ['object' => ['id' => $intent, 'object' => 'payment_intent', 'amount' => 1000,
            'currency' => 'usd', 'status' => 'canceled', 'metadata' => ['invoice_id' => (string) $this->invoice->id, 'mycs' => $mark]]]]);
        $time = time();

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1=" . hash_hmac('sha256', $time . '.' . $payload, self::SECRET),
        ], $payload);
    }

    public function test_stripe_cancelling_a_processing_intent_fails_its_payment_and_frees_the_invoice(): void
    {
        $payment = $this->processing('pi_cancelled');
        Carbon::setTestNow();

        $this->stripeEvent('payment_intent.canceled', 'pi_cancelled', GatewayReference::mark())->assertOk();

        $this->assertSame(InvoiceTransactionStatus::Failed, $payment->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $this->invoice->fresh()->status);
        $this->assertTrue(SavedMethodCharge::openCheckout($this->invoice->fresh()));
    }

    public function test_a_cancelled_intent_of_another_system_changes_nothing(): void
    {
        $payment = $this->processing('pi_cancelled');
        Carbon::setTestNow();

        $this->stripeEvent('payment_intent.canceled', 'pi_cancelled', 'whmcs')->assertOk();

        $this->assertSame(InvoiceTransactionStatus::Processing, $payment->fresh()->status);
    }

    public function test_a_cancelled_intent_never_undoes_a_succeeded_payment(): void
    {
        ExtensionHelper::addPayment($this->invoice->id, 'Stripe', '10.00', null, 'pi_paid', currency: 'USD');
        Carbon::setTestNow();

        $this->stripeEvent('payment_intent.canceled', 'pi_paid', GatewayReference::mark())->assertOk();

        $this->assertSame(InvoiceTransactionStatus::Succeeded, InvoiceTransaction::query()->where('transaction_id', 'pi_paid')->sole()->status);
        $this->assertSame(Invoice::STATUS_PAID, $this->invoice->fresh()->status);
    }
}
