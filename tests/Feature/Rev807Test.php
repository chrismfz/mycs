<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\AdjustmentNote;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGatewayEvent;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use Tests\TestCase;

/**
 * Adversarial review of PR #807 (S10-PAYMENTS-1, S10-PAYMENTS-2, S10-MONEY-LEDGER-1). Each test fails on the PR head
 * (8bb968a2) for the reason in its docblock. Invented fixtures only.
 */
class Rev807Test extends TestCase
{
    use RefreshDatabase;

    private const PAYPAL_API = 'https://api-m.sandbox.paypal.com';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * The scenario of S10-MONEY-LEDGER-1 (Sweep10MoneyLedgerTest), with one number changed: the line is brought down to
     * 60.00, not 50.00. An open invoice of 100.00 with a renewal line; 80.00 paid by card, 20.00 of it refunded to credit
     * by staff (60.00 stays on the invoice), the line then brought down to 60.00: open, EXACTLY covered (nothing left to
     * pay, nothing over), not closed yet («Close as paid» is staff's next click, StaffLineEdit says so).
     *
     * @return array{client: int, gateway: Gateway, invoice: Invoice, service: Service, line: InvoiceItem, expires: string}
     */
    private function exactlyCoveredOpenInvoice(): array
    {
        $this->freezeTime();
        $this->storeSettings(['credits_enabled' => true, 'credits_auto_use' => true]);
        $user = User::factory()->create();
        $client = $user->client()->id;
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 100.00]);
        $service = Service::factory()->create(['client_id' => $client, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addDays(5)->startOfDay(), 'currency_code' => 'USD', 'price' => 100.00]);
        $invoice = Invoice::factory()->create(['client_id' => $client, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addDays(5)]);
        $line = $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100, 'reference_type' => Service::class, 'reference_id' => $service->id,
            'period_start' => now()->addDays(5)->toDateString(), 'period_end' => now()->addDays(5)->addMonth()->toDateString()]);
        $expires = $service->fresh()->expires_at->toDateString();

        (new RecordPaymentService)->handle($invoice->id, $gateway, '80.00', null, 'pi_rev807_1');
        ExtensionHelper::refundToCredit(InvoiceTransaction::query()->firstOrFail(), 20.00, false);
        $line->refresh()->update(['price' => 60]);

        // The premise: still open, nothing left to pay and nothing over, nothing renewed, 20.00 of credit.
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(0, RecordPaymentService::toCents($invoice->fresh()->remaining));
        $this->assertSame($expires, $service->fresh()->expires_at->toDateString());
        $this->assertNull($line->fresh()->applied_at);
        $this->assertSame(2000, RecordPaymentService::toCents(Credit::query()->where('client_id', $client)->value('amount')));

        return ['client' => $client, 'gateway' => $gateway, 'invoice' => $invoice, 'service' => $service, 'line' => $line, 'expires' => $expires];
    }

    /**
     * REV-807-1 (sibling of S10-MONEY-LEDGER-1 the fix does not cover; the same on main). The whole 80.00 goes back at
     * the gateway (a dashboard refund). refundOverpaidCredit's save (credited 20.00 → refunded 20.00) fires the payments'
     * listener while the invoice has exactly 0.00 left: the listener's «remaining === 0» branch, which the PR left as it
     * was, turns the invoice PAID in the middle of the refund; the 60.00 still on it then goes back from a «paid» invoice.
     *
     * Should happen (the PR's own rule: «a refund leaves an open invoice open», as it now does when the same invoice is
     * 10.00 over): the invoice stays unpaid and owes its 60.00 again, no refund note, its line not applied.
     *
     * Happens: the invoice is REFUNDED (closed, nothing to pay) with a numbered credit note of -60.00 for money it never
     * kept, and its renewal line is marked applied although the service was never renewed.
     */
    public function test_rev_807_1_gateway_refund_of_the_payment_on_an_exactly_covered_open_invoice_leaves_it_open(): void
    {
        $made = $this->exactlyCoveredOpenInvoice();

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_rev807_1', 're_rev807_1', 80.00, 'USD');

        $payment = InvoiceTransaction::query()->where('transaction_id', 'pi_rev807_1')->firstOrFail();
        // The money itself is right (this part passes): all of it back, nothing kept as credit.
        $this->assertSame(8000, RecordPaymentService::toCents($payment->refunded_amount) + RecordPaymentService::toCents($payment->credited_amount));
        $this->assertSame(0, RecordPaymentService::toCents(Credit::query()->where('client_id', $made['client'])->value('amount')));
        $this->assertSame($made['expires'], $made['service']->fresh()->expires_at->toDateString());

        $this->assertSame(Invoice::STATUS_UNPAID, $made['invoice']->fresh()->status,
            'all the money on an open invoice went back at the gateway, and the invoice was closed (paid in the middle of the refund, then refunded) instead of staying open');
        $this->assertSame(6000, RecordPaymentService::toCents($made['invoice']->fresh()->remaining), 'the 60.00 it bills is owed again');
        $this->assertSame(0, AdjustmentNote::query()->where('invoice_id', $made['invoice']->id)->count(), 'a credit note was issued for an invoice that was never paid');
        $this->assertNull($made['line']->fresh()->applied_at, 'the renewal line is marked applied, and the service was never renewed');
    }

    /**
     * REV-807-2 (the chargeback side of REV-807-1; the same on main). The 80.00 is charged back instead. The same
     * mid-way «paid» marks the renewal line applied; the chargeback then sets the invoice back to unpaid (60.00 owed),
     * before anything renewed the service. The customer then PAYS the 60.00 it owes.
     *
     * Should happen: the invoice is paid and its service is renewed by one period, as for any payment of a renewal.
     *
     * Happens: the invoice is paid, the 60.00 is kept, and the service is NOT renewed (its line was «applied» by the
     * chargeback, so the payment is taken as a replay: RenewServiceService::paidAgain). It expires unpaid-for although
     * its invoice is paid.
     */
    public function test_rev_807_2_paying_an_invoice_after_a_chargeback_on_an_exactly_covered_open_invoice_renews_its_service(): void
    {
        $made = $this->exactlyCoveredOpenInvoice();

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_rev807_1', 'dp_rev807_1', 80.00, 'USD');

        // After the chargeback (these pass): owed again, nothing renewed, no credit kept.
        $this->assertSame(Invoice::STATUS_UNPAID, $made['invoice']->fresh()->status);
        $this->assertSame(6000, RecordPaymentService::toCents($made['invoice']->fresh()->remaining));
        $this->assertSame($made['expires'], $made['service']->fresh()->expires_at->toDateString());
        $this->assertSame(0, RecordPaymentService::toCents(Credit::query()->where('client_id', $made['client'])->value('amount')));

        (new RecordPaymentService)->handle($made['invoice']->id, $made['gateway'], '60.00', null, 'pi_rev807_2');

        $this->assertSame(Invoice::STATUS_PAID, $made['invoice']->fresh()->status);
        $this->assertSame(InvoiceTransactionStatus::Succeeded, InvoiceTransaction::query()->where('transaction_id', 'pi_rev807_2')->firstOrFail()->status);
        $this->assertNotSame($made['expires'], $made['service']->fresh()->expires_at->toDateString(),
            'the customer paid the 60.00 the invoice owed after the chargeback: the invoice is paid and its service was not renewed');
    }

    /**
     * REV-807-3 (the same shape as S10-PAYMENTS-2, one step earlier in the same webhook; the same on main).
     * PayPal.php:762-770: the event's signature is verified by a call to PayPal, and only a 5xx of that call is thrown.
     * A rate limit (429) is «no answer» about the signature, exactly as it is about the order, but here it is taken as
     * «not verified»: answered 400 and written to the gateway log as a request that failed its signature (a forged call),
     * which GatewayLog::alarming never tells staff about.
     *
     * Should happen (the rule the PR wrote for the order read): the webhook fails (5xx), is logged as an error and not as
     * a forged call, so staff hear of it while PayPal retries.
     *
     * Happens: 400, signature_verified = false, nobody told. PayPal does retry a 400, so one 429 loses nothing; with
     * credentials refused for days (403) every PayPal event is dropped as «forged» without a word to staff.
     */
    public function test_rev_807_3_a_rate_limited_signature_check_is_not_a_forged_webhook(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        foreach (['test_mode' => '1', 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'wh'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $invoice = Invoice::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD', 'due_at' => now()->toDateString()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 20]);
        Http::fake([
            self::PAYPAL_API . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::PAYPAL_API . '/v1/notifications/verify-webhook-signature' => Http::response(['name' => 'RATE_LIMIT_REACHED'], 429),
        ]);
        /** @var PayPal $paypal */
        $paypal = ExtensionHelper::getExtension('gateway', 'PayPal', $gateway->fresh()->settings);
        $paypal->boot();
        PayPal::rememberOrder('ORDER-REV807', $invoice->fresh());
        ExtensionHelper::addProcessingPayment($invoice->id, 'PayPal', 20.00, null, 'CAP-REV807', currency: 'USD');

        $response = $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
            'id' => 'CAP-REV807', 'status' => 'COMPLETED', 'amount' => ['value' => '20.00', 'currency_code' => 'USD'],
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-REV807']],
        ]]);

        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status);
        $logged = PaymentGatewayEvent::query()->orderByDesc('id')->firstOrFail();
        $this->assertNotFalse($logged->signature_verified, 'PayPal rate-limited the signature check, and the completed capture is logged as a forged request (staff are never told)');
        $this->assertSame(500, $response->getStatusCode(), 'no answer about the signature (429) must fail the webhook as no answer about the order does, not refuse it as forged (400)');
    }
}
