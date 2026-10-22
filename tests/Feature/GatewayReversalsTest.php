<?php

namespace Tests\Feature;

use App\Enums\MoneyEventKind;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\MoneyEvent;
use App\Models\User;
use App\Services\Money\GatewayReference;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\Mollie\Mollie;
use MyCS\Extensions\Gateways\PayPal_IPN\PayPal_IPN;
use Tests\TestCase;

/**
 * Security audit PAY-SEC-5: a refund, a chargeback and a chargeback won back at PayPal (IPN) or Mollie are recorded as
 * Stripe's are (once, the invoice owed again, staff told), never silently ignored.
 */
class GatewayReversalsTest extends TestCase
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

    private function paidInvoice(string $gateway, string $transactionId): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->subDays(3)]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);
        ExtensionHelper::addPayment($invoice->id, $gateway, 100, transactionId: $transactionId, currency: 'USD');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        return $invoice->fresh();
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

    public function test_an_ipn_refund_is_recorded_once(): void
    {
        $this->ipnGateway();
        $invoice = $this->paidInvoice('PayPal', 'TXN-PAY');

        foreach ([1, 2] as $try) { // PayPal resends
            $this->ipn(['payment_status' => 'Refunded', 'txn_id' => 'TXN-REF', 'parent_txn_id' => 'TXN-PAY', 'mc_gross' => '-40.00'])->assertOk();
        }

        $this->assertSame('40.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame(1, GatewayRefund::query()->where('reference', 'TXN-REF')->count());
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }

    public function test_an_ipn_chargeback_makes_the_invoice_owed_and_a_cancelled_one_undoes_it(): void
    {
        $this->ipnGateway();
        $invoice = $this->paidInvoice('PayPal', 'TXN-CB');

        $this->ipn(['payment_status' => 'Reversed', 'reason_code' => 'chargeback', 'txn_id' => 'TXN-REV', 'parent_txn_id' => 'TXN-CB', 'mc_gross' => '-100.00'])->assertOk();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(1, MoneyEvent::query()->where('kind', MoneyEventKind::Refund->value)->where('cause', MoneyTrail::CAUSE_CHARGEBACK)->count());

        foreach ([1, 2] as $try) {
            $this->ipn(['payment_status' => 'Canceled_Reversal', 'txn_id' => 'TXN-CANC', 'parent_txn_id' => 'TXN-CB', 'mc_gross' => '100.00'])->assertOk();
        }
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(1, MoneyEvent::query()->where('kind', MoneyEventKind::Reversal->value)->where('cause', MoneyTrail::CAUSE_CHARGEBACK_WON)->count());
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }

    public function test_an_ipn_reversal_of_a_payment_not_ours_moves_nothing(): void
    {
        $this->ipnGateway();
        $invoice = $this->paidInvoice('PayPal', 'TXN-OURS');

        $this->ipn(['payment_status' => 'Reversed', 'txn_id' => 'TXN-X', 'parent_txn_id' => 'TXN-ELSEWHERE', 'mc_gross' => '-100.00'])->assertOk();
        $this->ipn(['payment_status' => 'Reversed', 'txn_id' => 'TXN-Y', 'mc_gross' => '-100.00'])->assertOk(); // no parent

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('0.00', (string) $invoice->transactions()->sole()->refunded_amount);
    }

    private function mollie(array $payment, array $refunds = [], array $chargebacks = []): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Mollie'], ['name' => 'Mollie', 'type' => 'gateway']);
        $gateway->settings()->updateOrCreate(['key' => 'api_key'], ['value' => 'test_key']);
        $id = $payment['id'];
        Http::fake([
            "https://api.mollie.com/v2/payments/{$id}/refunds" => Http::response(['_embedded' => ['refunds' => $refunds]]),
            "https://api.mollie.com/v2/payments/{$id}/chargebacks" => Http::response(['_embedded' => ['chargebacks' => $chargebacks]]),
            "https://api.mollie.com/v2/payments/{$id}" => Http::response($payment),
        ]);
        (new Mollie(['api_key' => 'test_key']))->boot();
    }

    private function molliePayment(string $id, Invoice $invoice, array $extra = []): array
    {
        return ['id' => $id, 'status' => 'paid', 'amount' => ['value' => '100.00', 'currency' => 'USD'],
            'metadata' => ['mycs' => GatewayReference::mark(), 'invoice_id' => $invoice->id]] + $extra;
    }

    public function test_a_mollie_refund_is_recorded_once(): void
    {
        $invoice = $this->paidInvoice('Mollie', 'tr_ref');
        $this->mollie($this->molliePayment('tr_ref', $invoice, ['amountRefunded' => ['value' => '30.00', 'currency' => 'USD']]),
            refunds: [['id' => 're_1', 'status' => 'refunded', 'amount' => ['value' => '30.00', 'currency' => 'USD']],
                ['id' => 're_failed', 'status' => 'failed', 'amount' => ['value' => '70.00', 'currency' => 'USD']]]);

        foreach ([1, 2] as $try) {
            $this->post('/extensions/gateways/mollie/webhook', ['id' => 'tr_ref'])->assertOk();
        }

        $this->assertSame('30.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }

    public function test_a_mollie_chargeback_makes_the_invoice_owed_and_its_reversal_undoes_it(): void
    {
        $invoice = $this->paidInvoice('Mollie', 'tr_cb');
        $chargeback = ['id' => 'chb_1', 'amount' => ['value' => '100.00', 'currency' => 'USD']];
        $this->mollie($this->molliePayment('tr_cb', $invoice, ['amountChargedBack' => ['value' => '100.00', 'currency' => 'USD']]), chargebacks: [$chargeback]);

        $this->post('/extensions/gateways/mollie/webhook', ['id' => 'tr_cb'])->assertOk();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->mollie($this->molliePayment('tr_cb', $invoice, ['amountChargedBack' => ['value' => '0.00', 'currency' => 'USD'], '_links' => ['chargebacks' => ['href' => 'x']]]),
            chargebacks: [$chargeback + ['reversedAt' => now()->toIso8601String()]]);
        foreach ([1, 2] as $try) {
            $this->post('/extensions/gateways/mollie/webhook', ['id' => 'tr_cb'])->assertOk();
        }

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(1, MoneyEvent::query()->where('kind', MoneyEventKind::Reversal->value)->where('cause', MoneyTrail::CAUSE_CHARGEBACK_WON)->count());
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }
}
