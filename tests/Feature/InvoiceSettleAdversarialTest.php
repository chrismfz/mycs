<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Enums\AdjustmentNoteType;
use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Models\Staff;
use App\Services\Invoice\ChangeOpenLine;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * Adversarial review of PR 2b (docs/payments.md «PR 2b», «Όπως χτίστηκε (2026-10-13)»): an open invoice that is
 * covered closes as paid through RecordPaymentService::settle, the part paid over its total becomes credit once.
 *
 * Findings (a FAILING test is a confirmed violation; the passing ones are guards that must keep holding):
 *  S1 High   The transaction listener still closes a pending invoice brought down below its payments by itself (a
 *            Stripe fee report, any transaction event): paid, the part over never becomes credit (paid_balance).
 *  S2 High   Staff (or the admin API) set «paid» on such an invoice by its status (the form allows it, remaining ≤ 0):
 *            same result, the part over is stranded on a paid invoice and «Close as paid» is gone.
 *  S3 Medium Staff without admin.invoice_transactions.create (no «Close as paid», no «Add Payment») move a payment to
 *            account credit by saving the invoice as draft and back to unpaid: finalising settles it.
 *  S4 High   A mixed credit purchase with a discount: settle marks it paid while the part over is still on it, so the
 *            «credit only for money that stays» guard passes, the full credit is granted AND the part over is credited.
 *  S5 High   A paid credit purchase reopened and its (applied) credit line lowered: settle credits the difference while
 *            the credit already bought stays; the customer holds more credit than they paid.
 *  S6 Low    A line the app reprices below zero (the cron's upgrade refresh, a downgrade prorated) leaves a pending
 *            negative invoice: settle refuses it, the money check now flags it and points to «Close as paid», which is
 *            shown and always fails.
 */
class InvoiceSettleAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Cache::flush();
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    // ---------------------------------------------------------------- helpers

    private function invoice(float $price = 100, ?string $reference = null, ?int $referenceId = null, ?int $userId = null): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $userId ?? $this->user->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(array_filter(['description' => 'Line', 'quantity' => 1, 'price' => $price,
            'reference_type' => $reference, 'reference_id' => $referenceId], fn ($value) => $value !== null));

        return $invoice->fresh();
    }

    private function note(Invoice $invoice, float $amount): void
    {
        $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => $amount, 'description' => 'Discount']);
    }

    private function balanceCents(): int
    {
        return RecordPaymentService::toCents(Credit::query()->where('user_id', $this->user->id)->where('currency_code', 'USD')->value('amount') ?? 0);
    }

    private function stripe(): Gateway
    {
        return Gateway::query()->where('extension', 'Stripe')->sole();
    }

    /** 100.00 invoice, 60.00 paid, its line then lowered to 50.00 by staff: pending, 10.00 over its total. */
    private function reducedBelowPayment(): Invoice
    {
        $invoice = $this->invoice(100);
        (new RecordPaymentService)->handle($invoice, $this->stripe(), 60.0, transactionId: 'pi_1', currency: 'USD');
        $invoice->items()->sole()->update(['price' => 50]);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(-1000, RecordPaymentService::toCents($invoice->fresh()->remaining));

        return $invoice->fresh();
    }

    private function staff(array $permissions): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Staff ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function assertBooksClean(string $context): void
    {
        $findings = (new MoneyCheck)->run();
        $this->assertSame([], $findings, $context . ' ' . json_encode($findings));
    }

    // ================================================================ findings

    /**
     * S1 (High). Scenario: an invoice brought down below what was paid on it waits for staff («Close as paid»). Stripe
     * then reports the fee of that payment (a replay with a fee: $existing->update(['fee'])). The transaction's Updated
     * event runs InvoiceTransactionCreatedListener, which sets the invoice paid by its status because remaining ≤ 0:
     * the 10.00 over never becomes credit, settle no longer applies (it is paid) and the books say paid over 10.00.
     */
    public function test_s1_a_transaction_event_closes_an_invoice_below_its_payments_without_crediting_the_part_over(): void
    {
        $invoice = $this->reducedBelowPayment();

        (new RecordPaymentService)->handle($invoice, $this->stripe(), 60.0, fee: 1.80, transactionId: 'pi_1', currency: 'USD');

        $invoice = $invoice->fresh();
        if ($invoice->status === Invoice::STATUS_PAID) {
            $this->assertSame(1000, $this->balanceCents(), 'S1: closed as paid, but the 10.00 over did not become credit');
        }
        $this->assertBooksClean('S1:');
    }

    /**
     * S2 (High). Scenario: the same invoice, closed by staff with the edit form's status «paid» (the option is enabled:
     * nothing is left to pay) or PUT /api/admin/invoices/{id} status=paid. InvoiceObserver::updating only refuses a
     * positive balance, so the invoice is paid with 10.00 over its total that never becomes credit.
     */
    public function test_s2_staff_setting_paid_by_status_strands_the_part_over_on_a_paid_invoice(): void
    {
        $invoice = $this->reducedBelowPayment();

        try {
            $invoice->update(['status' => Invoice::STATUS_PAID]);
        } catch (DisplayException) {
            // refusing it (use «Close as paid») is a fix too. After the fix: refused, and staff close it with the action
            // (RecordPaymentService::settle), which credits the part over.
            $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
            $this->assertTrue((new RecordPaymentService)->settle($invoice));
        }

        $invoice = $invoice->fresh();
        if ($invoice->status === Invoice::STATUS_PAID) {
            $this->assertSame(1000, $this->balanceCents(), 'S2: paid by its status, the 10.00 over did not become credit');
        }
        $this->assertBooksClean('S2:');
    }

    /**
     * S3 (Medium). Scenario: a staff role with invoices view/update but NOT admin.invoice_transactions.create. «Close as
     * paid» is hidden from it (it moves money to credit: «the permission Add Payment needs too»). The same role saves
     * the invoice as draft, then as pending: InvoiceObserver settles a finalised draft, and 10.00 of the payment
     * becomes account credit (spendable on any invoice) without the permission.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_s3_staff_without_payment_permission_move_a_payment_to_credit_by_a_draft_round_trip(): void
    {
        $invoice = $this->reducedBelowPayment();
        $clerk = $this->staff(['admin.invoices.viewAny', 'admin.invoices.viewAny', 'admin.invoices.update']);

        Livewire::actingAs($clerk)->test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionHidden('closeAsPaid');

        // What the edit form's status select saves, twice.
        $this->actingAs($clerk);
        $invoice->update(['status' => Invoice::STATUS_DRAFT]);
        $invoice->fresh()->update(['status' => Invoice::STATUS_UNPAID]);

        $this->assertSame(0, $this->balanceCents(), 'S3: a staff role without payment permissions moved a payment to credit');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'S3: a staff edit closed it on its own');
    }

    /**
     * S4 (High). Scenario: a credit purchase of 100.00 with another line of 20.00; the customer pays 100.00, staff write a
     * 30.00 discount (total 90.00, 10.00 over) and «Close as paid». settle saves «paid» FIRST, while the 10.00 over still
     * counts as received, so InvoiceObserver's «credit only for money that arrived and stays» (100.00 to give, 100.00
     * received) passes; then it credits the 10.00. The customer paid 100.00 and holds 110.00 of credit. Had the discount
     * been written before the payment, the same invoice could never become paid (100.00 to give > 90.00 received).
     */
    public function test_s4_a_discounted_mixed_credit_purchase_gives_more_credit_than_was_paid(): void
    {
        $invoice = $this->invoice(100, Credit::class);
        $invoice->items()->create(['description' => 'Setup', 'quantity' => 1, 'price' => 20]);
        (new RecordPaymentService)->handle($invoice->fresh(), null, 100.0, transactionId: 'wire-1');
        $this->note($invoice, -30);

        try {
            (new RecordPaymentService)->settle($invoice);
        } catch (DisplayException) {
            // refusing it is a fix too
        }

        $this->assertLessThanOrEqual(10000, $this->balanceCents(), 'S4: 100.00 paid, more than 100.00 of credit given');
    }

    /**
     * S5 (High). Scenario: a credit purchase of 100.00, paid: 100.00 of credit given (its line applied). Staff set it
     * back to unpaid to correct it («Τα paid τιμολόγια ... το staff τα γυρίζει σε pending»), lower the credit line to
     * 70.00 and «Close as paid». settle credits the 30.00 over; the 100.00 the applied line gave stays. 100.00 paid, 130.00
     * of credit.
     */
    public function test_s5_lowering_an_applied_credit_line_then_closing_credits_the_difference_on_top_of_the_bought_credit(): void
    {
        $invoice = $this->invoice(100, Credit::class);
        (new RecordPaymentService)->handle($invoice, null, 100.0, transactionId: 'wire-1');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(10000, $this->balanceCents());

        $invoice->fresh()->update(['status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->sole()->update(['price' => 70]);

        try {
            (new RecordPaymentService)->settle($invoice);
        } catch (DisplayException) {
            // refusing it is a fix too
        }

        $this->assertLessThanOrEqual(10000, $this->balanceCents(), 'S5: 100.00 paid, more than 100.00 of credit held');
    }

    /**
     * S6 (Low). Scenario: the cron's upgrade refresh (CronJob::refreshUpgrade) sets an upgrade's line to its current
     * price through ChangeOpenLine, and ServiceUpgrade::calculatePrice is negative for a downgrade (Livewire Upgrade
     * turns that into credit at order time). The invoice is left pending with a negative total and no payment: settle
     * refuses it (nothing came in to give back), the money check reports open_settled «(Close as paid)», and the
     * action is shown but always fails. Before 2b the check did not flag it; now nothing in the app can clear it.
     */
    public function test_s6_an_app_repriced_negative_line_is_flagged_for_close_as_paid_which_cannot_close_it(): void
    {
        $invoice = $this->invoice(15);
        $line = $invoice->items()->sole();

        ChangeOpenLine::handle($line, fn ($locked) => $locked->price = -5);

        // After the fix (author): the app no longer writes a negative price (CronJob::refreshUpgrade clamps the upgrade's
        // price at 0, see the guard below); a negative invoice made by hand is left to staff: «Close as paid» is not
        // offered (settleRefusal) and the money check says «by hand».
        $invoice = $invoice->fresh(['items', 'transactions', 'adjustmentNotes']);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertNotNull(RecordPaymentService::settleRefusal($invoice), 'S6: «Close as paid» is offered but cannot close it');
        $findings = (new MoneyCheck)->run(['open_settled']);
        $this->assertStringContainsString('by hand', $findings[0]['detail'] ?? '');
    }

    /** S6 at the source: the cron's upgrade refresh never writes a negative price on an upgrade's line. */
    public function test_guard_the_cron_never_reprices_an_upgrade_below_zero(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 50, 'quantity' => 1, 'expires_at' => now()->addDays(20)]);
        $target = $this->createProduct();
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id, 'invoice_id' => $invoice->id]);
        $invoice->items()->create(['reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 5]);

        $this->artisan('app:cron-job');

        $this->assertGreaterThanOrEqual(0, RecordPaymentService::toCents($invoice->items()->sole()->price));
    }

    // ================================================================ guards (must keep holding)

    /** Deleting the payment whose part became credit takes exactly that credit back, and the invoice reopens. */
    public function test_guard_deleting_a_settled_payment_takes_its_credit_back_once(): void
    {
        $invoice = $this->reducedBelowPayment();
        $this->assertTrue((new RecordPaymentService)->settle($invoice));
        $this->assertSame(1000, $this->balanceCents());

        (new RecordPaymentService)->deleteTransaction($invoice->transactions()->sole());

        $this->assertSame(0, $this->balanceCents());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(5000, RecordPaymentService::toCents($invoice->fresh()->remaining));
        $this->assertBooksClean('guard delete:');
    }

    /** A settled payment refunds only what stayed on the invoice: the credited part is not refundable as well. */
    public function test_guard_the_credited_part_is_not_refundable_too(): void
    {
        $invoice = $this->reducedBelowPayment();
        $this->assertTrue((new RecordPaymentService)->settle($invoice));
        $payment = $invoice->transactions()->sole();

        try {
            ExtensionHelper::refundLocally($payment, 60.0);
            $this->fail('the whole payment was refunded although 10.00 of it is credit');
        } catch (InvalidArgumentException) {
        }
        ExtensionHelper::refundLocally($payment->fresh(), 50.0);

        $this->assertSame(1000, $this->balanceCents());
        // All of what stayed on it went back: refunded since 2c (it was paid before).
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        $this->assertBooksClean('guard refund:');
    }

    /** A credit payment settled back to credit, then deleted: the journal and the balance agree, nothing doubles. */
    public function test_guard_a_settled_credit_payment_deleted_afterwards_keeps_the_ledger_whole(): void
    {
        (new CreditLedger)->add($this->user->id, 'USD', 4000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Goodwill');
        $invoice = $this->invoice(100);
        (new RecordPaymentService)->handle($invoice, null, 60.0, transactionId: 'wire-1');
        (new RecordPaymentService)->payWithCredit($invoice->fresh());
        $invoice->fresh()->update(['status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->sole()->update(['price' => 80]);
        $this->assertTrue((new RecordPaymentService)->settle($invoice));
        $this->assertSame(2000, $this->balanceCents(), '20.00 of the credit payment came back');

        $credit = $invoice->transactions()->where('is_credit_transaction', true)->sole();
        (new RecordPaymentService)->deleteTransaction($credit);

        $this->assertSame(4000, $this->balanceCents(), 'all 40.00 back, once');
        $this->assertBooksClean('guard credit payment:');
    }

    /** No customer: the part over has no account to go to, nothing is closed or credited. */
    public function test_guard_an_invoice_without_customer_with_a_part_over_is_not_closed(): void
    {
        $invoice = $this->reducedBelowPayment();
        DB::table('invoices')->where('id', $invoice->id)->update(['user_id' => null]);

        $this->assertFalse((new RecordPaymentService)->settle($invoice->id));
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(0, $this->balanceCents());
    }

    /** A line of a cancelled service repriced to nothing: the invoice closes, the service is not revived. */
    public function test_guard_a_free_line_of_a_cancelled_service_does_not_revive_it(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_CANCELLED, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->subDay()]);
        $invoice = $this->invoice(10, Service::class, $service->id);

        ChangeOpenLine::handle($invoice->items()->sole(), fn ($locked) => $locked->price = 0);

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertBooksClean('guard cancelled service:');
    }

    /** Settled once: a second settle, and a late payment for the same invoice, never credit the part over twice. */
    public function test_guard_settle_twice_then_a_late_payment_credits_each_cent_once(): void
    {
        $invoice = $this->reducedBelowPayment();
        $this->assertTrue((new RecordPaymentService)->settle($invoice));
        $this->assertFalse((new RecordPaymentService)->settle($invoice));
        (new RecordPaymentService)->handle($invoice->fresh(), $this->stripe(), 5.0, transactionId: 'pi_2', currency: 'USD');

        $this->assertSame(1500, $this->balanceCents());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(0, RecordPaymentService::toCents($invoice->fresh()->transactions()->where('transaction_id', 'pi_2')->sole()->amount)
            - RecordPaymentService::toCents($invoice->fresh()->transactions()->where('transaction_id', 'pi_2')->sole()->credited_amount));
        $this->assertBooksClean('guard twice:');
    }

    /** A processing payment does not count: settle closes on what arrived; the late success becomes credit. */
    public function test_guard_processing_payment_is_not_counted_by_settle(): void
    {
        $invoice = $this->invoice(100);
        (new RecordPaymentService)->handle($invoice, $this->stripe(), 100.0, transactionId: 'pi_sepa', status: InvoiceTransactionStatus::Processing, currency: 'USD');
        $this->assertFalse((new RecordPaymentService)->settle($invoice), 'nothing arrived yet');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }
}
