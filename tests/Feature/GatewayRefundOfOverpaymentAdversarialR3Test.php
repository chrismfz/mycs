<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\MergeInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use Tests\TestCase;

/**
 * Round-3 adversarial review of #552, aimed at 7e177e68 (since reverted, owner «keep it simple»: allocateFromTransaction
 * is main's again): it paid min(budget, remaining)
 * with mayGoNegative (a subscription sale's renewals and the merged-invoice path, as MassPay/PayInvoices already did).
 * test_bug_* fail on the PR's head and name a hole; test_holds_* are guarantees attacked that held.
 */
class GatewayRefundOfOverpaymentAdversarialR3Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 10:00:00');
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.credits_enabled' => true,
            'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $subscription): Service
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 10.00]);

        return Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-10-15', 'currency_code' => 'USD', 'price' => 10.00, 'subscription_id' => $subscription,
        ]);
    }

    private function renewal(Service $service): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => '2026-10-15']);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10.00, 'period_start' => '2026-10-15', 'period_end' => '2026-11-15']);

        return $invoice->fresh();
    }

    private function manual(float $price, string $due = '2026-10-01'): Invoice
    {
        $invoice = Invoice::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD', 'due_at' => $due, 'status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->create(['description' => 'Manual work', 'price' => $price, 'quantity' => 1]);

        return $invoice->fresh();
    }

    private function credit(): float
    {
        return (float) (Credit::query()->where('client_id', $this->user->client()->id)->where('currency_code', 'USD')->value('amount') ?? 0);
    }

    /** @param list<string> $except */
    private function assertMoneyCheckClean(array $except = []): void
    {
        $findings = array_values(array_filter((new MoneyCheck)->run(), fn ($finding) => !in_array($finding['rule'] ?? null, $except, true)));
        $this->assertSame([], $findings, json_encode($findings));
    }

    private function staffMails(): int
    {
        return EmailLog::query()->where('to', 'staff@example.test')->count();
    }

    /**
     * A PayPal subscription sale of 20 for services A and B (10 each): it lands on A, and its allocation to B is
     * interrupted (as in CodexPaymentFollowupReviewTest): the sale is committed, 10 sits as credit, PayPal retries later.
     *
     * @return array{0: Invoice, 1: Invoice, 2: array<string, mixed>}
     */
    private function interruptedSale(): array
    {
        $invoiceA = $this->renewal($this->service('I-R3'));
        $invoiceB = $this->renewal($this->service('I-R3'));
        $resource = ['id' => 'SALE-R3', 'billing_agreement_id' => 'I-R3', 'amount' => ['total' => '20.00', 'currency' => 'USD']];
        $failOnce = true;
        InvoiceTransaction::creating(function ($txn) use (&$failOnce, $invoiceB) {
            if ($failOnce && $txn->invoice_id === $invoiceB->id && $txn->is_credit_transaction) {
                $failOnce = false;
                throw new \RuntimeException('interrupted');
            }
        });
        try {
            (new PayPal([]))->recordSubscriptionSale($resource);
            $this->fail('the interruption was not injected');
        } catch (\RuntimeException $e) {
            $this->assertSame('interrupted', $e->getMessage());
        }
        $this->assertSame([Invoice::STATUS_PAID, Invoice::STATUS_UNPAID, 10.0], [$invoiceA->fresh()->status, $invoiceB->fresh()->status, $this->credit()]);

        return [$invoiceA, $invoiceB, $resource];
    }

    // ---- ADV-552-R3-1: the sale's credit, spent meanwhile, is spent again by the retried allocation: a new, silent debt

    public function test_bug_a_retried_subscription_allocation_spends_again_credit_the_daily_pass_already_spent(): void
    {
        [, $invoiceB, $resource] = $this->interruptedSale();
        // Before PayPal's retry, the cron's first step (credit_applied, the daily pass) spends the 10 on the customer's
        // older open invoice X (not a subscription's: eligible). Nobody chose anything wrong.
        config(['settings.credits_auto_use' => true]);
        $x = $this->manual(10.00);
        (new CreditAutoApply)->sweep();
        $this->assertSame([Invoice::STATUS_PAID, 0.0], [$x->fresh()->status, $this->credit()], 'precondition: the daily pass paid X');
        $mails = $this->staffMails();

        (new PayPal([]))->recordSubscriptionSale($resource);

        // As on main (7e177e68 reverted), the retry finds no credit left and B stays open (to be paid by the customer or the
        // next sale). On 7e177e68 the same 10 paid B too: X and B both paid from one 10, the credit is −10, a debt the customer never caused
        // (decision 1 / R2-1 was about a debt that existed *before* the sale), and no one is told (refundOverpaidCredit
        // tells staff of the debt it makes; this path writes it silently). Either keep B open when the sale's credit was
        // spent elsewhere, or tell staff.
        $this->assertTrue($this->credit() >= 0 || $this->staffMails() > $mails,
            'credit ' . $this->credit() . ', B ' . $invoiceB->fresh()->status . ': the 10 paid X and B, the debt was written silently');
    }

    // ---- guarantees attacked that held

    public function test_holds_a_retried_allocation_after_a_dashboard_refund_of_the_unallocated_part_pays_nothing(): void
    {
        [$invoiceA, $invoiceB, $resource] = $this->interruptedSale();

        ExtensionHelper::refundedAtGateway('PayPal', 'SALE-R3', 'RF-R3', 10.00, 'USD');
        $this->assertSame(0.0, $this->credit(), 'the refund took the sale\'s unallocated 10 from the credit');

        (new PayPal([]))->recordSubscriptionSale($resource);

        $this->assertSame([Invoice::STATUS_PAID, Invoice::STATUS_UNPAID, 0.0], [$invoiceA->fresh()->status, $invoiceB->fresh()->status, $this->credit()]);
        $sale = InvoiceTransaction::query()->where('transaction_id', 'SALE-R3')->sole();
        $this->assertSame(['10.00', '0.00'], [(string) $sale->refunded_amount, (string) $sale->credited_amount]);
        $this->assertSame(0, InvoiceTransaction::query()->where('source_transaction_id', $sale->id)->count());
        $this->assertMoneyCheckClean();
    }

    public function test_holds_replays_after_a_completed_allocation_never_pay_again_even_with_a_debt(): void
    {
        [, $invoiceB, $resource] = $this->interruptedSale();
        (new PayPal([]))->recordSubscriptionSale($resource);
        $this->assertSame([Invoice::STATUS_PAID, 0.0], [$invoiceB->fresh()->status, $this->credit()]);
        // A debt appears (staff take credit back by hand), and a new renewal of B's service opens.
        Credit::query()->where('client_id', $this->user->client()->id)->update(['amount' => -5]);
        $next = $this->renewal(Service::query()->orderByDesc('id')->first());

        (new PayPal([]))->recordSubscriptionSale($resource);
        (new PayPal([]))->recordSubscriptionSale($resource);

        $sale = InvoiceTransaction::query()->where('transaction_id', 'SALE-R3')->sole();
        $this->assertSame(1, InvoiceTransaction::query()->where('source_transaction_id', $sale->id)->count());
        $this->assertSame([Invoice::STATUS_UNPAID, -5.0], [$next->fresh()->status, $this->credit()]);
    }

    public function test_holds_after_the_double_spend_a_dashboard_refund_of_the_sale_never_doubles_the_debt(): void
    {
        $this->markTestSkipped('Owner 2026-10-30, keep it simple: a dashboard refund never makes a debt, and the allocation change (R2-1) was reverted.');
        [$invoiceA, , $resource] = $this->interruptedSale();
        config(['settings.credits_auto_use' => true]);
        $this->manual(10.00);
        (new CreditAutoApply)->sweep();
        (new PayPal([]))->recordSubscriptionSale($resource);
        $debt = $this->credit();

        ExtensionHelper::refundedAtGateway('PayPal', 'SALE-R3', 'RF-R3', 10.00, 'USD');

        // The sale's credited part is all allocated: the refund is A's, never taken from the credit a second time.
        $this->assertSame($debt, $this->credit());
        $this->assertSame(-10.0, (float) $invoiceA->fresh()->adjustmentNotes()->sum('amount'));
        $this->assertMoneyCheckClean(['credit_negative']);
    }

    public function test_holds_a_payment_on_a_merged_invoice_pays_the_invoice_it_went_to_and_a_debt_stays_a_debt(): void
    {
        $this->markTestSkipped('Owner 2026-10-30, keep it simple: a dashboard refund never makes a debt, and the allocation change (R2-1) was reverted.');
        $target = $this->manual(10.00);
        $merged = $this->manual(15.00);
        (new MergeInvoices)->handle($target->id, [$merged->id]);
        $this->assertSame([Invoice::STATUS_CANCELLED, $target->id], [$merged->fresh()->status, (int) $merged->fresh()->merged_into_id]);
        Credit::query()->updateOrCreate(['client_id' => $this->user->client()->id, 'currency_code' => 'USD'], ['amount' => -30]);

        (new RecordPaymentService)->handle($merged->id, null, 15.00, transactionId: 'late-transfer');

        // Owner's rule (decision 1, R2-1): the payment pays what it was for; the older debt stays as it was.
        $this->assertSame(['10.00', -30.0], [number_format((float) $target->fresh()->remaining, 2, '.', ''), $this->credit()]);
    }

    public function test_holds_a_payment_on_a_merged_invoice_reaches_it_before_auto_apply_and_makes_no_debt(): void
    {
        config(['settings.credits_auto_use' => true]);
        $target = $this->manual(10.00, '2026-10-20');
        $merged = $this->manual(15.00, '2026-10-20');
        (new MergeInvoices)->handle($target->id, [$merged->id]);
        $older = $this->manual(25.00, '2026-09-01'); // due first: auto-apply would pick it

        (new RecordPaymentService)->handle($merged->id, null, 25.00, transactionId: 'late-transfer');

        $this->assertSame([Invoice::STATUS_PAID, Invoice::STATUS_UNPAID, 0.0], [$target->fresh()->status, $older->fresh()->status, $this->credit()]);
        $this->assertMoneyCheckClean();
    }
}
