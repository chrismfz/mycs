<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\PayPal_IPN\PayPal_IPN;
use Tests\TestCase;

/**
 * Independent review of the sweep-2 fixes for flow f-gateway-webhooks. Each test fails on the fix branch and states the
 * expected behaviour.
 */
class Rev2FGatewayWebhooksTest extends TestCase
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
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.credits_enabled' => true,
            'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
    }

    private function invoice(string $price): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR',
            'due_at' => now()->addDays(7)->toDateString()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    /**
     * REV2-FGW-1 (F-GATEWAY-WEBHOOKS-1 not fully fixed): two disputes overlap. D2 is opened while D1 is still open, then
     * D1 is withdrawn (no chargeback standing: a won-early marker), then PayPal takes the money back for D2. D2 is open
     * and not closed, exactly the ambiguity the fix means to record and hand to staff, but laterOpenDispute only looks
     * at disputes opened after the marker (id > early->id), so D2 is missed: nothing is recorded and the invoice stays
     * paid although the money is gone.
     */
    public function test_reversal_of_a_dispute_opened_before_the_other_was_withdrawn_is_recorded(): void
    {
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        $invoice = $this->invoice('50.00');
        ExtensionHelper::addPayment($invoice->id, 'PayPal', 50.00, transactionId: 'CAP1', currency: 'EUR');

        ExtensionHelper::chargebackOpened('PayPal', 'CAP1', 'D1', 50.00, 'EUR');
        ExtensionHelper::chargebackOpened('PayPal', 'CAP1', 'D2', 50.00, 'EUR');
        ExtensionHelper::chargebackReversedAtGateway('PayPal', 'CAP1', null, 50.00, 'EUR', 'D1'); // D1 withdrawn
        ExtensionHelper::chargebackAtGateway('PayPal', 'CAP1', 'REV-2', 50.00, 'EUR'); // D2's money taken

        $payment = $invoice->transactions()->sole();
        $this->assertSame('50.00', (string) $payment->refunded_amount, 'D2 is still open, yet its reversal was taken as D1\'s late one');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    /**
     * REV2-FGW-2 (regression of the F-GATEWAY-WEBHOOKS-2 fix): with only PayPal_IPN installed the payment is now recorded
     * under PayPal_IPN. When the PayPal gateway row is added later (cut-over, EnableImportedGateways), a replay of the
     * same IPN (PayPal resends IPNs, and sends duplicates) is recorded under PayPal; RecordPaymentService's replay check
     * is per gateway (gateway_id = PayPal's, or null), so the PayPal_IPN row is not seen and the money is recorded twice.
     * Before the fix the payment had no gateway and the null row matched every gateway's replay.
     */
    public function test_ipn_replay_after_the_paypal_gateway_is_added_is_not_recorded_twice(): void
    {
        $gateway = Gateway::query()->create(['extension' => 'PayPal_IPN', 'name' => 'PayPal_IPN', 'type' => 'gateway', 'enabled' => true]);
        foreach (['email' => 'biz@example.test', 'test_mode' => '1'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Http::fake([self::IPN_VERIFY => Http::response('VERIFIED')]);
        (new PayPal_IPN(['email' => 'biz@example.test', 'test_mode' => '1']))->boot();
        $invoice = $this->invoice('50.00');
        $fields = ['payment_status' => 'Completed', 'txn_id' => 'TXN-DUP', 'item_number' => (string) $invoice->id, 'mc_gross' => '50.00',
            'mc_currency' => 'EUR', 'receiver_email' => 'biz@example.test'];
        $ipn = fn () => $this->call('POST', '/extensions/paypal_ipn/notify', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query($fields))->assertOk();

        $ipn();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        Gateway::query()->create(['extension' => 'PayPal', 'name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        $ipn(); // the same IPN, resent

        $credit = (float) (Credit::query()->where('client_id', $this->user->client()->id)->where('currency_code', 'EUR')->value('amount') ?? 0);
        $this->assertSame([1, 0.0], [$invoice->transactions()->where('transaction_id', 'TXN-DUP')->count(), $credit],
            'the replayed IPN was recorded a second time under PayPal: 50.00 of credit the customer never paid');
    }
}
