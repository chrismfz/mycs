<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Money\GatewayReference;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use MyCS\Extensions\Gateways\PayPal_IPN\PayPal_IPN;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Sweep 2026-11-07, area «payments»: the verifier's failing tests for the finder's candidates. */
#[Group('adversarial')]
class SweepPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api-m.sandbox.paypal.com';

    private const IPN_VERIFY = 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        Carbon::setTestNow('2026-11-10 09:00:00');
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.credits_enabled' => true, 'settings.credits_auto_use' => false, 'settings.mass_pay_enabled' => true]);
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $price = '10.00', string $due = '2026-11-10'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function paypalGateway(): Gateway
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        foreach (['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1', 'test_mode' => '1'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return $gateway->fresh();
    }

    private function ipnGateway(string $email): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal_IPN'], ['name' => 'PayPal_IPN', 'type' => 'gateway', 'enabled' => true]);
        foreach (['email' => $email, 'test_mode' => '1'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Http::fake([self::IPN_VERIFY => Http::response('VERIFIED')]);
        (new PayPal_IPN(['email' => $email, 'test_mode' => '1']))->boot();
    }

    private function ipn(array $fields)
    {
        return $this->call('POST', '/extensions/paypal_ipn/notify', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($fields));
    }

    /**
     * PAYMENTS-1: a PENDING PayPal capture is recorded as processing; PayPal then denies it (PAYMENT.CAPTURE.DENIED, an
     * event the webhook subscribes to). The webhook answers «success» and does nothing: the payment stays processing and
     * holds the invoice from every way of paying, and nobody is told.
     */
    public function test_payments_1_a_denied_pending_capture_does_not_hold_the_invoice_for_good(): void
    {
        $this->paypalGateway();
        Http::fake([
            self::API . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API . '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice->id, 'PayPal', '10.00', null, 'CAP-PENDING-1', InvoiceTransactionStatus::Processing, currency: 'USD');
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()), 'precondition: the processing capture holds the invoice');

        $this->postJson('/extensions/paypal/webhook', ['id' => 'WH-EV-DENIED-1', 'event_type' => 'PAYMENT.CAPTURE.DENIED',
            'resource' => ['id' => 'CAP-PENDING-1', 'status' => 'DENIED', 'amount' => ['value' => '10.00', 'currency_code' => 'USD'],
                'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-1']]]])->assertOk();

        $payment = $invoice->transactions()->sole();
        $this->assertNotSame(InvoiceTransactionStatus::Processing, $payment->fresh()->status, 'a denied capture is no payment on its way');
        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()), 'the invoice is payable again once PayPal denied the capture');
    }

    /**
     * PAYMENTS-2: the PayPal capture of a Mass Pay group (custom_id = the group) checks only its lead for a saved-method
     * charge of unsettled outcome. A charge of the group's other invoice B, alone, of unknown outcome, does not stop it:
     * the group's total is captured and B is paid twice.
     */
    public function test_payments_2_group_capture_is_held_by_an_unsettled_charge_of_another_group_invoice(): void
    {
        $paypal = $this->paypalGateway();
        $a = $this->invoice('10.00', '2026-11-08');
        $b = $this->invoice('10.00', '2026-11-10');
        $group = (new MassPay)->open($this->user->client(), [$a->id, $b->id], $this->user);
        $this->assertSame($a->id, (int) $group->invoice_id, 'precondition: A leads');

        // Meanwhile B alone is charged with a saved card; its answer was lost (outcome unknown).
        $card = Gateway::query()->create(['extension' => 'SweepCard', 'name' => 'SweepCard', 'type' => 'gateway', 'enabled' => true]);
        $agreement = BillingAgreement::query()->create(['client_id' => $this->user->client()->id, 'gateway_id' => $card->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_sweep', 'type' => 'card']);
        ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $b->id, 'client_id' => $this->user->client()->id,
            'billing_agreement_id' => $agreement->id, 'amount' => 10, 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON,
            'status' => ChargeAttempt::STATUS_UNKNOWN, 'started_at' => now()->subMinutes(30), 'sent_at' => now()->subMinutes(30)]);
        $this->assertTrue(SavedMethodCharge::onItsWay($b->fresh()), 'precondition: B is held');

        PayPal::rememberOrder('ORDER-G', $a);
        $order = ['id' => 'ORDER-G', 'status' => 'APPROVED', 'purchase_units' => [['invoice_id' => GatewayReference::paypalInvoiceId($a),
            'custom_id' => $group->ulid, 'amount' => ['currency_code' => 'USD', 'value' => '20.00']]]];
        Http::fake([
            self::API . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API . '/v2/checkout/orders/ORDER-G/capture' => Http::response(['id' => 'ORDER-G', 'status' => 'COMPLETED',
                'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-G', 'status' => 'COMPLETED',
                    'amount' => ['value' => '20.00', 'currency_code' => 'USD']]]]]]], 201),
            self::API . '/v2/checkout/orders/ORDER-G' => Http::response($order),
        ]);

        try {
            ExtensionHelper::getExtension('gateway', 'PayPal', $paypal->settings)->capture($a->fresh(), 'ORDER-G');
        } catch (HttpException) {
        }

        Http::assertNotSent(fn (Request $request) => $request->url() === self::API . '/v2/checkout/orders/ORDER-G/capture');
        $this->assertSame(0, $b->fresh()->transactions()->count(), 'B, whose card charge may have paid it, is not paid again from the group');
    }

    /**
     * PAYMENTS-3: the configured email is a secondary email of the account (sent as «business»); PayPal's IPN names the
     * account's primary email as receiver_email. The verified Completed IPN is dropped: money taken, nothing recorded,
     * staff not told.
     */
    public function test_payments_3_a_verified_ipn_to_the_configured_business_email_is_not_dropped(): void
    {
        $this->ipnGateway('biz2@example.test');
        $invoice = $this->invoice();

        $this->ipn(['payment_status' => 'Completed', 'txn_id' => 'TXN-SEC-1', 'item_number' => (string) $invoice->id, 'mc_gross' => '10.00',
            'mc_currency' => 'USD', 'receiver_email' => 'primary@example.test', 'business' => 'biz2@example.test'])->assertOk();

        $staff = fn (Mailable $mail) => $mail->hasTo('staff@example.test');
        $told = Mail::sent($staff)->isNotEmpty() || Mail::queued($staff)->isNotEmpty();
        $this->assertTrue($invoice->transactions()->exists() || $told, 'the payment is recorded, or at least staff are told it was not');
    }

    /**
     * PAYMENTS-4: with only PayPal_IPN installed (it offers Mass Pay), the IPN's payment is recorded under the extension
     * «PayPal», which has no row: no gateway, so it is not a checkout's money and the group is never spread.
     */
    public function test_payments_4_an_ipn_mass_pay_payment_pays_the_group_with_only_the_ipn_gateway(): void
    {
        $this->ipnGateway('biz@example.test');
        $a = $this->invoice('10.00', '2026-11-08');
        $b = $this->invoice('10.00', '2026-11-10');
        $group = (new MassPay)->open($this->user->client(), [$a->id, $b->id], $this->user);

        $this->ipn(['payment_status' => 'Completed', 'txn_id' => 'TXN-GRP-1', 'item_number' => (string) $a->id, 'mc_gross' => '20.00',
            'mc_currency' => 'USD', 'receiver_email' => 'biz@example.test', 'custom' => $group->ulid])->assertOk();

        $payment = $a->transactions()->where('transaction_id', 'TXN-GRP-1')->sole();
        $this->assertSame(Invoice::STATUS_PAID, $b->fresh()->status, 'the group\'s other invoice is paid from the payment');
        $this->assertNotNull($payment->gateway_id, 'the IPN payment is recorded under its gateway');
    }
}
