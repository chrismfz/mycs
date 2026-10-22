<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Money\GatewayReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\Mollie\Mollie;
use MyCS\Extensions\Gateways\PayPal_IPN\PayPal_IPN;
use Tests\TestCase;

/** Adversarial review of #349: refunds and chargebacks at PayPal (IPN) and Mollie. */
class Adv349ReversalsTest extends TestCase
{
    use RefreshDatabase;

    private const IPN_VERIFY = 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
    }

    private function unpaidInvoice(): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->subDays(3)]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);

        return $invoice;
    }

    private function ipnGateway(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal_IPN'], ['name' => 'PayPal_IPN', 'type' => 'gateway']);
        foreach (['email' => 'biz@example.test', 'test_mode' => '1'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Http::fake([self::IPN_VERIFY => Http::response('VERIFIED')]);
        (new PayPal_IPN(['email' => 'biz@example.test', 'test_mode' => '1']))->boot();
    }

    private function ipn(array $fields)
    {
        $fields += ['receiver_email' => 'biz@example.test', 'mc_currency' => 'USD'];

        return $this->call('POST', '/extensions/paypal_ipn/notify', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($fields));
    }

    /**
     * F1: a refund IPN that reaches us before its payment's IPN (the Completed one failed and is retried later) waits,
     * and staff are told «applied by itself when its payment arrives: do not act by hand». With only the IPN gateway
     * installed its payment is recorded without a gateway row (addPayment looks up the extension 'PayPal'), so
     * applyWaitingRefunds never runs: the customer has the money back, the invoice stays paid, for ever.
     */
    public function test_an_ipn_refund_told_before_its_payment_is_applied_when_the_payment_arrives(): void
    {
        $this->ipnGateway();
        $invoice = $this->unpaidInvoice();

        $this->ipn(['payment_status' => 'Refunded', 'txn_id' => 'TXN-REF', 'parent_txn_id' => 'TXN-PAY', 'mc_gross' => '-40.00'])->assertOk();
        $this->ipn(['payment_status' => 'Completed', 'txn_id' => 'TXN-PAY', 'item_number' => (string) $invoice->id, 'mc_gross' => '100.00'])->assertOk();

        $payment = $invoice->transactions()->sole();
        $this->assertSame('100.00', (string) $payment->amount);
        $this->assertSame('40.00', (string) $payment->refunded_amount, 'the refund that waited for this payment was never applied');
    }

    private function mollie(array $payment, array $refunds = []): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Mollie'], ['name' => 'Mollie', 'type' => 'gateway']);
        $gateway->settings()->updateOrCreate(['key' => 'api_key'], ['value' => 'test_key']);
        $id = $payment['id'];
        Http::fake([
            "https://api.mollie.com/v2/payments/{$id}/refunds" => Http::response(['_embedded' => ['refunds' => $refunds]]),
            "https://api.mollie.com/v2/payments/{$id}/chargebacks" => Http::response(['_embedded' => ['chargebacks' => []]]),
            "https://api.mollie.com/v2/payments/{$id}" => Http::response($payment),
        ]);
        (new Mollie(['api_key' => 'test_key']))->boot();
    }

    private function molliePayment(string $id, Invoice $invoice, array $extra = []): array
    {
        return ['id' => $id, 'status' => 'paid', 'amount' => ['value' => '100.00', 'currency' => 'USD'],
            'metadata' => ['mycs' => GatewayReference::mark(), 'invoice_id' => $invoice->id]] + $extra;
    }

    /**
     * F2: a Mollie refund is recorded (and the customer told) while it is still «pending»; when Mollie then reports it
     * «failed» (or it is canceled), the money never left, but nothing happens: the failed status is skipped, and with
     * amountRefunded back to 0 the refunds are not even fetched. Stripe's path calls refundFailedAtGateway (staff told).
     */
    public function test_a_mollie_refund_recorded_while_pending_that_then_fails_is_told_to_staff(): void
    {
        $invoice = $this->unpaidInvoice();
        ExtensionHelper::addPayment($invoice->id, 'Mollie', 100, transactionId: 'tr_pf', currency: 'USD');
        $refund = ['id' => 're_pf', 'amount' => ['value' => '30.00', 'currency' => 'USD']];

        $this->mollie($this->molliePayment('tr_pf', $invoice, ['amountRefunded' => ['value' => '30.00', 'currency' => 'USD']]),
            refunds: [$refund + ['status' => 'pending']]);
        $this->post('/extensions/gateways/mollie/webhook', ['id' => 'tr_pf'])->assertOk();
        $this->assertSame('30.00', (string) $invoice->transactions()->sole()->refunded_amount);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->mollie($this->molliePayment('tr_pf', $invoice, ['amountRefunded' => ['value' => '0.00', 'currency' => 'USD'],
            '_links' => ['refunds' => ['href' => 'x']]]), refunds: [$refund + ['status' => 'failed']]);
        $this->post('/extensions/gateways/mollie/webhook', ['id' => 'tr_pf'])->assertOk();

        $this->assertTrue(EmailLog::query()->where('subject', 'like', '%refund failed%')->exists(),
            'a refund recorded as money given back, then failed at Mollie, is never told to staff');
    }
}
