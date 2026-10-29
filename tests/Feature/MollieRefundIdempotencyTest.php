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
 * PayPal's request id, plus what Mollie lists (it keeps a key for an hour only): a refund over one MyCS has not
 * recorded is refused, and an unknown outcome is a Log::critical.
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

    public function test_a_retry_after_a_timeout_is_refused_until_the_lost_refund_is_recorded_however_late(): void
    {
        $keys = [];
        $refunds = [];
        $this->fakeMollie(function (int $call) {
            if ($call === 0) {
                throw new ConnectionException('timed out'); // Mollie refunded, MyCS never heard back
            }

            return Http::response(['id' => 're_' . ($call + 1), 'status' => 'pending']);
        }, $keys, $refunds);
        $payment = $this->payment();
        Log::spy();

        try {
            ExtensionHelper::refund($payment, 50.00);
            $this->fail('the first refund call times out');
        } catch (ConnectionException) {
        }
        Log::shouldHaveReceived('critical')->withArgs(fn (string $message) => str_contains($message, 'Mollie refund outcome unknown'))->once();

        // Mollie lists the refund it made; its webhook has not come. The next morning (the key forgotten after an hour)
        // staff retry: refused, nothing sent.
        $refunds[] = ['id' => 're_1', 'status' => 'pending', 'amount' => ['value' => '50.00', 'currency' => 'USD']];
        $this->travel(1)->day();
        try {
            ExtensionHelper::refund(InvoiceTransaction::find($payment->id), 50.00);
            $this->fail('a refund over one MyCS has not recorded yet is refused');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('not recorded yet', $e->getMessage());
        }
        $this->assertCount(1, $keys, 'the retry was sent to Mollie: the customer gets 100 for the 50 staff meant');
        $this->assertSame('0.00', (string) $payment->fresh()->refunded_amount);

        // Once the webhook records it, a second refund staff mean is a refund of its own, with its own key.
        $this->post('/extensions/gateways/mollie/webhook', ['id' => 'tr_1'])->assertOk();
        $this->assertSame('50.00', (string) $payment->fresh()->refunded_amount);
        ExtensionHelper::refund(InvoiceTransaction::find($payment->id), 50.00);

        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1], 'the same key would give the first refund back: nothing paid, «done» shown');
        $this->assertSame('100.00', (string) $payment->fresh()->refunded_amount);
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }

    public function test_two_clicks_before_mollie_lists_the_first_share_a_key(): void
    {
        $keys = [];
        $this->fakeMollie(fn (int $call) => $call === 0
            ? throw new ConnectionException('timed out')
            : Http::response(['id' => 're_1', 'status' => 'pending']), $keys);
        $payment = $this->payment();

        try {
            ExtensionHelper::refund($payment, 50.00);
        } catch (ConnectionException) {
        }
        ExtensionHelper::refund(InvoiceTransaction::find($payment->id), 50.00); // Mollie does not list re_1 yet

        $this->assertCount(2, $keys);
        $this->assertNotEmpty($keys[0]);
        $this->assertSame($keys[0], $keys[1], 'Mollie gives the first refund back for the same key');
        $this->assertSame('50.00', (string) $payment->fresh()->refunded_amount);
    }

    public function test_a_key_still_being_processed_is_an_unknown_outcome(): void
    {
        $keys = [];
        $this->fakeMollie(fn () => Http::response(['status' => 409, 'detail' => 'A request with this idempotency key is already being processed'], 409), $keys);
        Log::spy();

        try {
            ExtensionHelper::refund($this->payment(), 50.00);
            $this->fail('a 409 is not a refund');
        } catch (\Throwable) {
        }

        Log::shouldHaveReceived('critical')->withArgs(fn (string $message) => str_contains($message, 'Mollie refund outcome unknown'))->once();
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
