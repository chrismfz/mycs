<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGatewayEvent;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\GatewayLog;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Paymenter\Extensions\Gateways\PayPal\PayPal;
use Paymenter\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * Adversarial review of MT-3 (feat/mt-3-money-check): the money check, its command, the payments log and the gateway
 * log upkeep. test_<what should hold> proves a finding (fails on the branch head); test_holds_<...> is a guard.
 * No real data: example.test addresses, documentation IPs (RFC 5737), made-up provider ids.
 */
class MoneyCheckAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_adversarial';

    /** I10 of BillingCursorFuzzTest: the rules the simulation runs after every step. */
    // As BillingCursorFuzzTest::I10_EXCLUDED after the fix (the simulation excuses single invoices, not whole rules).
    private const SIMULATION_RULES_EXCLUDED = BillingCursorFuzzTest::I10_EXCLUDED;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
    }

    private function invoice(float $price = 10, ?User $user = null): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => ($user ?? $this->user)->id, 'status' => Invoice::STATUS_PENDING, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function service(string $status): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'currency_code' => 'USD', 'price' => 10]);
    }

    private function serviceInvoice(Service $service, float $price = 10): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_PENDING, 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function stripe(): Gateway
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        foreach (['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        (new Stripe(['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET]))->boot();

        return $gateway;
    }

    /** @param  array<string, mixed>  $server */
    private function stripeWebhook(array $event, bool $signed, array $server = [])
    {
        $payload = json_encode($event);
        $time = time();
        $signature = $signed ? hash_hmac('sha256', $time . '.' . $payload, self::SECRET) : 'forged';

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], $server + [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}",
        ], $payload);
    }

    /** @return list<string> */
    private function rules(?array $only = null): array
    {
        return array_values(array_unique(array_column((new MoneyCheck)->run($only), 'rule')));
    }

    private function moneyCheckMails(): int
    {
        return DB::table('email_logs')->where('subject', 'like', 'Money check:%')->count();
    }

    // =====================================================================  false negatives: money wrong, nothing said

    /**
     * F1. Staff «Cancel» (InvoiceResource / ViewInvoice: $invoice->update(['status' => cancelled])) on an invoice that
     * a gateway paid in part: the 4.00 stays on a cancelled invoice, neither credited nor refunded (the very state
     * CancelOpenInvoices refuses to create). No rule looks at money held by a cancelled invoice.
     */
    public function test_money_left_on_a_cancelled_invoice_is_reported(): void
    {
        $invoice = $this->invoice(10);
        (new RecordPaymentService)->handle($invoice, null, 4.0, transactionId: 'wire-001');
        $invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Customer asked']);

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame(0, (int) round((float) (Credit::query()->where('user_id', $this->user->id)->value('amount') ?? 0) * 100), 'no credit given');
        $this->assertNotSame([], (new MoneyCheck)->run(), 'a cancelled invoice holding 4.00 of the customer\'s money is not reported');
    }

    /** F1b. The same through «Cancel Invoice» on a paid invoice (ViewInvoice shows it for paid invoices too). */
    public function test_a_paid_invoice_cancelled_by_staff_with_its_money_on_it_is_reported(): void
    {
        $invoice = $this->invoice(10);
        (new RecordPaymentService)->handle($invoice, null, 10.0, transactionId: 'wire-002');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Wrong invoice']);

        $this->assertNotSame([], (new MoneyCheck)->run(), 'the 10.00 sits on a cancelled invoice and nothing is reported');
    }

    /** F1c. Credit spent on an invoice that staff then cancel by hand: the credit is gone for nothing. */
    public function test_credit_spent_on_an_invoice_staff_cancelled_is_reported(): void
    {
        (new CreditLedger)->add($this->user->id, 'USD', 600, 'staff_adjustment', 'staff', note: 'Goodwill');
        $invoice = $this->invoice(10);
        (new RecordPaymentService)->payWithCredit($invoice);
        $invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Duplicate']);

        $this->assertSame(0, (int) round((float) Credit::query()->where('user_id', $this->user->id)->value('amount') * 100));
        $this->assertNotSame([], (new MoneyCheck)->run(), '6.00 of credit sits on a cancelled invoice and nothing is reported');
    }

    /**
     * F2. An invoice of a cancelled service is reported while open (open_for_cancelled). Once the customer pays it
     * (it was left open for staff, or it was paid before CancelOpenInvoices ran) RenewServiceService refuses to revive
     * the service, ProcessPaidInvoiceService clears apply_pending_since anyway, and the money buys nothing: no rule
     * sees a paid invoice whose service lines were never applied.
     */
    public function test_a_paid_invoice_for_a_cancelled_service_is_reported(): void
    {
        $service = $this->service(Service::STATUS_CANCELLED);
        $invoice = $this->serviceInvoice($service);
        $this->assertSame(['open_for_cancelled'], $this->rules(), 'seen while open');

        (new RecordPaymentService)->handle($invoice, null, 10.0, transactionId: 'wire-003');
        $invoice = $invoice->fresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertNull($invoice->apply_pending_since, 'the marker is cleared');
        $this->assertNull($invoice->items()->sole()->applied_at, 'nothing was delivered');

        $this->assertNotSame([], (new MoneyCheck)->run(), '10.00 paid for a cancelled service, no period, no credit: not reported');
    }

    /**
     * F3. One Stripe payment recorded twice: staff «Add Payment» on customer B's invoice, typing the provider id and
     * leaving the gateway empty, and the webhook on customer A's invoice. RecordPaymentService scopes replays by
     * customer, and duplicate_gateway_id ignores rows without a gateway_id: 10.00 counted twice, nothing reported.
     */
    public function test_one_provider_payment_recorded_by_staff_without_a_gateway_and_by_the_webhook_is_reported(): void
    {
        $gateway = $this->stripe();
        $other = User::factory()->create();
        (new RecordPaymentService)->recordByStaff($this->invoice(10, $other), null, 10.0, 'pi_3AdvDup0001');
        (new RecordPaymentService)->handle($this->invoice(10), $gateway, 10.0, transactionId: 'pi_3AdvDup0001', currency: 'USD');

        $this->assertSame(2, InvoiceTransaction::query()->where('transaction_id', 'pi_3AdvDup0001')
            ->where('status', InvoiceTransactionStatus::Succeeded->value)->count());
        $this->assertSame(['duplicate_gateway_id'], $this->rules(['duplicate_gateway_id']));
    }

    // =====================================================================  false positives: healthy books flagged

    /**
     * P1. A bank-transfer reference staff type for two different customers (the app records both: replays are per
     * customer) is reported as «one payment id succeeded twice» and mails staff. Staff-entered payments have no
     * external_reference in the journal; the rule could look only at ids a gateway recorded.
     */
    public function test_the_same_staff_reference_on_two_customers_is_not_a_duplicate(): void
    {
        $gateway = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Bank transfer', 'type' => 'gateway']);
        $other = User::factory()->create();
        (new RecordPaymentService)->recordByStaff($this->invoice(10), $gateway, 10.0, 'cash');
        (new RecordPaymentService)->recordByStaff($this->invoice(10, $other), $gateway, 10.0, 'cash');

        $this->assertSame([], $this->rules(['duplicate_gateway_id']));
    }

    // =====================================================================  gateway log: throttle and unmatched routes

    /**
     * T1. The throttle drops rows before it knows what they are: a signed webhook that records money, from an address
     * that already wrote 120 rows this minute (a flood sharing it: a proxy, a provider's egress), leaves no gateway-log
     * row at all, so the payment has no gateway evidence and a 5xx after it would not alert.
     */
    public function test_a_signed_webhook_that_records_money_keeps_its_row_when_its_address_is_flooded(): void
    {
        $this->stripe();
        $invoice = $this->invoice(10);
        $key = 'gateway-log:' . sha1('203.0.113.9');
        for ($i = 0; $i < GatewayLog::ROWS_PER_MINUTE; $i++) {
            RateLimiter::hit($key, 60);
        }

        $this->stripeWebhook(['type' => 'payment_intent.succeeded', 'data' => ['object' => [
            'id' => 'pi_3AdvFlood01', 'amount' => 1000, 'currency' => 'usd', 'metadata' => ['invoice_id' => (string) $invoice->id],
        ]]], true, ['REMOTE_ADDR' => '203.0.113.9'])->assertOk();

        $transaction = InvoiceTransaction::query()->where('transaction_id', 'pi_3AdvFlood01')->sole();
        $this->assertTrue(PaymentGatewayEvent::query()->where('invoice_transaction_id', $transaction->id)->exists(),
            'the money was recorded but its gateway-log row was throttled away');
    }

    /**
     * T2. With trusted_proxies «*» (a documented setting: ProxyMiddleware then trusts REMOTE_ADDR, i.e. anyone),
     * the throttle keys on X-Forwarded-For, which the sender writes. An outsider spoofs a gateway's address and its
     * real, signed webhooks lose their rows.
     */
    public function test_a_spoofed_forwarded_address_cannot_silence_a_gateways_rows(): void
    {
        config(['settings.trusted_proxies' => ['*']]);
        $this->stripe();
        for ($i = 0; $i < GatewayLog::ROWS_PER_MINUTE; $i++) {
            $this->stripeWebhook(['type' => 'noise'], false, ['REMOTE_ADDR' => '198.51.100.66', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'])->assertStatus(400);
        }
        $before = PaymentGatewayEvent::query()->count();

        $this->stripeWebhook(['type' => 'customer.updated', 'data' => ['object' => []]], true, ['REMOTE_ADDR' => '203.0.113.9'])->assertOk();

        $this->assertSame($before + 1, PaymentGatewayEvent::query()->count(), 'the gateway\'s own signed request was not logged');
    }

    /** T3. Same setting, the other way: a new X-Forwarded-For per request writes as many rows as it likes. */
    public function test_a_flood_with_rotating_forwarded_addresses_is_still_capped(): void
    {
        config(['settings.trusted_proxies' => ['*']]);
        $this->stripe();
        for ($i = 0; $i < GatewayLog::ROWS_PER_MINUTE + 10; $i++) {
            $this->stripeWebhook(['type' => 'noise'], false, ['REMOTE_ADDR' => '198.51.100.66', 'HTTP_X_FORWARDED_FOR' => '192.0.2.' . ($i % 250 + 1)])->assertStatus(400);
        }

        $this->assertLessThanOrEqual(GatewayLog::ROWS_PER_MINUTE, PaymentGatewayEvent::query()->count());
    }

    /**
     * T4. A request to a gateway URL that matches no route keeps its raw path. The gateway log keeps names, never values
     * (docs/money-trail.md §4): a GET to PayPal's capture URL stores the invoice id and the PayPal order id.
     */
    public function test_an_unmatched_gateway_request_keeps_no_values_from_its_path(): void
    {
        Gateway::query()->create(['extension' => 'PayPal', 'name' => 'PayPal', 'type' => 'gateway']);
        (new PayPal([]))->boot();

        $this->get('/extensions/paypal/capture/4242/5O190127TN364715T')->assertStatus(405);

        $row = PaymentGatewayEvent::query()->sole();
        $this->assertStringNotContainsString('5O190127TN364715T', (string) $row->endpoint);
        $this->assertStringNotContainsString('4242', (string) $row->endpoint);
    }

    // =====================================================================  the payments log and the command

    /**
     * L1. The new «payments» channel is a second daily file, created first each day by the 06:30 money check (it
     * always writes «nothing found»). When it cannot be written (the cron user created the day's file, the web user
     * cannot append: Laravel's classic daily-log permission problem; no «permission» on the channel), every
     * PaymentsLog call throws. Here: an overpayment is recorded and committed, then its notice throws out of
     * RecordPaymentService::handle, so the webhook answers 500 (the gateway retries) and staff are never mailed.
     */
    public function test_a_payments_log_that_cannot_be_written_does_not_break_a_payment(): void
    {
        config(['logging.channels.payments.path' => base_path('composer.json') . '/nope/payments.log']);
        $invoice = $this->invoice(10);

        try {
            (new RecordPaymentService)->handle($invoice, null, 15.0, transactionId: 'wire-004');
        } catch (\Throwable $e) {
            $this->fail('the payment path threw because the payments log could not be written: ' . get_class($e) . ': ' . $e->getMessage());
        }
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** L2. Same cause, the command: it logs before it mails, so a finding is never mailed while the log is broken. */
    public function test_the_money_check_mails_staff_even_when_the_payments_log_cannot_be_written(): void
    {
        $invoice = $this->invoice(10);
        (new RecordPaymentService)->handle($invoice, null, 10.0, transactionId: 'wire-005');
        DB::table('invoices')->where('id', $invoice->id)->update(['apply_pending_since' => now()->subDays(2)]);
        config(['logging.channels.payments.path' => base_path('composer.json') . '/nope/payments.log']);

        try {
            $this->artisan('mycs:money-check')->run();
        } catch (\Throwable $e) {
            // reported below: the mail is what matters
        }

        $this->assertSame(1, $this->moneyCheckMails(), 'a finding was not mailed because the payments log could not be written');
    }

    // =====================================================================  the simulation (BillingCursorFuzzTest I10)

    /**
     * S1. I10 drops cancelled_applied and open_for_cancelled entirely («the simulation's staff cancel in the database»),
     * but its cancel_paid step is the app's own model path (the same $invoice->update as the admin's «Cancel Invoice»).
     * A paid, applied invoice cancelled that way keeps its money and its delivered period, and I10 says nothing. Only
     * the seeded setup invoice needs excluding (with all rules on, every seed trips on invoice #2 of the setup).
     */
    public function test_the_simulation_rules_see_a_paid_invoice_cancelled_through_the_model(): void
    {
        $service = $this->service(Service::STATUS_ACTIVE);
        $invoice = $this->serviceInvoice($service);
        (new RecordPaymentService)->handle($invoice, null, 10.0, transactionId: 'wire-006');
        $this->assertNotNull($invoice->items()->sole()->applied_at, 'the period was delivered');
        $invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED]); // cancel_paid / «Cancel Invoice»

        $this->assertContains('cancelled_applied', $this->rules(), 'the daily check sees it');
        $simulation = (new MoneyCheck)->run(array_values(array_diff(MoneyCheck::RULES, self::SIMULATION_RULES_EXCLUDED)));
        $this->assertNotSame([], $simulation, 'I10 hides it');
    }

    // =====================================================================  guards that hold

    public function test_holds_partial_refunds_and_a_full_refund_keep_the_book_clean(): void
    {
        $invoice = $this->invoice(20);
        (new RecordPaymentService)->handle($invoice, null, 20.0, transactionId: 'wire-101');
        $transaction = $invoice->transactions()->sole();
        ExtensionHelper::refundLocally($transaction, 5.0);
        ExtensionHelper::refundLocally($transaction->fresh(), 15.0);

        $this->assertSame([], (new MoneyCheck)->run());
    }

    public function test_holds_an_overpayment_that_becomes_credit_and_pays_another_invoice(): void
    {
        $first = $this->invoice(10);
        $second = $this->invoice(10);
        $source = (new RecordPaymentService)->handle($first, null, 20.0, transactionId: 'wire-102');
        $this->assertSame(1000, RecordPaymentService::toCents($source->credited_amount));
        (new RecordPaymentService)->allocateFromTransaction($source, $second);

        $this->assertSame([], (new MoneyCheck)->run());
    }

    public function test_holds_processing_then_succeeded_and_a_late_success_on_a_cancelled_invoice(): void
    {
        $gateway = $this->stripe();
        $invoice = $this->invoice(10);
        (new RecordPaymentService)->handle($invoice, $gateway, 10.0, transactionId: 'pi_3AdvProc01', status: InvoiceTransactionStatus::Processing, currency: 'USD');
        $this->assertSame([], (new MoneyCheck)->run(), 'nothing journaled while processing');
        (new RecordPaymentService)->handle($invoice, $gateway, 10.0, transactionId: 'pi_3AdvProc01', currency: 'USD');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $late = $this->invoice(10);
        (new RecordPaymentService)->handle($late, $gateway, 10.0, transactionId: 'pi_3AdvProc02', status: InvoiceTransactionStatus::Processing, currency: 'USD');
        CancelOpenInvoices::handle([$late], 'overdue');
        (new RecordPaymentService)->handle($late, $gateway, 10.0, transactionId: 'pi_3AdvProc02', currency: 'USD');

        $this->assertSame([], (new MoneyCheck)->run());
    }

    public function test_holds_staff_mark_paid_reopening_a_cancelled_invoice_and_deleting_payments(): void
    {
        $cancelled = $this->invoice(10);
        CancelOpenInvoices::handle([$cancelled], 'overdue');
        $transaction = (new RecordPaymentService)->recordByStaff($cancelled->fresh(), null, 10.0, 'wire-103', reopen: true, upToRemaining: true);
        $this->assertSame(Invoice::STATUS_PAID, $cancelled->fresh()->status);
        $this->assertSame([], (new MoneyCheck)->run());

        (new RecordPaymentService)->deleteTransaction($transaction);
        $this->assertSame([], (new MoneyCheck)->run(), 'deleted: the invoice is open again, its events reversed');
    }

    public function test_holds_a_credit_purchase_and_its_refund(): void
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_PENDING, 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Credit::class, 'reference_id' => null, 'description' => 'Credit', 'quantity' => 1, 'price' => 25]);
        (new RecordPaymentService)->handle($invoice->fresh(), null, 25.0, transactionId: 'wire-104');
        $this->assertSame([], (new MoneyCheck)->run());

        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 25.0);
        $this->assertSame([], (new MoneyCheck)->run());
    }

    public function test_holds_a_partial_cancellation_that_pays_the_rest_with_credit_again(): void
    {
        $cancelledService = $this->service(Service::STATUS_ACTIVE);
        $keptService = $this->service(Service::STATUS_ACTIVE);
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_PENDING, 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $cancelledService->id, 'description' => 'A', 'quantity' => 1, 'price' => 10]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $keptService->id, 'description' => 'B', 'quantity' => 1, 'price' => 5]);
        (new CreditLedger)->add($this->user->id, 'USD', 1200, 'staff_adjustment', 'staff', note: 'Goodwill');
        (new RecordPaymentService)->payWithCredit($invoice->fresh());

        CancelOpenInvoices::handle([$invoice], 'service cancelled', $cancelledService);

        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }

    public function test_holds_the_unmatched_logging_ignores_other_paths_and_matched_routes(): void
    {
        $this->stripe();
        $this->get('/extensions/not-a-gateway/x')->assertStatus(404);
        $this->get('/admin/nothing-here-' . 'x')->assertStatus(404);
        $this->stripeWebhook(['type' => 'noise'], false)->assertStatus(400);

        $rows = PaymentGatewayEvent::query()->get();
        $this->assertSame(1, $rows->count());
        $this->assertSame('webhook', $rows->sole()->endpoint, 'a matched route is not logged as no_route');
    }

    public function test_holds_the_prune_keeps_rows_with_money_or_a_verified_signature(): void
    {
        $old = now()->subDays(120);
        $make = fn (array $values) => tap(PaymentGatewayEvent::query()->create(['gateway' => 'stripe', 'endpoint' => 'webhook', 'method' => 'POST'] + $values),
            fn ($row) => DB::table('payment_gateway_events')->where('id', $row->id)->update(['created_at' => $old]));
        $kept = [
            $make(['outcome' => PaymentGatewayEvent::OUTCOME_ERROR, 'reason' => 'http_500']),
            $make(['outcome' => PaymentGatewayEvent::OUTCOME_IGNORED, 'reason' => 'replay']),
            $make(['outcome' => PaymentGatewayEvent::OUTCOME_REJECTED, 'reason' => 'http_400', 'signature_verified' => true]),
            $make(['outcome' => PaymentGatewayEvent::OUTCOME_RECEIVED]),
        ];
        $make(['outcome' => PaymentGatewayEvent::OUTCOME_REJECTED, 'reason' => 'no_route']);

        $this->artisan('mycs:gateway-log-prune')->assertExitCode(0);

        $this->assertSame(collect($kept)->pluck('id')->all(), PaymentGatewayEvent::query()->orderBy('id')->pluck('id')->all());
    }

    public function test_holds_the_command_does_not_mail_the_same_findings_daily_and_mails_a_change(): void
    {
        $invoice = $this->invoice(10);
        (new RecordPaymentService)->handle($invoice, null, 10.0, transactionId: 'wire-105');
        DB::table('invoices')->where('id', $invoice->id)->update(['apply_pending_since' => now()->subDays(2)]);

        for ($day = 0; $day < 6; $day++) {
            $this->artisan('mycs:money-check')->assertExitCode(1);
            $this->travel(1)->days();
        }
        $this->assertSame(1, $this->moneyCheckMails());

        (new CreditLedger)->add($this->user->id, 'USD', 100, 'staff_adjustment', 'staff', note: 'Goodwill');
        DB::table('credits')->where('user_id', $this->user->id)->update(['amount' => 3]);
        $this->artisan('mycs:money-check')->assertExitCode(1);
        $this->assertSame(2, $this->moneyCheckMails(), 'a new finding is mailed');
    }
}
