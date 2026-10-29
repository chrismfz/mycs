<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use App\Services\Money\GatewayReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\Mollie\Mollie;
use Tests\TestCase;

/**
 * Adversarial review of #517 (Mollie refund idempotency key). The fake below behaves as Mollie documents
 * (docs.mollie.com/reference/api-idempotency): a POST with a key seen in the last hour gets the cached first answer,
 * a new key is a new refund. The key is counted from MyCS's own refunds only, so once the webhook (not MyCS) recorded
 * a refund, the next refund of the same amount within the hour carries the same key and gets the old refund back.
 */
class Adv517MollieRefundTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.mollie.com/v2/payments/tr_1';

    private ?int $invoiceId = null;

    /** @var array<string, array<string, mixed>> Mollie's idempotency cache: key => refund made for it */
    private array $byKey = [];

    /** @var list<array<string, mixed>> the refunds Mollie made */
    private array $refunds = [];

    private bool $timeoutNext = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Mollie'], ['name' => 'Mollie', 'type' => 'gateway']);
        $gateway->settings()->updateOrCreate(['key' => 'api_key'], ['value' => 'test_key']);
        (new Mollie(['api_key' => 'test_key']))->boot();

        Http::fake([
            self::API . '/refunds' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['_embedded' => ['refunds' => $this->refunds]]);
                }
                $key = $request->header('Idempotency-Key')[0] ?? null;
                if ($key !== null && isset($this->byKey[$key])) {
                    return Http::response($this->byKey[$key]); // Mollie's cached first answer
                }
                $refund = ['id' => 're_' . (count($this->refunds) + 1), 'status' => 'pending', 'amount' => $request->data()['amount']];
                $this->refunds[] = $refund;
                if ($key !== null) {
                    $this->byKey[$key] = $refund;
                }
                if ($this->timeoutNext) {
                    $this->timeoutNext = false;
                    throw new ConnectionException('timed out'); // Mollie refunded, MyCS never heard back
                }

                return Http::response($refund);
            },
            self::API . '/chargebacks' => Http::response(['_embedded' => ['chargebacks' => []]]),
            self::API => fn () => Http::response(['id' => 'tr_1', 'status' => 'paid', 'amount' => ['value' => '100.00', 'currency' => 'USD'],
                'amountRefunded' => ['value' => '50.00', 'currency' => 'USD'], '_links' => ['refunds' => ['href' => self::API . '/refunds']],
                'metadata' => ['mycs' => GatewayReference::mark(), 'invoice_id' => $this->invoiceId]]),
        ]);
    }

    private function payment(): InvoiceTransaction
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);
        ExtensionHelper::addPayment($invoice->id, 'Mollie', 100, transactionId: 'tr_1', currency: 'USD');
        $this->invoiceId = $invoice->id;

        return $invoice->fresh()->transactions()->sole();
    }

    private function timedOutRefundRecordedByWebhook(InvoiceTransaction $payment): void
    {
        $this->timeoutNext = true;
        try {
            ExtensionHelper::refund($payment, 30.00);
            $this->fail('the first refund call times out');
        } catch (ConnectionException) {
        }
        $this->post('/extensions/gateways/mollie/webhook', ['id' => 'tr_1'])->assertOk();
    }

    /** @return int cents Mollie really gives back (refunds not failed or canceled) */
    private function refundedAtMollieCents(): int
    {
        return (int) collect($this->refunds)->whereNotIn('status', ['failed', 'canceled'])
            ->sum(fn (array $r) => (int) round((float) $r['amount']['value'] * 100));
    }

    /**
     * ADV-517-1. A timeout, the webhook records Mollie's refund re_1 (30). Staff then refund another 30 (a second line)
     * within the hour: the same key, Mollie hands back re_1, ExtensionHelper sees re_1 recorded and returns true. Staff
     * are told «refunded»; the customer gets nothing and MyCS records nothing.
     */
    public function test_adv_517_1_a_second_refund_of_the_same_amount_after_a_webhook_recorded_one_is_not_a_silent_success(): void
    {
        $payment = $this->payment();
        $this->timedOutRefundRecordedByWebhook($payment);
        $this->assertSame('30.00', (string) $payment->fresh()->refunded_amount);

        $ok = false;
        try {
            $ok = ExtensionHelper::refund(InvoiceTransaction::find($payment->id), 30.00);
        } catch (\Throwable) {
            // telling staff that nothing new was refunded is a valid outcome
        }

        if ($ok) {
            $this->assertSame(6000, $this->refundedAtMollieCents(), 'refund() said true but Mollie made no second refund: the customer is owed 30');
            $this->assertSame('60.00', (string) $payment->fresh()->refunded_amount);
        }
        $this->assertTrue(true);
    }

    /**
     * ADV-517-2. A timeout, Mollie made re_1; staff cancel it at Mollie and the webhook records it as canceled
     * (origin failed). Staff refund the 30 again within the hour: the same key, Mollie hands back the canceled re_1,
     * and ExtensionHelper deletes the failed row and records 30 as refunded. MyCS says 30 went back; Mollie gave none.
     */
    public function test_adv_517_2_a_refund_after_the_timed_out_one_was_canceled_at_mollie_is_not_recorded_as_money_given_back(): void
    {
        $payment = $this->payment();
        $this->timeoutNext = true;
        try {
            ExtensionHelper::refund($payment, 30.00);
            $this->fail('the first refund call times out');
        } catch (ConnectionException) {
        }
        $this->refunds[0]['status'] = 'canceled';
        $this->post('/extensions/gateways/mollie/webhook', ['id' => 'tr_1'])->assertOk();
        $this->assertSame('0.00', (string) $payment->fresh()->refunded_amount);

        try {
            ExtensionHelper::refund(InvoiceTransaction::find($payment->id), 30.00); // staff refund it again
        } catch (\Throwable) {
        }

        $this->assertSame($this->refundedAtMollieCents(), (int) round((float) $payment->fresh()->refunded_amount * 100),
            'MyCS records a refund Mollie canceled: the customer is told 30 went back and got nothing');
    }
}
