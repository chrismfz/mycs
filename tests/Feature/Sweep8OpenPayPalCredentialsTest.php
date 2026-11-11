<?php

namespace Tests\Feature;

use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use RuntimeException;
use Tests\TestCase;

/**
 * Open item of the sweep-8 reviews (#791): PayPal refusing OUR client ID and secret at the token call of a saved method's
 * charge (401, or 400 invalid_client) is «not sent» as before, and staff are now told, once an hour, as for a key Stripe
 * refuses. An outage (5xx, no connection) or any other token error tells nobody; a notice that cannot be made never
 * changes the charge's outcome (REV-791-3).
 */
class Sweep8OpenPayPalCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private const SUBJECT = 'PayPal: saved methods cannot be charged';

    private Client $client;

    private BillingAgreement $vault;

    /** @var array{0: int, 1: array<string, mixed>} the token call's answer (status 0: no connection) */
    private array $token = [200, ['access_token' => 'token']];

    /** @var array{0: int, 1: array<string, mixed>} the order POST's answer */
    private array $order = [500, ['name' => 'INTERNAL_SERVER_ERROR']];

    private int $orders = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        MailFacade::fake();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-11 09:00:00');
        $this->client = User::factory()->create()->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        foreach (['client_id' => 'id', 'client_secret' => 'secret', 'test_mode' => true, 'webhook_id' => 'WH'] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->vault = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'PayPal', 'external_reference' => 'vault_1', 'type' => 'paypal']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/v1/oauth2/token')) {
                return $this->token[0] === 0 ? throw new ConnectionException('cURL error 28: Operation timed out') : Http::response($this->token[1], $this->token[0]);
            }
            $this->orders++;

            return Http::response($this->order[1], $this->order[0]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function charge(): ChargeAttempt
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-11']);
        $invoice->items()->create(['description' => 'Hosting', 'price' => '10.00', 'quantity' => 1]);

        return (new SavedMethodCharge)->charge($invoice->fresh(), $this->vault, ChargeAttempt::SOURCE_CRON)->fresh();
    }

    private function notices(): int
    {
        return EmailLog::query()->where('to', 'staff@example.test')->where('subject', self::SUBJECT)->count();
    }

    private function assertNotSent(ChargeAttempt $attempt): void
    {
        $this->assertSame(ChargeAttempt::STATUS_NOT_SENT, $attempt->status);
        $this->assertFalse(SavedMethodCharge::onItsWay($attempt->invoice()->firstOrFail()), 'nothing holds the invoice');
        $this->assertSame(0, $this->orders, 'no order was posted');
        $this->assertSame(0, (int) $this->vault->fresh()->failed_count, 'no decline is counted');
    }

    public function test_credentials_paypal_refuses_at_the_token_call_are_told_to_staff_once_an_hour(): void
    {
        $this->token = [401, ['error' => 'invalid_client', 'error_description' => 'Client Authentication failed']];
        $first = $this->charge();
        $this->assertNotSent($first);
        $this->assertSame(1, $this->notices());
        $body = (string) EmailLog::query()->where('subject', self::SUBJECT)->value('body');
        $this->assertStringContainsString('HTTP 401 invalid_client, invoice #' . $first->invoice_id, $body);

        Carbon::setTestNow('2026-11-11 09:30:00');
        $this->assertNotSent($this->charge());
        $this->assertSame(1, $this->notices(), 'once an hour, however many charges');

        Carbon::setTestNow('2026-11-11 10:00:01');
        $this->assertNotSent($this->charge());
        $this->assertSame(2, $this->notices());
    }

    public function test_a_400_invalid_client_is_our_credentials_refused_too(): void
    {
        $this->token = [400, ['error' => 'invalid_client', 'error_description' => 'Client Authentication failed']];
        $this->assertNotSent($this->charge());
        $this->assertSame(1, $this->notices());
    }

    /** The neighbours: an outage, no connection, or a token error that says nothing of our credentials: no notice. */
    public function test_an_outage_or_another_token_error_tells_nobody(): void
    {
        foreach ([[503, ['name' => 'SERVICE_UNAVAILABLE']], [500, []], [0, []], [429, ['error' => 'RATE_LIMIT_REACHED']],
            [400, ['error' => 'unsupported_grant_type']]] as $token) {
            $this->token = $token;
            $this->assertNotSent($this->charge());
        }
        $this->assertSame(0, $this->notices());
        $this->assertFalse(Cache::has('paypal-charge-credentials-refused'));
    }

    /** REV-791-3, here: the notice failing leaves the charge «not sent», and the next charge tells again. */
    public function test_a_notice_that_cannot_be_made_never_changes_the_charges_outcome(): void
    {
        $this->token = [401, ['error' => 'invalid_client']];
        MailFacade::shouldReceive('to')->andThrow(new RuntimeException('queue connection refused'));
        $this->assertNotSent($this->charge());
        $this->assertFalse(Cache::has('paypal-charge-credentials-refused'), 'its claim is dropped: told again by the next charge');
    }

    /** The neighbours after the token: a declined order is a failed charge as before, a 5xx on it unknown; neither tells staff this. */
    public function test_a_decline_and_an_order_left_unanswered_are_unchanged(): void
    {
        $this->order = [422, ['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED']]]];
        $declined = $this->charge();
        $this->assertSame(ChargeAttempt::STATUS_FAILED, $declined->status);
        $this->assertSame(1, (int) $this->vault->fresh()->failed_count);

        $this->order = [500, ['name' => 'INTERNAL_SERVER_ERROR']];
        $unknown = $this->charge();
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $unknown->status);
        $this->assertTrue(SavedMethodCharge::onItsWay($unknown->invoice()->firstOrFail()));

        $this->assertSame(2, $this->orders);
        $this->assertSame(0, $this->notices());
    }
}
