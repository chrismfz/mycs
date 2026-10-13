<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Regression tests for refunds/deletions arriving before the paid provisioning callback. */
class PaymentMutationAtomicityTest extends TestCase
{
    use DatabaseTruncation;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateDatabaseTables());
        Carbon::setTestNow('2026-10-12 10:00:00');
        config(['settings.credits_enabled' => true]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->where('user_id', $this->user->id)->where('currency_code', 'USD')->value('amount') ?? 0), 2, '.', '');
    }

    private function creditInvoice(): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'pending', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Credit deposit', 'quantity' => 1, 'price' => 50]);

        return $invoice->fresh();
    }

    public function test_refund_between_payment_commit_and_credit_grant_does_not_leave_free_credit(): void
    {
        $invoice = $this->creditInvoice();
        $this->actBetweenPaymentAndGrant($invoice, function ($txn) {
            ExtensionHelper::refundLocally($txn, 50);
        });
        $this->assertSame('50.00', InvoiceTransaction::where('transaction_id', 'CREDIT-RACE')->sole()->refunded_amount);
        $this->assertSame('0.00', $this->credit(), 'Payment fully refunded before grant: no spendable credit may remain');
    }

    public function test_delete_between_payment_commit_and_credit_grant_does_not_leave_free_credit(): void
    {
        $invoice = $this->creditInvoice();
        $this->actBetweenPaymentAndGrant($invoice, function ($txn) {
            (new RecordPaymentService)->deleteTransaction($txn);
        });
        $this->assertSame(0, InvoiceTransaction::where('transaction_id', 'CREDIT-RACE')->count());
        $this->assertSame('0.00', $this->credit(), 'Payment deleted before grant: no spendable credit may remain');
    }

    private function actBetweenPaymentAndGrant(Invoice $invoice, callable $action): void
    {
        $main = DB::getDefaultConnection();
        config(['database.connections.payment_mutation_after_commit' => config("database.connections.{$main}")]);
        Invoice::updating(function ($updating) use ($invoice, $action, $main) {
            if ($updating->id !== $invoice->id || !$updating->isDirty('status') || $updating->status !== 'paid') {
                return;
            }
            // Registered before InvoiceObserver::updated queues provisioning, on the same transaction.
            DB::afterCommit(function () use ($invoice, $action, $main) {
                DB::setDefaultConnection('payment_mutation_after_commit');
                try {
                    $txn = InvoiceTransaction::where('invoice_id', $invoice->id)->where('transaction_id', 'CREDIT-RACE')->sole();
                    $action($txn);
                } finally {
                    DB::setDefaultConnection($main);
                }
            });
        });
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 50, transactionId: 'CREDIT-RACE', currency: 'USD');
    }

    public function test_partial_refund_immediately_after_commit_keeps_only_the_remaining_credit(): void
    {
        $invoice = $this->creditInvoice();
        $this->actBetweenPaymentAndGrant($invoice, fn ($txn) => ExtensionHelper::refundLocally($txn, 20));

        $this->assertSame('20.00', InvoiceTransaction::where('transaction_id', 'CREDIT-RACE')->sole()->refunded_amount);
        $this->assertSame('30.00', $this->credit());
    }

    public function test_failed_credit_grant_rolls_back_payment_status_and_marker(): void
    {
        $invoice = $this->creditInvoice();
        Credit::creating(function () {
            throw new \RuntimeException('Synthetic credit storage failure');
        });

        try {
            ExtensionHelper::addPayment($invoice->id, 'Stripe', 50, transactionId: 'CREDIT-RACE', currency: 'USD');
            $this->fail('The credit failure must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic credit storage failure', $e->getMessage());
        }

        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertNull($invoice->items()->sole()->applied_at);
        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertSame('0.00', $this->credit());
    }

    public function test_staff_marking_paid_grants_credit_before_commit_and_rolls_back_together(): void
    {
        $invoice = $this->creditInvoice();
        DB::beginTransaction();
        try {
            // MT-1c: staff mark it paid by recording the payment (Mark Paid); its status alone no longer does.
            (new RecordPaymentService)->recordByStaff($invoice, null, '50.00');
            $this->assertSame('50.00', $this->credit());
            $this->assertNotNull($invoice->items()->sole()->applied_at);
        } finally {
            DB::rollBack();
        }

        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertNull($invoice->items()->sole()->applied_at);
        $this->assertSame('0.00', $this->credit());
        $this->assertSame(0, $invoice->transactions()->count());
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '50.00');
        $this->assertSame('50.00', $this->credit());
    }

    public function test_stale_staff_model_cannot_mark_a_now_cancelled_invoice_paid(): void
    {
        $invoice = $this->creditInvoice();
        $invoice->fresh()->update(['status' => 'cancelled']);

        try {
            $invoice->update(['status' => 'paid']);
            $this->fail('Current cancelled status must be respected.');
        } catch (DisplayException) {
            $this->assertSame('cancelled', $invoice->fresh()->status);
            $this->assertSame('0.00', $this->credit());
        }
    }
}
