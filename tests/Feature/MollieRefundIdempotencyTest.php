<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use App\Services\Money\GatewayReference;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\Mollie\Mollie;
use Tests\TestCase;

/**
 * Pre-launch audit, flow 2, AUD2-1 (docs/prelaunch-audit.md): a Mollie refund whose answer is lost (a timeout, Mollie's
 * 5xx) looked like a refusal, nothing was logged, and the staff retry was a new refund. As Stripe's Idempotency-Key and
 * PayPal's request id: the same refund keeps the same key, and an unknown outcome is a Log::critical.
 */
class MollieRefundIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.mollie.com/v2/payments/tr_1';

    private User $user;

    private ?int $invoiceId = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $this->user = User::factory()->create();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Mollie'], ['name' => 'Mollie', 'type' => 'gateway']);
        $gateway->settings()->updateOrCreate(['key' => 'api_key'], ['value' => 'test_key']);
        (new Mollie(['api_key' => 'test_key']))->boot();
    }

    private function payment(): InvoiceTransaction
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);
        ExtensionHelper::addPayment($invoice->id, 'Mollie', 100, transactionId: 'tr_1', currency: 'USD');
        $this->invoiceId = $invoice->id;

        return $invoice->fresh()->transactions()->sole();
    }

    /** @param  callable(int): mixed  $answer  what Mollie answers the n-th refund call (0-based) */
    private function fakeMollie(callable $answer, array &$keys, array &$refunds = []): void
    {
        $calls = 0;
        Http::fake([
            self::API . '/refunds' => function (Request $request) use ($answer, &$keys, &$calls, &$refunds) {
                if ($request->method() === 'GET') {
                    return Http::response(['_embedded' => ['refunds' => $refunds]]);
                }
                $keys[] = $request->header('Idempotency-Key')[0] ?? null;

                return $answer($calls++);
            },
            self::API . '/chargebacks' => Http::response(['_embedded' => ['chargebacks' => []]]),
            self::API => fn () => Http::response(['id' => 'tr_1', 'status' => 'paid', 'amount' => ['value' => '100.00', 'currency' => 'USD'],
                'amountRefunded' => ['value' => '50.00', 'currency' => 'USD'],
                'metadata' => ['mycs' => GatewayReference::mark(), 'invoice_id' => $this->invoiceId]]),
        ]);
    }

    public function test_a_refund_carries_an_idempotency_key(): void
    {
        $keys = [];
        $this->fakeMollie(fn () => Http::response(['id' => 're_1', 'status' => 'pending']), $keys);

        ExtensionHelper::refund($this->payment(), 50.00);

        $this->assertCount(1, $keys);
        $this->assertNotEmpty($keys[0], 'a Mollie refund without an Idempotency-Key is a new refund on every retry');
    }

    public function test_a_retry_after_a_timeout_reuses_the_key_even_once_the_webhook_recorded_the_first(): void
    {
        $keys = [];
        $refunds = [];
        $this->fakeMollie(function (int $call) {
            if ($call === 0) {
                throw new ConnectionException('timed out'); // Mollie refunded, MyCS never heard back
            }

            return Http::response(['id' => 're_1', 'status' => 'pending']); // Mollie's first answer, for the same key
        }, $keys, $refunds);
        $payment = $this->payment();
        Log::spy();

        try {
            ExtensionHelper::refund($payment, 50.00);
            $this->fail('the first refund call times out');
        } catch (ConnectionException) {
        }
        Log::shouldHaveReceived('critical')->withArgs(fn (string $message) => str_contains($message, 'Mollie refund outcome unknown'))->once();

        // Mollie's webhook for the refund it did make arrives before the staff retry.
        $refunds[] = ['id' => 're_1', 'status' => 'pending', 'amount' => ['value' => '50.00', 'currency' => 'USD']];
        $this->post('/extensions/gateways/mollie/webhook', ['id' => 'tr_1'])->assertOk();
        $this->assertSame('50.00', (string) $payment->fresh()->refunded_amount);

        try {
            ExtensionHelper::refund(InvoiceTransaction::find($payment->id), 50.00); // staff retry
        } catch (\Throwable) {
            // Refused here or not: what matters is the key Mollie saw.
        }

        $this->assertCount(2, $keys);
        $this->assertNotEmpty($keys[0], 'no Idempotency-Key: every retry is a new refund');
        $this->assertSame($keys[0], $keys[1], 'the retry is a new Mollie refund: the customer gets 100 for the 50 staff meant');
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }

    public function test_a_server_error_is_an_unknown_outcome_not_a_refusal(): void
    {
        $keys = [];
        $this->fakeMollie(fn () => Http::response('<html>Bad gateway</html>', 502), $keys);
        Log::spy();

        try {
            ExtensionHelper::refund($this->payment(), 50.00);
            $this->fail('a 502 is not a refund');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('Undefined', $e->getMessage(), 'a non-JSON error body crashed the message');
        }

        Log::shouldHaveReceived('critical')->withArgs(fn (string $message) => str_contains($message, 'Mollie refund outcome unknown'))->once();
    }

    public function test_a_refusal_is_still_a_refusal_and_is_not_logged_as_unknown(): void
    {
        $keys = [];
        $this->fakeMollie(fn () => Http::response(['status' => 422, 'detail' => 'The amount is higher than the refundable amount'], 422), $keys);
        Log::spy();

        try {
            ExtensionHelper::refund($this->payment(), 50.00);
            $this->fail('Mollie refused');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('refundable amount', $e->getMessage());
        }

        Log::shouldNotHaveReceived('critical', [\Mockery::on(fn ($message) => is_string($message) && str_contains($message, 'Mollie refund outcome unknown')), \Mockery::any()]);
    }
}
