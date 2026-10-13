<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGatewayEvent;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\GatewayLog;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Monolog\Level;
use Tests\TestCase;

/**
 * Code review of MT-3 (feat/mt-3-money-check): one failing test per finding. Synthetic data only.
 */
class MoneyCheckCodeReviewTest extends TestCase
{
    use RefreshDatabase;

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

    private function invoice(float $price = 10, string $status = Invoice::STATUS_UNPAID): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => $status, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    /**
     * #1 The per-IP row throttle drops every row of an address, not only refused ones. Behind a reverse proxy with no
     * trusted_proxies (the default) every request has the proxy's address, so 120 forged requests a minute silence the
     * log for all gateways: a genuine request that fails (5xx) writes no row and staff get no «Gateway request failed».
     */
    public function test_a_failing_gateway_request_is_logged_and_alerted_even_after_a_flood_from_its_address(): void
    {
        Route::post('/extensions/reviewgw/webhook', fn () => throw new \RuntimeException('renewal failed'))
            ->name('extensions.gateways.reviewgw.webhook');
        app('router')->getRoutes()->refreshNameLookups();

        $key = 'gateway-log:' . sha1('127.0.0.1');
        for ($i = 0; $i < GatewayLog::ROWS_PER_MINUTE; $i++) {
            RateLimiter::hit($key, 60); // forged requests from the same (proxy) address
        }

        $this->post('/extensions/reviewgw/webhook')->assertStatus(500);

        $this->assertSame(1, PaymentGatewayEvent::query()->where('outcome', PaymentGatewayEvent::OUTCOME_ERROR)->count(),
            'a 5xx from a gateway route must always be logged: the throttle is meant for refused rows only');
        $this->assertSame(1, DB::table('email_logs')->where('subject', 'Gateway request failed')->count(),
            'staff must still hear of the failed request');
    }

    /**
     * #2 The «payments» channel takes LOG_LEVEL: with LOG_LEVEL=error (a common production setting) it drops every
     * PaymentsLog::warning/info, so the log kept for a year holds nothing.
     */
    public function test_the_payments_channel_keeps_warnings_whatever_the_app_log_level(): void
    {
        $saved = [$_ENV['LOG_LEVEL'] ?? null, $_SERVER['LOG_LEVEL'] ?? null, getenv('LOG_LEVEL')];
        $_ENV['LOG_LEVEL'] = $_SERVER['LOG_LEVEL'] = 'error';
        putenv('LOG_LEVEL=error');
        try {
            $config = require config_path('logging.php');
        } finally {
            [$env, $server, $put] = $saved;
            if ($env === null) {
                unset($_ENV['LOG_LEVEL']);
            } else {
                $_ENV['LOG_LEVEL'] = $env;
            }
            if ($server === null) {
                unset($_SERVER['LOG_LEVEL']);
            } else {
                $_SERVER['LOG_LEVEL'] = $server;
            }
            putenv($put === false ? 'LOG_LEVEL' : 'LOG_LEVEL=' . $put);
        }

        $level = Level::fromName((string) $config['channels']['payments']['level']);
        $this->assertTrue($level->includes(Level::Warning), "payments channel level is {$level->getName()}: money warnings are dropped");
        $this->assertTrue($level->includes(Level::Info), 'and so is «money-check: nothing found.»');
    }

    /**
     * #3 payment_not_journaled and journal_without_payment load every succeeded transaction and every payment event in
     * one query each (no chunking), while paid_balance chunks by 500. On a book with tens of thousands of payments
     * (myip: > 32k invoices) that is two full-table hydrations per run (and per fuzz step).
     */
    public function test_the_journal_rules_read_transactions_and_events_in_chunks(): void
    {
        $invoice = $this->invoice(10);
        (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'chunk-1');

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });
        (new MoneyCheck)->run(['payment_not_journaled', 'journal_without_payment']);

        $unbounded = array_values(array_filter($queries, fn (string $sql) => str_starts_with($sql, 'select')
            && (str_contains($sql, 'from "invoice_transactions"') || str_contains($sql, 'from `invoice_transactions`')
                || str_contains($sql, 'from "money_events"') || str_contains($sql, 'from `money_events`'))
            && !str_contains($sql, ' limit ')));
        $this->assertSame([], $unbounded, 'whole-table reads: ' . implode(' | ', $unbounded));
    }

    /**
     * #4 BillingCursorFuzzTest::pay() now goes through RecordPaymentService with $invoice->remaining. On a cancelled
     * invoice that is always 0 (its cancellation note), or negative after cancel_paid, so handle() refuses it at once:
     * the pay_cancelled action (and the second pay of pay_twice) no longer reaches any payment code, and its check
     * «cancelled invoice became paid» can never fire. The extra mt_rand() in the transaction id also shifts every seed's
     * random sequence, so recorded failing seeds no longer replay.
     */
    public function test_the_fuzz_pay_on_a_cancelled_invoice_still_exercises_the_payment_path(): void
    {
        $invoice = $this->invoice(10);
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        $invoice = $invoice->fresh();

        // What BillingCursorFuzzTest::pay() does after the fix: nothing left on it, so its lines' total.
        $amount = $invoice->remaining > 0 ? $invoice->remaining : $invoice->items->sum(fn ($line) => (float) $line->price * (int) $line->quantity);
        (new RecordPaymentService)->handle($invoice, null, $amount, transactionId: 'sim-1');

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertGreaterThan(0, InvoiceTransaction::query()->where('invoice_id', $invoice->id)->count(),
            'pay_cancelled must put money on the cancelled invoice (it becomes credit), or it tests nothing');
    }
}
