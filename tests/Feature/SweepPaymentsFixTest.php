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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use MyCS\Extensions\Gateways\PayPal_IPN\PayPal_IPN;
use Tests\TestCase;

/** The fixes of the sweep's «payments» findings (2026-11-07): what else they change, around SweepPaymentsTest. */
class SweepPaymentsFixTest extends TestCase
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

    private function paypal(): Gateway
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        foreach (['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1', 'test_mode' => '1'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Http::fake([
            self::API . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API . '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);

        return $gateway->fresh();
    }

    private function captureEvent(string $type, string $capture, string $eventId)
    {
        return $this->postJson('/extensions/paypal/webhook', ['id' => $eventId, 'event_type' => $type,
            'resource' => ['id' => $capture, 'status' => 'DENIED', 'amount' => ['value' => '10.00', 'currency_code' => 'USD']]]);
    }

    private function staffMails(): int
    {
        $staff = fn (Mailable $mail) => $mail->hasTo('staff@example.test');

        return Mail::sent($staff)->count() + Mail::queued($staff)->count();
    }

    private function ipn(array $fields)
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal_IPN'], ['name' => 'PayPal_IPN', 'type' => 'gateway', 'enabled' => true]);
        foreach (['email' => 'biz@example.test', 'test_mode' => '1'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Http::fake([self::IPN_VERIFY => Http::response('VERIFIED')]);
        (new PayPal_IPN(['email' => 'biz@example.test', 'test_mode' => '1']))->boot();

        return $this->call('POST', '/extensions/paypal_ipn/notify', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query($fields + ['payment_status' => 'Completed', 'mc_gross' => '10.00', 'mc_currency' => 'USD']));
    }

    public function test_a_declined_pending_capture_is_failed_and_staff_told_once(): void
    {
        $this->paypal();
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice->id, 'PayPal', '10.00', null, 'CAP-P-2', InvoiceTransactionStatus::Processing, currency: 'USD');

        $this->captureEvent('PAYMENT.CAPTURE.DECLINED', 'CAP-P-2', 'WH-EV-2')->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame(InvoiceTransactionStatus::Failed, $invoice->transactions()->sole()->status);
        $told = $this->staffMails();
        $this->assertGreaterThanOrEqual(1, $told, 'staff are told the capture was declined');

        // PayPal sends it again: nothing more changes, nobody is told again.
        $this->captureEvent('PAYMENT.CAPTURE.DECLINED', 'CAP-P-2', 'WH-EV-2')->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertSame($told, $this->staffMails());
        $this->assertSame(1, $invoice->transactions()->count());
    }

    public function test_a_denied_event_never_undoes_a_succeeded_capture_nor_another_gateways_payment(): void
    {
        $this->paypal();
        $invoice = $this->invoice('20.00');
        ExtensionHelper::addPayment($invoice->id, 'PayPal', '10.00', null, 'CAP-OK', currency: 'USD');
        $other = Gateway::query()->create(['extension' => 'SweepOther', 'name' => 'SweepOther', 'type' => 'gateway', 'enabled' => true]);
        $invoice->transactions()->create(['gateway_id' => $other->id, 'amount' => '10.00', 'transaction_id' => 'CAP-ELSE',
            'status' => InvoiceTransactionStatus::Processing]);

        $this->captureEvent('PAYMENT.CAPTURE.DENIED', 'CAP-OK', 'WH-EV-3')->assertOk()->assertJson(['status' => 'ignored']);
        $this->captureEvent('PAYMENT.CAPTURE.DENIED', 'CAP-ELSE', 'WH-EV-4')->assertOk()->assertJson(['status' => 'ignored']);
        $this->captureEvent('PAYMENT.CAPTURE.DENIED', 'CAP-UNKNOWN', 'WH-EV-5')->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(InvoiceTransactionStatus::Succeeded, $invoice->transactions()->where('transaction_id', 'CAP-OK')->sole()->status);
        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->where('transaction_id', 'CAP-ELSE')->sole()->status);
        $this->assertSame(0, $this->staffMails());
    }

    public function test_a_group_capture_with_nothing_on_its_way_is_captured_and_pays_the_group(): void
    {
        $paypal = $this->paypal();
        $a = $this->invoice('10.00', '2026-11-08');
        $b = $this->invoice('10.00', '2026-11-10');
        $group = (new MassPay)->open($this->user->client(), [$a->id, $b->id], $this->user);
        PayPal::rememberOrder('ORDER-H', $a);
        $order = ['id' => 'ORDER-H', 'status' => 'APPROVED', 'purchase_units' => [['invoice_id' => GatewayReference::paypalInvoiceId($a),
            'custom_id' => $group->ulid, 'amount' => ['currency_code' => 'USD', 'value' => '20.00']]]];
        Http::fake([
            self::API . '/v2/checkout/orders/ORDER-H/capture' => Http::response(['id' => 'ORDER-H', 'status' => 'COMPLETED',
                'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-H', 'status' => 'COMPLETED',
                    'amount' => ['value' => '20.00', 'currency_code' => 'USD']]]]]]], 201),
            self::API . '/v2/checkout/orders/ORDER-H' => Http::response($order),
        ]);

        ExtensionHelper::getExtension('gateway', 'PayPal', $paypal->settings)->capture($a->fresh(), 'ORDER-H');

        Http::assertSent(fn (Request $request) => $request->url() === self::API . '/v2/checkout/orders/ORDER-H/capture');
        $this->assertSame(Invoice::STATUS_PAID, $a->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $b->fresh()->status);
    }

    public function test_an_ipn_to_another_account_is_still_refused(): void
    {
        $invoice = $this->invoice();

        $this->ipn(['txn_id' => 'TXN-F-1', 'item_number' => (string) $invoice->id, 'receiver_email' => 'other@example.test',
            'business' => 'other@example.test'])->assertOk();
        $this->ipn(['txn_id' => 'TXN-F-2', 'item_number' => (string) $invoice->id])->assertOk(); // names no receiver at all

        $this->assertFalse($invoice->transactions()->exists());
    }

    public function test_an_ipn_to_our_primary_email_is_recorded_whatever_its_business(): void
    {
        $invoice = $this->invoice();

        $this->ipn(['txn_id' => 'TXN-P-1', 'item_number' => (string) $invoice->id, 'receiver_email' => 'BIZ@example.test',
            'business' => 'alias@example.test'])->assertOk();

        $this->assertSame('TXN-P-1', $invoice->transactions()->sole()->transaction_id);
    }

    public function test_an_ipn_completed_for_no_invoice_of_ours_is_told_to_staff(): void
    {
        $this->ipn(['txn_id' => 'TXN-N-1', 'item_number' => '999999', 'receiver_email' => 'biz@example.test'])->assertOk();

        $this->assertSame(1, $this->staffMails());
    }

    private function vaultAttempt(Invoice $invoice, Gateway $paypal, string $amount = '10.00'): ChargeAttempt
    {
        $agreement = BillingAgreement::query()->firstOrCreate(['client_id' => $this->user->client()->id, 'gateway_id' => $paypal->id,
            'external_reference' => 'vault-token-1'], ['name' => 'PayPal', 'type' => 'paypal']);

        return ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'client_id' => $this->user->client()->id,
            'billing_agreement_id' => $agreement->id, 'amount' => $amount, 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON,
            'status' => ChargeAttempt::STATUS_SUCCEEDED, 'started_at' => now(), 'sent_at' => now(), 'finished_at' => now()]);
    }

    /** REV-PAYMENTS-1: a charge whose payment is another one, still succeeded, is not the denied capture's: it stays. */
    public function test_rev_payments_1_a_charge_recorded_by_another_payment_is_kept(): void
    {
        $paypal = $this->paypal();
        $invoice = $this->invoice('20.00');
        Carbon::setTestNow('2026-11-10 07:00:00');
        $attempt = $this->vaultAttempt($invoice, $paypal);
        ExtensionHelper::addPayment($invoice->id, 'PayPal', '10.00', null, 'CAP-OK-1', InvoiceTransactionStatus::Succeeded, currency: 'USD');
        ExtensionHelper::addPayment($invoice->id, 'PayPal', '10.00', null, 'CAP-PEND-1', InvoiceTransactionStatus::Processing, currency: 'USD');
        Carbon::setTestNow('2026-11-10 09:00:00');

        $this->captureEvent('PAYMENT.CAPTURE.DENIED', 'CAP-PEND-1', 'WH-EV-KEEP-1')->assertOk();

        $this->assertSame(ChargeAttempt::STATUS_SUCCEEDED, $attempt->fresh()->status, 'its money is the succeeded payment');
    }

    /** REV-PAYMENTS-1: two charges the denied capture could be: neither is known to be it, both are left to staff. */
    public function test_rev_payments_1_two_charges_the_capture_could_be_are_left_to_staff(): void
    {
        $paypal = $this->paypal();
        $invoice = $this->invoice('20.00');
        Carbon::setTestNow('2026-11-10 07:00:00');
        $first = $this->vaultAttempt($invoice, $paypal);
        $second = $this->vaultAttempt($invoice, $paypal);
        ExtensionHelper::addPayment($invoice->id, 'PayPal', '10.00', null, 'CAP-PEND-2', InvoiceTransactionStatus::Processing, currency: 'USD');
        Carbon::setTestNow('2026-11-10 09:00:00');

        $this->captureEvent('PAYMENT.CAPTURE.DENIED', 'CAP-PEND-2', 'WH-EV-TWO-1')->assertOk();

        $this->assertSame(InvoiceTransactionStatus::Failed, $invoice->transactions()->sole()->status);
        $this->assertSame([ChargeAttempt::STATUS_SUCCEEDED, ChargeAttempt::STATUS_SUCCEEDED], [$first->fresh()->status, $second->fresh()->status]);
    }
}
