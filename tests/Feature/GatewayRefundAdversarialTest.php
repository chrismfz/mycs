<?php

namespace Tests\Feature;

use App\Enums\MoneyEventKind;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\Notification;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * Adversarial review of PR 2c-2a (gateway refunds by webhook). Tests prefixed test_bug_* FAILED on 4073c3c (findings
 * F1-F5, fixed in the PR); tests prefixed test_guard_* passed then and document behaviour that holds.
 */
class GatewayRefundAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    private const PAYPAL_API = 'https://api-m.sandbox.paypal.com';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.credits_enabled' => true]);
        $this->user = User::factory()->create();
    }

    // ------------------------------------------------------------------ helpers

    private function stripe(array $fakes = []): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        foreach (['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        (new Stripe(['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET]))->boot();
        Http::fake($fakes);
    }

    private function paypal(array $fakes): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        foreach (['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1', 'test_mode' => '1'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $all = [self::PAYPAL_API . '/v1/oauth2/token' => Http::response(['access_token' => 'token'])];
        foreach ($fakes as $path => $response) {
            $all[self::PAYPAL_API . $path] = $response;
        }
        Http::fake($all);
        /** @var PayPal $paypal */
        $paypal = ExtensionHelper::getExtension('gateway', 'PayPal', $gateway->fresh()->settings);
        $paypal->boot();
    }

    private function openInvoice(float $price = 100): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function paidInvoice(string $gateway, string $transactionId, float $price = 100): Invoice
    {
        $invoice = $this->openInvoice($price);
        ExtensionHelper::addPayment($invoice->id, $gateway, $price, transactionId: $transactionId, currency: 'usd');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        return $invoice->fresh();
    }

    private function stripeWebhook(array $event, bool $signed = true)
    {
        $payload = json_encode($event);
        $time = time();
        $signature = $signed ? hash_hmac('sha256', $time . '.' . $payload, self::SECRET) : str_repeat('0', 64);

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}",
        ], $payload);
    }

    private function stripeRefund(string $id, string $paymentIntent, int $cents, string $status = 'succeeded', string $type = 'refund.created'): array
    {
        return ['type' => $type, 'data' => ['object' => [
            'id' => $id, 'object' => 'refund', 'amount' => $cents, 'currency' => 'usd', 'payment_intent' => $paymentIntent, 'status' => $status,
        ]]];
    }

    private function captureRefunded(string $refundId, string $captureId, string $value): array
    {
        return ['event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => [
            'id' => $refundId, 'status' => 'COMPLETED', 'amount' => ['value' => $value, 'currency_code' => 'USD'],
            'links' => [['rel' => 'up', 'href' => self::PAYPAL_API . '/v2/payments/captures/' . $captureId]],
        ]];
    }

    private function refundNotices(): int
    {
        return Notification::query()->where('user_id', $this->user->id)->where('title', 'like', 'Refund for invoice%')->count();
    }

    private function staffMails(): int
    {
        return EmailLog::query()->where('subject', 'Gateway refund needs staff attention')->count();
    }

    private function assertMoneyCheckClean(): void
    {
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }

    private function service(): Service
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 10.00]);

        return Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => now()->addDays(3), 'currency_code' => 'USD', 'price' => 10.00,
            'subscription_id' => 'I-SUB',
        ]);
    }

    private function invoiceFor(Service $service): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create([
            'reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10.00, 'period_start' => now()->addDays(3), 'period_end' => now()->addDays(3)->addMonth(),
        ]);

        return $invoice->fresh();
    }

    // ------------------------------------------------------------------ findings (FAIL)

    /**
     * F1 (high). Staff refund in Stripe's dashboard and record it with «by hand» (the only way before this PR, and
     * WHMCS's «Record refund only»); the dashboard refund's webhook then records the same money a second time: the
     * payment shows 60 refunded for 30 given back, the customer gets two refund emails, and the journal has two refunds.
     */
    public function test_bug_f1_dashboard_refund_recorded_by_hand_is_recorded_again_by_its_webhook(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_hand');
        $payment = $invoice->transactions()->sole();

        ExtensionHelper::refundLocally($payment, 30.00); // staff record the dashboard refund by hand
        $this->stripeWebhook($this->stripeRefund('re_hand', 'pi_hand', 3000))->assertOk(); // the same refund, by webhook

        $this->assertSame('30.00', (string) $payment->fresh()->refunded_amount, 'one 30.00 refund recorded twice');
        $this->assertSame(1, MoneyEvent::query()->where('kind', MoneyEventKind::Refund->value)->count());
        $this->assertSame(1, $this->refundNotices(), 'the customer is told of one refund twice');
    }

    /**
     * F2 (medium). Stripe does not deliver events in order and retries failed ones for days. A refund.failed delivered
     * before the refund.created (status pending) snapshot finds nothing to flag (refundFailedAtGateway returns
     * silently, leaving no trace); the late refund.created then records a refund that failed: money recorded as given
     * back that never left, and staff are never told.
     */
    public function test_bug_f2_late_pending_snapshot_after_refund_failed_records_a_failed_refund(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_order');

        $this->stripeWebhook($this->stripeRefund('re_order', 'pi_order', 2500, 'failed', 'refund.failed'))->assertOk();
        $this->stripeWebhook($this->stripeRefund('re_order', 'pi_order', 2500, 'pending', 'refund.created'))->assertOk();

        $recorded = (string) $invoice->transactions()->sole()->refunded_amount;
        $flagged = EmailLog::query()->where('subject', 'like', 'Gateway refund%')->count();
        $this->assertTrue($recorded === '0.00' || $flagged > 0,
            "a refund Stripe reported failed is recorded as {$recorded} refunded, and staff were told {$flagged} times");
    }

    /**
     * F3 (medium). A refund webhook that arrives before its payment is recorded (the payment's webhook failed and is
     * being retried, or arrives later out of order) is answered 200 «refund_unknown_payment», so the gateway never sends
     * it again; when the payment then arrives the invoice is paid in full and the refund is lost. Staff are not mailed.
     */
    public function test_bug_f3_refund_webhook_before_its_payment_is_lost(): void
    {
        $this->stripe();
        $invoice = $this->openInvoice();

        $this->stripeWebhook($this->stripeRefund('re_early', 'pi_late', 10000))->assertOk(); // no retry from Stripe
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100, transactionId: 'pi_late', currency: 'usd'); // the late payment

        $payment = $invoice->transactions()->sole();
        $this->assertTrue((string) $payment->refunded_amount === '100.00' || $this->staffMails() > 0,
            'a 100.00 refund is lost: the invoice stays ' . $invoice->fresh()->status . ', refunded ' . $payment->refunded_amount . ', staff not told');
    }

    /**
     * F3b (low/medium). A refund on a payment still processing at MyCS (its succeeded event not in yet) is stored with
     * recorded_amount 0: its id is burnt, so the refund is never recorded once the payment succeeds (staff are mailed
     * once, with a text that blames credit or a manual refund).
     */
    public function test_bug_f3b_refund_on_a_processing_payment_is_never_recorded_once_it_succeeds(): void
    {
        $this->stripe();
        $invoice = $this->openInvoice();
        ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 100, transactionId: 'pi_proc', currency: 'usd');

        $this->stripeWebhook($this->stripeRefund('re_proc', 'pi_proc', 4000))->assertOk();
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100, transactionId: 'pi_proc', currency: 'usd');
        $this->stripeWebhook($this->stripeRefund('re_proc', 'pi_proc', 4000, type: 'refund.updated'))->assertOk(); // Stripe tells it again

        $this->assertSame('40.00', (string) $invoice->transactions()->sole()->refunded_amount, 'the 40.00 refund is never recorded');
    }

    /**
     * F4 (medium). PayPal refund() keys its PayPal-Request-Id on refunded_amount so that a retry after a timeout gets
     * PayPal's first answer (the Log::critical says «a retry reuses the request id»). The webhook now records that
     * first refund, refunded_amount changes, and the staff retry sends a NEW request id: PayPal refunds a second time.
     */
    public function test_bug_f4_paypal_retry_after_timeout_gets_a_new_request_id_once_the_webhook_recorded_the_first(): void
    {
        $ids = [];
        $calls = 0;
        $this->paypal([
            '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
            '/v2/payments/captures/CAP-T/refund' => function (Request $request) use (&$ids, &$calls) {
                $ids[] = $request->header('PayPal-Request-Id')[0] ?? null;
                if ($calls++ === 0) {
                    throw new ConnectionException('timed out'); // PayPal refunded, MyCS never heard back
                }

                return Http::response(['id' => 'REF-T' . $calls, 'status' => 'COMPLETED']);
            },
        ]);
        $invoice = $this->paidInvoice('PayPal', 'CAP-T');
        $payment = $invoice->transactions()->sole();

        try {
            ExtensionHelper::refund($payment, 10.00);
            $this->fail('the first refund call times out');
        } catch (ConnectionException) {
        }
        $this->postJson('/extensions/paypal/webhook', $this->captureRefunded('REF-T1', 'CAP-T', '10.00'))->assertOk();
        ExtensionHelper::refund(InvoiceTransaction::find($payment->id), 10.00); // staff retry, as the log says

        $this->assertCount(2, $ids);
        $this->assertSame($ids[0], $ids[1], 'the retry is a new PayPal request: the customer is refunded twice');
    }

    /**
     * F5 (low/medium). A payment staff entered by hand without a gateway but with the provider's id (the webhook failed;
     * docs/payments.md: «a webhook finds a manual entry without gateway») is matched by payment webhooks but not by refund
     * webhooks: a dashboard refund of it is ignored as «refund_unknown_payment», silently (log only).
     */
    public function test_bug_f5_refund_of_a_staff_entered_payment_with_the_provider_id_is_silently_ignored(): void
    {
        $this->stripe();
        $invoice = $this->openInvoice();
        (new RecordPaymentService)->recordByStaff($invoice, null, 100, 'pi_staff');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $this->stripeWebhook($this->stripeRefund('re_staff', 'pi_staff', 2000))->assertOk();

        $this->assertTrue((string) $invoice->transactions()->sole()->refunded_amount === '20.00' || $this->staffMails() > 0,
            'a 20.00 Stripe refund of a payment we hold is ignored with no one told');
    }

    // ------------------------------------------------------------------ guarded behaviour (PASS)

    public function test_guard_unsigned_stripe_and_unverified_paypal_refunds_change_nothing(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_forged');
        $this->stripeWebhook($this->stripeRefund('re_forged', 'pi_forged', 10000), signed: false)->assertStatus(400);

        $this->paypal(['/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE'])]);
        $paypalInvoice = $this->paidInvoice('PayPal', 'CAP-F');
        $this->postJson('/extensions/paypal/webhook', $this->captureRefunded('REF-F', 'CAP-F', '100.00'))->assertStatus(400);

        $this->assertSame('0.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame('0.00', (string) $paypalInvoice->transactions()->sole()->refunded_amount);
        $this->assertSame(0, GatewayRefund::query()->count());
        $this->assertSame(0, $this->refundNotices());
    }

    public function test_guard_two_refund_ids_over_the_payment_are_capped_and_money_check_stays_clean(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_two');

        $this->stripeWebhook($this->stripeRefund('re_a', 'pi_two', 7000))->assertOk();
        $this->stripeWebhook($this->stripeRefund('re_b', 'pi_two', 7000))->assertOk();
        $this->stripeWebhook($this->stripeRefund('re_b', 'pi_two', 7000, type: 'refund.updated'))->assertOk();

        $payment = $invoice->transactions()->sole();
        $this->assertSame('100.00', (string) $payment->refunded_amount);
        $this->assertSame(['70.00', '30.00'], GatewayRefund::query()->orderBy('id')->pluck('recorded_amount')->map(fn ($v) => (string) $v)->all());
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        $this->assertSame(1, $this->staffMails());
        $this->assertSame(2, $this->refundNotices());
        $this->assertMoneyCheckClean();
    }

    public function test_guard_mycs_refund_rolled_back_after_the_gateway_sends_no_email_and_the_webhook_records_it_once(): void
    {
        $this->stripe(['https://api.stripe.com/v1/refunds' => Http::response(['id' => 're_rb', 'object' => 'refund', 'status' => 'succeeded'])]);
        $invoice = $this->paidInvoice('Stripe', 'pi_rb');
        $payment = $invoice->transactions()->sole();

        GatewayRefund::creating(function (GatewayRefund $refund) {
            if ($refund->origin === GatewayRefund::ORIGIN_MYCS) {
                throw new \RuntimeException('database hiccup after the gateway refunded');
            }
        });
        $thrown = null;
        try {
            ExtensionHelper::refund($payment, 40.00);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(\RuntimeException::class, $thrown, 'the recording fails');
        $this->assertSame('0.00', (string) $payment->fresh()->refunded_amount);
        $this->assertSame(0, $this->refundNotices(), 'no email for a rolled-back refund');

        $this->stripeWebhook($this->stripeRefund('re_rb', 'pi_rb', 4000))->assertOk();
        $this->stripeWebhook($this->stripeRefund('re_rb', 'pi_rb', 4000, type: 'refund.updated'))->assertOk();

        $this->assertSame('40.00', (string) $payment->fresh()->refunded_amount);
        $this->assertSame(1, $this->refundNotices());
        $this->assertMoneyCheckClean();
    }

    public function test_guard_webhook_refund_that_fails_to_commit_sends_nothing_and_its_retry_records_it(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_fail');
        $fail = true;
        GatewayRefund::creating(function () use (&$fail) {
            if ($fail) {
                throw new \RuntimeException('boom');
            }
        });

        $this->stripeWebhook($this->stripeRefund('re_fail', 'pi_fail', 1500))->assertStatus(500); // Stripe retries
        $this->assertSame('0.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame(0, $this->refundNotices());

        $fail = false;
        $this->stripeWebhook($this->stripeRefund('re_fail', 'pi_fail', 1500))->assertOk();
        $this->assertSame('15.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame(1, $this->refundNotices());
        $this->assertMoneyCheckClean();
    }

    public function test_guard_paypal_subscription_sale_split_over_invoices_records_only_its_own_share_and_tells_staff(): void
    {
        $this->paypal(['/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'])]);
        $invoices = collect([$this->service(), $this->service(), $this->service()])->map(fn (Service $s) => $this->invoiceFor($s));
        $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.SALE.COMPLETED', 'resource' => [
            'id' => 'SALE-3', 'billing_agreement_id' => 'I-SUB', 'amount' => ['total' => '30.00', 'currency' => 'USD'],
        ]])->assertOk();
        $invoices->each(fn (Invoice $i) => $this->assertSame(Invoice::STATUS_PAID, $i->fresh()->status));

        $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.SALE.REFUNDED', 'resource' => [
            'id' => 'SREF-3', 'sale_id' => 'SALE-3', 'state' => 'completed', 'amount' => ['total' => '-30.00', 'currency' => 'USD'],
        ]])->assertOk();

        $sale = InvoiceTransaction::query()->where('transaction_id', 'SALE-3')->sole();
        $this->assertSame(['10.00', '20.00'], [(string) $sale->refunded_amount, (string) $sale->credited_amount]);
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoices[0]->fresh()->status);
        $this->assertSame([Invoice::STATUS_PAID, Invoice::STATUS_PAID], [$invoices[1]->fresh()->status, $invoices[2]->fresh()->status],
            'the other services stay paid by the sale\'s credit: 20.00 of the refund is staff\'s');
        $this->assertSame(1, $this->staffMails());
        $this->assertMoneyCheckClean();
    }

    public function test_guard_credit_purchase_partly_spent_takes_back_only_what_is_left_over_two_refund_ids(): void
    {
        $this->stripe();
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => 50]);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 50, transactionId: 'pi_dep', currency: 'usd');
        $other = $this->openInvoice(30);
        (new RecordPaymentService)->payWithCredit($other);
        $this->assertEquals(20, (float) Credit::query()->where('client_id', $this->user->client()->id)->value('amount'));

        $this->stripeWebhook($this->stripeRefund('re_d1', 'pi_dep', 1500))->assertOk();
        $this->stripeWebhook($this->stripeRefund('re_d2', 'pi_dep', 3500))->assertOk();
        $this->stripeWebhook($this->stripeRefund('re_d2', 'pi_dep', 3500, type: 'refund.updated'))->assertOk();

        $this->assertEquals(0, (float) Credit::query()->where('client_id', $this->user->client()->id)->value('amount'), 'credit taken once, never negative');
        $this->assertSame('50.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame(1, $this->staffMails());
        $this->assertMoneyCheckClean();
    }

    public function test_guard_full_refund_of_a_partial_payment_leaves_the_invoice_open_and_consistent(): void
    {
        $this->stripe();
        $invoice = $this->openInvoice();
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 40, transactionId: 'pi_part', currency: 'usd');

        $this->stripeWebhook($this->stripeRefund('re_part', 'pi_part', 4000))->assertOk();

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertEquals(100, (float) $invoice->fresh()->remaining);
        $this->assertMoneyCheckClean();
    }

    public function test_guard_mycs_paypal_refund_and_its_webhook_record_once(): void
    {
        $this->paypal([
            '/v2/payments/captures/CAP-O/refund' => Http::response(['id' => 'REF-O', 'status' => 'COMPLETED']),
            '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);
        $invoice = $this->paidInvoice('PayPal', 'CAP-O');
        $payment = $invoice->transactions()->sole();

        ExtensionHelper::refund($payment, 100.00);
        $this->postJson('/extensions/paypal/webhook', $this->captureRefunded('REF-O', 'CAP-O', '100.00'))->assertOk();

        $this->assertSame('100.00', (string) $payment->fresh()->refunded_amount);
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        $this->assertSame(1, $this->refundNotices());
        $this->assertSame(0, $this->staffMails());
        $this->assertMoneyCheckClean();
    }
}
