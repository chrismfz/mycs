<?php

namespace Tests\Feature;

use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Sweep 11, S11-PAYMENTS-1: PayPal's refusal of the order for OUR account or permissions is «not sent» and staff are
 * told. The paths around it stay as they were: every refusal about the payer (or any other 4xx) is the customer's failed
 * charge, mailed and counted; a 5xx is an unknown outcome. Invented rows, PayPal faked.
 */
class PayPalOrderRefusedTest extends TestCase
{
    use RefreshDatabase;

    private const OURS_SUBJECT = 'PayPal: our account refuses charges';

    private Client $client;

    private BillingAgreement $vault;

    /** @var array{0: int, 1: array<string, mixed>} */
    private array $order = [500, []];

    private int $orders = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0]);
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
        $this->client->forceFill(['auto_charge' => true, 'default_billing_agreement_id' => $this->vault->id])->save();
        $this->client->refresh();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'token'], 200);
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

    private function invoice(): Invoice
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-11-12']);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-12']);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    private function failedMails(): int
    {
        return count(MailFacade::queued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'invoice_payment_failed'));
    }

    private function staffNotices(): int
    {
        return EmailLog::query()->where('to', 'staff@example.test')->where('subject', self::OURS_SUBJECT)->count();
    }

    /** @return array<string, array{0: int, 1: array<string, mixed>}> */
    public static function notOurs(): array
    {
        $refused = fn (string $issue) => ['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => $issue]]];

        return [
            'the payer\'s instrument declined' => [422, $refused('INSTRUMENT_DECLINED')],
            'the payer\'s account restricted' => [422, $refused('PAYER_ACCOUNT_RESTRICTED')],
            'the payer\'s account locked or closed' => [422, $refused('PAYER_ACCOUNT_LOCKED_OR_CLOSED')],
            'the payer cannot pay' => [422, $refused('PAYER_CANNOT_PAY')],
            'a 422 with no issue' => [422, ['name' => 'UNPROCESSABLE_ENTITY']],
            // Maybe one vault token our app may not use: the customer is told and saves the method again (review of #811).
            '403 permission denied' => [403, ['name' => 'NOT_AUTHORIZED', 'details' => [['issue' => 'PERMISSION_DENIED']]]],
            'a 403 with another issue' => [403, ['name' => 'NOT_AUTHORIZED', 'details' => [['issue' => 'CONSENT_NEEDED']]]],
            'a 403 with no issue' => [403, ['name' => 'NOT_AUTHORIZED']],
            'our issue under another status' => [400, ['name' => 'INVALID_REQUEST', 'details' => [['issue' => 'PAYEE_ACCOUNT_RESTRICTED']]]],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('notOurs')]
    public function test_any_other_refusal_of_the_order_is_still_the_customers_failed_charge(int $status, array $body): void
    {
        $this->order = [$status, $body];
        $invoice = $this->invoice();

        (new AutoCharge)->run();

        $this->assertSame(1, $this->orders);
        $this->assertSame(ChargeAttempt::STATUS_FAILED, ChargeAttempt::query()->where('invoice_id', $invoice->id)->sole()->status);
        $this->assertSame(1, (int) $this->vault->fresh()->failed_count, 'the decline is counted');
        $this->assertSame(1, $this->failedMails(), 'the customer is told');
        $this->assertSame(0, $this->staffNotices());
    }

    public function test_a_5xx_on_the_order_is_still_an_unknown_outcome(): void
    {
        $this->order = [503, ['name' => 'SERVICE_UNAVAILABLE', 'details' => [['issue' => 'PAYEE_ACCOUNT_RESTRICTED']]]];
        $invoice = $this->invoice();

        $attempt = (new SavedMethodCharge)->charge($invoice, $this->vault, ChargeAttempt::SOURCE_CRON)->fresh();

        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $attempt->status);
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()), 'maybe taken: the invoice is held');
        $this->assertSame(0, $this->staffNotices());
    }

    public function test_our_accounts_refusal_on_the_cron_tells_no_customer_counts_nothing_and_tells_staff_once_an_hour(): void
    {
        $this->order = [422, ['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'PAYEE_ACCOUNT_RESTRICTED']]]];
        $first = $this->invoice();
        $second = $this->invoice();

        (new AutoCharge)->run();

        $this->assertSame(2, $this->orders);
        $this->assertSame([ChargeAttempt::STATUS_NOT_SENT, ChargeAttempt::STATUS_NOT_SENT],
            ChargeAttempt::query()->whereIn('invoice_id', [$first->id, $second->id])->pluck('status')->all());
        $this->assertSame(0, (int) $this->vault->fresh()->failed_count);
        $this->assertSame(0, $this->failedMails());
        $this->assertFalse(SavedMethodCharge::onItsWay($first->fresh()), 'the order was refused: nothing holds the invoice');
        $this->assertSame(1, $this->staffNotices(), 'one notice for the run');

        // Past the hour, the next refusal tells again.
        Carbon::setTestNow('2026-11-12 10:00:01');
        (new SavedMethodCharge)->charge($this->invoice(), $this->vault, ChargeAttempt::SOURCE_CLIENT);
        $this->assertSame(2, $this->staffNotices());
    }

    public function test_a_staff_notice_that_fails_does_not_change_the_outcome(): void
    {
        $this->order = [422, ['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'PAYEE_ACCOUNT_LOCKED_OR_CLOSED']]]];
        MailFacade::shouldReceive('to')->andThrow(new RuntimeException('queue connection refused'));
        $invoice = $this->invoice();

        $attempt = (new SavedMethodCharge)->charge($invoice, $this->vault, ChargeAttempt::SOURCE_CRON)->fresh();

        $this->assertSame(ChargeAttempt::STATUS_NOT_SENT, $attempt->status);
        $this->assertSame(0, (int) $this->vault->fresh()->failed_count);
    }
}
