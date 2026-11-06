<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Independent review of fix/adv-area-4: failing tests for what the fixes still get wrong. */
class Review4Test extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_review_4';

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        MailFacade::fake();
        Http::preventStrayRequests();
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $this->clientId = User::factory()->create()->client()->id;
    }

    private function invoice(string $price): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->clientId, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->toDateString()]);
        $invoice->items()->create(['description' => 'Line', 'price' => $price, 'quantity' => 1]);

        return $invoice;
    }

    /**
     * R4-1. A WHMCS-era legacy PayPal subscription pays after the cut-over: the controller tells staff to record it by
     * hand, and they do (gateway PayPal, its txn id). The customer then charges it back: its Reversed IPN (parent_txn_id =
     * that payment) is taken as «MyCS's own payment, its webhook records it» because the payment has the PayPal gateway
     * and no import event. PayPal's REST webhook is not sent for a classic Standard sale, so nothing records it and staff
     * are never told the money went back.
     */
    public function test_r4_1_reversal_of_a_legacy_payment_staff_recorded_by_hand_is_still_told_to_staff(): void
    {
        config(['settings.paypal_legacy_receivers' => 'billing@example.test']);
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('VERIFIED')]);
        DB::table('extensions')->insert(['name' => 'PayPal', 'extension' => 'PayPal', 'type' => 'gateway', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $invoice = $this->invoice('12.40');
        (new RecordPaymentService)->handle($invoice, Gateway::query()->where('extension', 'PayPal')->firstOrFail(), '12.40', null, '8AB12345CD678901E', enteredByStaff: true);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'precondition: recorded by hand');

        $this->call('POST', '/modules/gateways/callback/paypal.php', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query([
            'payment_status' => 'Reversed', 'reason_code' => 'chargeback', 'txn_id' => '3RV98765ZY432109X', 'parent_txn_id' => '8AB12345CD678901E',
            'subscr_id' => 'I-ABCDEF123456', 'mc_gross' => '-12.40', 'mc_currency' => 'EUR', 'receiver_email' => 'billing@example.test']))->assertOk();

        $this->assertSame(1, EmailLog::query()->where('subject', 'PayPal refund or reversal not recorded')->count(),
            'a chargeback of a payment staff recorded by hand is silenced as recorded by MyCS\'s webhook');
    }

    /**
     * R4-2 (low). A SEPA payment (payment_intent.processing recorded it as processing): its charge.updated with the
     * balance transaction can come before payment_intent.succeeded is processed; feePayment now asks for a succeeded
     * payment, so the fee is dropped (Stripe sends no second charge.updated for it). Before the fix it was written.
     */
    public function test_r4_2_stripe_fee_of_a_processing_payment_is_not_lost(): void
    {
        $gateway = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $gateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_test_r4']);
        $gateway->settings()->create(['key' => 'stripe_webhook_secret', 'value' => self::SECRET]);
        Http::fake(['https://api.stripe.com/v1/balance_transactions/*' => Http::response(['id' => 'txn_sepa', 'fee' => 35])]);
        $invoice = $this->invoice('50.00');
        $payment = ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 50, null, 'pi_sepa_1', currency: 'EUR');

        $time = time();
        $payload = (string) json_encode(['id' => 'evt_r4', 'type' => 'charge.updated', 'data' => ['object' => ['id' => 'ch_sepa_1', 'object' => 'charge',
            'payment_intent' => 'pi_sepa_1', 'balance_transaction' => 'txn_sepa', 'amount' => 5000, 'currency' => 'eur']]]);
        $this->call('POST', '/extensions/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, self::SECRET)], $payload)->assertOk();

        $this->assertSame('0.35', number_format((float) $payment->fresh()->fee, 2, '.', ''), 'the fee of a processing payment is dropped');
    }
}
