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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sweep 11, payments (adversarial verifier). S11-PAYMENTS-1: the token is given, and PayPal then refuses the ORDER of a
 * saved method's charge for a reason that is ours (403 NOT_AUTHORIZED / PERMISSION_DENIED: the REST app may not use the
 * vault; 422 PAYEE_ACCOUNT_RESTRICTED / PAYEE_ACCOUNT_LOCKED_OR_CLOSED: our PayPal account is limited). Nothing was
 * charged and the customer's PayPal was not declined: expected «not sent», no decline counted, staff told (as for the
 * token's 401 and for a key Stripe refuses with 401/403). Today it is booked as the customer's decline.
 */
class Sweep11PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private BillingAgreement $vault;

    /** @var array{0: int, 1: array<string, mixed>} */
    private array $order = [500, []];

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        MailFacade::fake();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-12 09:00:00');
        $this->client = User::factory()->create()->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        foreach (['client_id' => 'id', 'client_secret' => 'secret', 'test_mode' => true, 'webhook_id' => 'WH'] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->vault = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'PayPal', 'external_reference' => 'vault_1', 'type' => 'paypal']);
        Http::fake(fn ($request) => str_contains($request->url(), '/v1/oauth2/token')
            ? Http::response(['access_token' => 'token'], 200)
            : Http::response($this->order[1], $this->order[0]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0: int, 1: array<string, mixed>}> */
    public static function ourRefusals(): array
    {
        return [
            '403 permission denied' => [403, ['name' => 'NOT_AUTHORIZED', 'details' => [['issue' => 'PERMISSION_DENIED']]]],
            '422 payee account restricted' => [422, ['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'PAYEE_ACCOUNT_RESTRICTED']]]],
            '422 payee account locked or closed' => [422, ['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'PAYEE_ACCOUNT_LOCKED_OR_CLOSED']]]],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('ourRefusals')]
    public function test_an_order_paypal_refuses_for_a_reason_that_is_ours_is_not_the_customers_decline(int $status, array $body): void
    {
        $this->order = [$status, $body];
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-12']);
        $invoice->items()->create(['description' => 'Hosting', 'price' => '10.00', 'quantity' => 1]);

        $attempt = (new SavedMethodCharge)->charge($invoice->fresh(), $this->vault, ChargeAttempt::SOURCE_CRON)->fresh();

        $this->assertSame(ChargeAttempt::STATUS_NOT_SENT, $attempt->status, 'our own refusal is booked as the customer\'s decline');
        $this->assertSame(0, (int) $this->vault->fresh()->failed_count, 'a decline is counted on the customer\'s saved method');
        $this->assertGreaterThan(0, EmailLog::query()->where('to', 'staff@example.test')->where('subject', 'like', 'PayPal%')->count(),
            'staff are told nothing of what PayPal refused');
    }
}
