<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\PaymentGroup;
use App\Models\User;
use App\Services\Invoice\MassPay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\PayPal_IPN\PayPal_IPN;
use Tests\TestCase;

/**
 * Adversarial verification, flow f-gateway-webhooks (sweep 2). Each test fails on main and states the expected
 * behaviour.
 */
class Sweep2FGatewayWebhooksTest extends TestCase
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

    private function invoice(string $price, int $dueInDays = 7): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR',
            'due_at' => now()->addDays($dueInDays)->toDateString()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    /**
     * F-GATEWAY-WEBHOOKS-1: dispute D1 is withdrawn with no money moved (a won-early marker is kept). Later the buyer's
     * bank opens a new dispute D2 and PayPal takes the money back for it. The reversal is matched to D1's marker by
     * amount alone, so nothing is recorded: the invoice stays paid although the money is gone.
     */
    public function test_reversal_of_a_later_dispute_is_recorded_after_an_earlier_one_was_withdrawn(): void
    {
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        $invoice = $this->invoice('50.00');
        ExtensionHelper::addPayment($invoice->id, 'PayPal', 50.00, transactionId: 'CAP1', currency: 'EUR');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        // D1: opened, then CUSTOMER.DISPUTE.RESOLVED with CANCELED_BY_BUYER; no PAYMENT.CAPTURE.REVERSED before or after.
        ExtensionHelper::chargebackOpened('PayPal', 'CAP1', 'D1', 50.00, 'EUR');
        ExtensionHelper::chargebackReversedAtGateway('PayPal', 'CAP1', null, 50.00, 'EUR', 'D1');

        // Weeks later: a new dispute D2 (the bank's chargeback), and the money taken back for it.
        ExtensionHelper::chargebackOpened('PayPal', 'CAP1', 'D2', 50.00, 'EUR');
        ExtensionHelper::chargebackAtGateway('PayPal', 'CAP1', 'REV-2', 50.00, 'EUR');

        $payment = $invoice->transactions()->sole();
        $this->assertSame('50.00', (string) $payment->refunded_amount, 'D2\'s reversal was taken as D1\'s, which never moved money');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    /**
     * F-GATEWAY-WEBHOOKS-2: with only the PayPal_IPN gateway installed, a Mass Pay paid by IPN is recorded with no gateway
     * (addPayment looks up the extension 'PayPal'), so the group is never spread: the second invoice stays unpaid and the
     * money sits as credit.
     */
    public function test_ipn_mass_pay_with_only_the_ipn_gateway_pays_the_whole_group(): void
    {
        $gateway = Gateway::query()->create(['extension' => 'PayPal_IPN', 'name' => 'PayPal_IPN', 'type' => 'gateway', 'enabled' => true]);
        foreach (['email' => 'biz@example.test', 'test_mode' => '1'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Http::fake([self::IPN_VERIFY => Http::response('VERIFIED')]);
        (new PayPal_IPN(['email' => 'biz@example.test', 'test_mode' => '1']))->boot();

        $a = $this->invoice('30.00', 5);
        $b = $this->invoice('20.00', 9);
        $group = (new MassPay)->open($this->user->client(), [$a->id, $b->id], $this->user);
        $this->assertSame($a->id, (int) $group->invoice_id);

        $fields = ['payment_status' => 'Completed', 'txn_id' => 'TXN-MP', 'item_number' => (string) $a->id, 'mc_gross' => '50.00',
            'mc_currency' => 'EUR', 'custom' => $group->ulid, 'receiver_email' => 'biz@example.test'];
        $this->call('POST', '/extensions/paypal_ipn/notify', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($fields))
            ->assertOk();

        $credit = (float) (Credit::query()->where('client_id', $this->user->client()->id)->where('currency_code', 'EUR')->value('amount') ?? 0);
        $this->assertSame(Invoice::STATUS_PAID, $a->fresh()->status);
        $this->assertSame([Invoice::STATUS_PAID, PaymentGroup::STATUS_PAID, 0.0], [$b->fresh()->status, $group->fresh()->status, $credit],
            'the IPN payment has no gateway, so the Mass Pay is not spread and B\'s share becomes credit');
        $this->assertNotNull($a->transactions()->sole()->gateway_id, 'recorded as if staff had entered it (MoneyTrail source «manual»)');
    }

    /** F-GATEWAY-WEBHOOKS-1, the fix: staff are told the reversal might be the won dispute's late one, to undo by hand. */
    public function test_a_reversal_taken_as_the_later_dispute_s_tells_staff(): void
    {
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        $invoice = $this->invoice('50.00');
        ExtensionHelper::addPayment($invoice->id, 'PayPal', 50.00, transactionId: 'CAP1', currency: 'EUR');
        ExtensionHelper::chargebackOpened('PayPal', 'CAP1', 'D1', 50.00, 'EUR');
        ExtensionHelper::chargebackReversedAtGateway('PayPal', 'CAP1', null, 50.00, 'EUR', 'D1');
        ExtensionHelper::chargebackOpened('PayPal', 'CAP1', 'D2', 50.00, 'EUR');

        $this->assertTrue(ExtensionHelper::chargebackAtGateway('PayPal', 'CAP1', 'REV-2', 50.00, 'EUR'));
        $this->assertTrue(EmailLog::query()->where('subject', 'Chargeback needs staff attention')->where('body', 'like', '%the dispute D2 was opened after it%')->exists());
        // D1's marker is kept for its own late reversal.
        $this->assertTrue(GatewayRefund::query()->where('reference', 'D1')->where('origin', GatewayRefund::ORIGIN_DISPUTE_WON_EARLY)->exists());
    }

    /** F-GATEWAY-WEBHOOKS-1, the fix: a later dispute already closed leaves the won-early marker in use (nothing recorded). */
    public function test_a_later_dispute_already_closed_keeps_the_early_win(): void
    {
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        $invoice = $this->invoice('50.00');
        ExtensionHelper::addPayment($invoice->id, 'PayPal', 50.00, transactionId: 'CAP1', currency: 'EUR');
        ExtensionHelper::chargebackOpened('PayPal', 'CAP1', 'D1', 50.00, 'EUR');
        ExtensionHelper::chargebackReversedAtGateway('PayPal', 'CAP1', null, 50.00, 'EUR', 'D1');
        ExtensionHelper::chargebackOpened('PayPal', 'CAP1', 'D2', 20.00, 'EUR');
        ExtensionHelper::chargebackReversedAtGateway('PayPal', 'CAP1', null, 20.00, 'EUR', 'D2');

        $this->assertFalse(ExtensionHelper::chargebackAtGateway('PayPal', 'CAP1', 'REV-1', 50.00, 'EUR'));
        $this->assertSame('0.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** F-GATEWAY-WEBHOOKS-2, the fix: a payment recorded under PayPal_IPN is still found by PayPal's refunds and chargebacks. */
    public function test_ipn_only_payment_is_refunded_and_charged_back_by_ipn(): void
    {
        $gateway = Gateway::query()->create(['extension' => 'PayPal_IPN', 'name' => 'PayPal_IPN', 'type' => 'gateway', 'enabled' => true]);
        foreach (['email' => 'biz@example.test', 'test_mode' => '1'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Http::fake([self::IPN_VERIFY => Http::response('VERIFIED')]);
        (new PayPal_IPN(['email' => 'biz@example.test', 'test_mode' => '1']))->boot();
        $invoice = $this->invoice('50.00');
        $ipn = fn (array $fields) => $this->call('POST', '/extensions/paypal_ipn/notify', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query($fields + ['mc_currency' => 'EUR', 'receiver_email' => 'biz@example.test']))->assertOk();

        $ipn(['payment_status' => 'Completed', 'txn_id' => 'TXN-P', 'item_number' => (string) $invoice->id, 'mc_gross' => '50.00']);
        $payment = $invoice->transactions()->sole();
        $this->assertSame($gateway->id, (int) $payment->gateway_id);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $ipn(['payment_status' => 'Refunded', 'txn_id' => 'TXN-R', 'parent_txn_id' => 'TXN-P', 'mc_gross' => '-10.00']);
        $this->assertSame('10.00', (string) $payment->fresh()->refunded_amount);
        $ipn(['payment_status' => 'Reversed', 'txn_id' => 'TXN-CB', 'parent_txn_id' => 'TXN-P', 'mc_gross' => '-40.00']);
        $this->assertSame('50.00', (string) $payment->fresh()->refunded_amount);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }
}
