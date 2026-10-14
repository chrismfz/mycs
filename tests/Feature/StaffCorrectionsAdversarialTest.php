<?php

namespace Tests\Feature;

use App\Admin\Actions\VoidAdjustmentNote;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Events\InvoiceTransaction\Created as TransactionCreated;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\AdjustmentNote;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyTrail;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use Tests\TestCase;

/**
 * Adversarial review of MT-1c (PR #53, docs/money-trail.md): staff corrections.
 *  I1 no invoice becomes paid while something is left to pay, by any path (and its consequences: services renewed,
 *     bought credit granted, without money);
 *  I2 recordByStaff (Mark Paid): one payment through handle(), reopening atomic, replays change nothing, its date;
 *  I3 negative credit only on an explicit deleteTransaction(..., allowNegativeCredit: true), journal == balance;
 *  I4 adjustment notes append-only, refund notes never voided;
 *  I5 no regression on the money paths.
 * A FAILING test is a confirmed violation; passing ones stay as regression coverage.
 */
class StaffCorrectionsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['settings.credits_enabled' => true]);
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Cache::flush();
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    // ---------------------------------------------------------------- helpers

    private function invoice(float $price = 100, string $status = Invoice::STATUS_UNPAID, ?string $referenceType = null, ?int $referenceId = null, ?User $user = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => ($user ?? $this->user)->client()->id, 'status' => $status === Invoice::STATUS_PAID ? Invoice::STATUS_UNPAID : $status, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(array_filter([
            'description' => 'Line', 'quantity' => 1, 'price' => $price,
            'reference_type' => $referenceType, 'reference_id' => $referenceId,
        ], fn ($value) => $value !== null));

        // A paid fixture (a period paid in the past): stored paid, its lines added while it was open (MT-1c: a paid
        // invoice keeps its lines, and is never created paid).
        if ($status === Invoice::STATUS_PAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        }

        return $invoice->fresh();
    }

    private function note(Invoice $invoice, float $amount, string $description = 'Goodwill'): AdjustmentNote
    {
        return $invoice->adjustmentNotes()->create([
            'type' => $amount < 0 ? AdjustmentNoteType::Credit->value : AdjustmentNoteType::Debit->value,
            'amount' => $amount, 'description' => $description,
        ]);
    }

    private function service(string $expires = '2027-01-15'): Service
    {
        $product = $this->createProduct();
        Plan::query()->whereKey($product->plan->id)->update(['billing_unit' => 'month', 'billing_period' => 1]);

        return Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 100.00,
        ]);
    }

    private static function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function balanceCents(?User $user = null): int
    {
        return self::cents(Credit::query()->where('client_id', ($user ?? $this->user)->client()->id)->where('currency_code', 'USD')->value('amount') ?? 0);
    }

    private function apiToken(array $permissions): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 'adv', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token), 'permissions' => $permissions]);

        return $token;
    }

    private function owner(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
    }

    private function assertNotPaidWithBalance(Invoice $invoice, string $context): void
    {
        $invoice = $invoice->fresh();
        $this->assertFalse($invoice->status === Invoice::STATUS_PAID && self::cents($invoice->remaining) > 0,
            "I1: invoice #{$invoice->id} is paid with {$invoice->remaining} left to pay. {$context}");
    }

    // ================================================================ I1: findings

    /**
     * I1 via the admin API (the B1 actor: an API key). The line is zeroed, the invoice marked paid (nothing left), the
     * line restored: a paid invoice with 100.00 to pay, and its service renewed one period without any money.
     */
    public function test_api_line_edits_around_the_paid_flip_never_leave_a_paid_invoice_with_a_balance(): void
    {
        $service = $this->service();
        $this->invoice(100, Invoice::STATUS_CANCELLED, Service::class, $service->id); // an earlier line: not the order's own
        $invoice = $this->invoice(100, referenceType: Service::class, referenceId: $service->id);
        $item = $invoice->items()->sole();
        $token = $this->apiToken(['admin.invoices.update', 'admin.invoice_items.update']);

        $codes[] = $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $item), ['price' => 0])->status();
        $codes[] = $this->withToken($token)->putJson(route('api.v1.admin.invoices.update', $invoice), ['status' => 'paid'])->status();
        $codes[] = $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $item), ['price' => 100])->status();

        $this->assertNotPaidWithBalance($invoice, 'HTTP ' . implode('/', $codes) . '; service expires_at 2027-01-15 -> ' . $service->fresh()->expires_at?->toDateString()
            . ', transactions: ' . InvoiceTransaction::query()->count());
    }

    /** I1 via the admin API: an invoice with no line is «paid» (nothing to pay), then gets its lines. */
    public function test_api_lines_added_after_the_paid_flip_never_leave_a_paid_invoice_with_a_balance(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $token = $this->apiToken(['admin.invoices.update', 'admin.invoice_items.create']);

        $this->withToken($token)->putJson(route('api.v1.admin.invoices.update', $invoice), ['status' => 'paid']);
        $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), [
            'invoice_id' => $invoice->id, 'description' => 'Hosting', 'price' => 250, 'quantity' => 1,
        ]);

        $this->assertNotPaidWithBalance($invoice, 'lines added to a paid invoice');
    }

    /**
     * I1 via the admin form, ONE save: Filament saves the invoice (status) first and its lines (repeater relationship)
     * after. A line at 0.00 raised to 100.00 together with «Paid»: the observer sees the old lines.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_admin_form_never_marks_paid_while_raising_a_line_in_the_same_save(): void
    {
        $service = $this->service();
        $invoice = $this->invoice(0, referenceType: Service::class, referenceId: $service->id);

        $component = $this->livewireAs($this->owner())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $key = array_key_first($component->get('data.items'));
        $thrown = null;
        try {
            $component->set("data.items.{$key}.price", '100.00')
                ->set('data.status', Invoice::STATUS_PAID)
                ->call('save');
        } catch (\Throwable $e) {
            // Held: Filament saves the repeater's lines inside getState(), BEFORE the record, so the observer sees
            // 100.00 and refuses. But the refusal escapes as an uncaught DisplayException (no Halt, panel without DB
            // transactions): the lines stay raised, the status unsaved, the user gets an error instead of a notice.
            $thrown = $e::class;
        }

        $this->assertNotPaidWithBalance($invoice, 'thrown: ' . ($thrown ?? 'nothing') . '; service expires_at 2027-01-15 -> ' . $service->fresh()->expires_at?->toDateString());
    }

    /**
     * I1 consequence (GrantBoughtCreditService), and the doc's own claim «a credit purchase invoice no longer becomes
     * paid without a payment»: a staff credit note covers the credit line, «Paid» is allowed (nothing left), the
     * customer receives 500.00 of credit for no money. Voiding the note afterwards leaves a paid invoice owing 500.
     */
    public function test_a_credit_purchase_never_grants_credit_without_a_payment(): void
    {
        $invoice = $this->invoice(500, referenceType: Credit::class);
        $note = $this->note($invoice, -500, 'Discount');

        try {
            $invoice->fresh()->update(['status' => Invoice::STATUS_PAID]);
        } catch (DisplayException) {
        }
        $granted = $this->balanceCents();
        try {
            $note->update(['status' => AdjustmentNoteStatus::Voided]); // what VoidAdjustmentNote does
        } catch (DisplayException) {
        }

        $this->assertSame(0, $granted, 'Credit was granted by a credit purchase that received no money (status ' . $invoice->fresh()->status . ').');
        $this->assertNotPaidWithBalance($invoice, 'after voiding the note that covered it');
    }

    /**
     * I1 / I4 interplay: voiding a staff discount on an invoice already paid (Mark Paid for what was left) leaves it
     * paid with the discount still to pay. VoidAdjustmentNote checks neither the invoice status nor (on the global
     * Adjustment Notes list) immutable invoices.
     */
    public function test_voiding_a_note_never_leaves_a_paid_invoice_with_a_balance(): void
    {
        $invoice = $this->invoice(100);
        $discount = $this->note($invoice, -20, 'Discount');
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '80.00', 'TRANSFER-80');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        // The action's own rule (0e74b081): policy, staff note, immutable invoices only while pending. Immutable off.
        $this->actingAs($this->owner());
        $allowed = VoidAdjustmentNote::allowed($discount->fresh());
        if ($allowed) {
            try {
                $discount->update(['status' => AdjustmentNoteStatus::Voided]); // what VoidAdjustmentNote does, under the locks
            } catch (DisplayException) {
            }
        }

        $this->assertNotPaidWithBalance($invoice, 'after voiding a discount on a paid invoice (VoidAdjustmentNote::allowed: ' . var_export($allowed, true) . ')');
    }

    /**
     * I1 / decision 1 («the credit and the status of the invoice follow»): deleting the payment of a paid invoice leaves
     * it paid with everything to pay, its services renewed. With allowNegativeCredit this is now reachable even when
     * the overpaid part was spent.
     */
    public function test_deleting_the_payment_of_a_paid_invoice_never_leaves_it_paid_with_a_balance(): void
    {
        $overpaid = $this->invoice(100);
        ExtensionHelper::addPayment($overpaid->id, 'Stripe', 150.00, null, 'pi_over', currency: 'USD');
        (new RecordPaymentService)->payWithCredit($this->invoice(50));
        $this->assertSame(Invoice::STATUS_PAID, $overpaid->fresh()->status);

        (new RecordPaymentService)->deleteTransaction($overpaid->transactions()->sole(), allowNegativeCredit: true);

        $this->assertSame(-5000, $this->balanceCents());
        $this->assertSame(0, Artisan::call('mycs:credits-check'));
        $this->assertNotPaidWithBalance($overpaid, 'after deleting its only payment');
    }

    /**
     * I1 by code: the docs say an invoice «is never created paid»; only the API request and the form refuse it, the
     * model does not (InvoiceObserver has no creating guard).
     */
    public function test_an_invoice_is_never_created_paid(): void
    {
        try {
            $invoice = Invoice::query()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_PAID, 'currency_code' => 'USD']);
            $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);
        } catch (DisplayException) {
            $this->assertSame(0, Invoice::query()->where('status', Invoice::STATUS_PAID)->count());

            return;
        }

        $this->assertNotPaidWithBalance($invoice, 'created paid by code');
    }

    // ================================================================ I4: refund notes now irreversible

    /**
     * A refund of a partial payment on an UNPAID invoice writes a refund note that lowers the total by the refunded
     * amount: the customer got the money back and owes 60.00 instead of 100.00. Before MT-1c staff could void that note;
     * now it is never voidable, so the forgiven 40.00 cannot be corrected. Mark Paid 60.00 then renews for 60.00 net.
     */
    public function test_refunding_a_partial_payment_of_an_unpaid_invoice_does_not_forgive_the_refund(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 40.00, null, 'pi_part', currency: 'USD');
        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 40.00);

        $this->assertSame(10000, self::cents($invoice->fresh()->remaining),
            'After the 40.00 payment went back, the invoice should owe 100.00 again, not ' . $invoice->fresh()->remaining);
    }

    // ================================================================ I2: findings

    /**
     * I2: Mark Paid with reopen on cancelled invoice A, with a transaction id the same customer has as a PROCESSING
     * payment on invoice B: handle() lands the money on B; A is reopened (its cancellation note voided) and stays
     * unpaid, yet recordByStaff reports success.
     */
    public function test_mark_paid_with_reopen_pays_the_invoice_it_reopens_or_changes_nothing(): void
    {
        $b = $this->invoice(100);
        ExtensionHelper::addProcessingPayment($b->id, 'Stripe', 100.00, null, 'TX-SHARED', currency: 'USD');
        $a = $this->invoice(100);
        $a->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Overdue']);

        try {
            $transaction = (new RecordPaymentService)->recordByStaff($a->fresh(), null, '100.00', 'TX-SHARED', reopen: true);
        } catch (DisplayException) {
            $this->assertSame(Invoice::STATUS_CANCELLED, $a->fresh()->status, 'refused, but the reopening stayed');

            return;
        }

        $this->assertSame($a->id, $transaction->invoice_id, "Mark Paid on #{$a->id} recorded the payment on #{$transaction->invoice_id}; #{$a->id} is now " . $a->fresh()->status);
    }

    /** I2: the payment date is the transaction's created_at, also when staff complete a processing payment. */
    public function test_mark_paid_completing_a_processing_payment_keeps_the_payment_date(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 100.00, null, 'TX-PROC', currency: 'USD');

        $transaction = (new RecordPaymentService)->recordByStaff($invoice->fresh(), Gateway::query()->first(), '100.00', 'TX-PROC',
            paidAt: Carbon::parse('2026-10-01 12:00:00'));

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('2026-10-01', $transaction->fresh()->created_at->toDateString());
    }

    // ================================================================ regression coverage (held)

    public function test_a_stale_model_with_cached_empty_lines_is_not_marked_paid(): void
    {
        $stale = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $stale->load('items', 'transactions', 'adjustmentNotes'); // total 0 in memory
        Invoice::query()->find($stale->id)->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);

        try {
            $stale->update(['status' => Invoice::STATUS_PAID]);
        } catch (DisplayException) {
        }

        $this->assertSame(Invoice::STATUS_UNPAID, $stale->fresh()->status);
    }

    public function test_paid_with_a_move_to_another_customer_in_the_same_save_is_refused(): void
    {
        $other = User::factory()->create();
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 40.00, null, 'pi_40', currency: 'USD');

        try {
            $invoice->fresh()->update(['status' => Invoice::STATUS_PAID, 'client_id' => $other->client()->id, 'currency_code' => 'USD']);
        } catch (DisplayException) {
        }

        $this->assertSame([Invoice::STATUS_UNPAID, $this->user->client()->id], [$invoice->fresh()->status, $invoice->fresh()->client_id]);
    }

    public function test_mark_paid_overpayment_journals_payment_and_credit_in(): void
    {
        $invoice = $this->invoice(100);

        (new RecordPaymentService)->recordByStaff($invoice, null, '150.00', 'TRANSFER-150');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(5000, $this->balanceCents());
        $this->assertSame([[MoneyEventKind::Payment, 15000], [MoneyEventKind::CreditIn, 5000]],
            MoneyEvent::query()->orderBy('id')->get()->map(fn ($e) => [$e->kind, self::cents($e->amount)])->all());
        $this->assertSame(0, Artisan::call('mycs:credits-check'));
    }

    public function test_mark_paid_reopening_rolls_back_entirely_when_recording_fails(): void
    {
        $invoice = $this->invoice(100);
        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Overdue']);
        Event::listen(TransactionCreated::class, fn () => throw new RuntimeException('gateway hook failed'));

        try {
            (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '100.00', 'TRANSFER-X', reopen: true);
            $this->fail('no failure');
        } catch (RuntimeException) {
        }

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame(AdjustmentNoteStatus::Active, $invoice->adjustmentNotes()->where('is_cancellation', true)->sole()->status);
        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame(0, MoneyEvent::query()->count());
    }

    public function test_mark_paid_replay_of_a_staff_transaction_id_changes_nothing(): void
    {
        $invoice = $this->invoice(100);
        (new RecordPaymentService)->recordByStaff($invoice, null, '40.00', 'TRANSFER-R');

        try {
            (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '60.00', 'TRANSFER-R');
            $this->fail('replay recorded');
        } catch (DisplayException) {
        }

        $this->assertSame([1, Invoice::STATUS_UNPAID, 6000], [InvoiceTransaction::query()->count(), $invoice->fresh()->status, self::cents($invoice->fresh()->remaining)]);
    }

    public function test_deleting_a_spent_payment_without_confirmation_changes_nothing(): void
    {
        $overpaid = $this->invoice(100);
        ExtensionHelper::addPayment($overpaid->id, 'Stripe', 130.00, null, 'pi_130', currency: 'USD');
        (new RecordPaymentService)->payWithCredit($this->invoice(30));
        $events = MoneyEvent::query()->count();

        try {
            (new RecordPaymentService)->deleteTransaction($overpaid->transactions()->sole());
            $this->fail('deleted without confirmation');
        } catch (DisplayException) {
        }

        $this->assertSame([0, $events, 1], [$this->balanceCents(), MoneyEvent::query()->count(), $overpaid->transactions()->count()]);
    }

    public function test_negative_credit_is_repaid_by_a_later_overpayment_and_the_journal_matches(): void
    {
        $overpaid = $this->invoice(100);
        ExtensionHelper::addPayment($overpaid->id, 'Stripe', 150.00, null, 'pi_150', currency: 'USD');
        (new RecordPaymentService)->payWithCredit($this->invoice(50));
        (new RecordPaymentService)->deleteTransaction($overpaid->transactions()->sole(), allowNegativeCredit: true);
        $this->assertSame(-5000, $this->balanceCents());

        $this->assertNull((new RecordPaymentService)->payWithCredit($open = $this->invoice(10)), 'negative credit paid an invoice');
        (new RecordPaymentService)->recordByStaff($this->invoice(10), null, '80.00', 'TRANSFER-80'); // +70 credit

        // MT-1e: what is left after the debt (20) pays the oldest open invoice, the one the deleted payment reopened
        $this->assertSame(80.0, $overpaid->fresh()->remaining);
        $this->assertSame(Invoice::STATUS_UNPAID, $open->fresh()->status);
        $this->assertSame(0, $this->balanceCents());
        $this->assertSame(0, Artisan::call('mycs:credits-check'));
    }

    public function test_note_markers_cannot_be_flipped_and_cancellation_notes_follow_reopening(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_r', currency: 'USD');
        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 10.00);
        $refund = AdjustmentNote::query()->where('is_refund', true)->sole();

        foreach ([['is_refund' => false], ['is_cancellation' => true], ['invoice_id' => $this->invoice(5)->id], ['number' => 'X-1'], ['type' => 'debit']] as $change) {
            try {
                $refund->update($change);
                $this->fail('note changed: ' . json_encode($change));
            } catch (DisplayException) {
            }
            $refund->refresh();
        }
        $this->assertTrue($refund->is_refund);

        $other = $this->invoice(100);
        $other->update(['status' => Invoice::STATUS_CANCELLED]);
        $other->fresh()->update(['status' => Invoice::STATUS_UNPAID]); // reopening voids the cancellation note
        $this->assertSame(AdjustmentNoteStatus::Voided, $other->adjustmentNotes()->where('is_cancellation', true)->sole()->status);
        $this->assertSame(10000, self::cents($other->fresh()->remaining));
    }

    public function test_an_invoice_without_payments_is_still_deleted_with_its_notes(): void
    {
        $invoice = $this->invoice(100);
        $this->note($invoice, -10);
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        $invoice->fresh()->delete();

        $this->assertSame([0, 0], [Invoice::query()->count(), AdjustmentNote::query()->count()]);
    }

    // ================================================================ fuzz

    public function test_fuzz_seed_11(): void
    {
        $this->fuzz(11, 80);
    }

    public function test_fuzz_seed_12(): void
    {
        $this->fuzz(12, 80);
    }

    public function test_fuzz_seed_13(): void
    {
        $this->fuzz(13, 80);
    }

    public function test_fuzz_seed_14(): void
    {
        $this->fuzz(14, 80);
    }

    /**
     * Random gateway payments, Mark Paid (partial/exact/over, replays, reopen), credit payments, local refunds, deletions
     * with/without allowNegativeCredit, cancellations, reopenings, status flips to paid, staff notes and their void.
     * After every step: I1 (an invoice that BECAME paid has nothing left), I2 (a refused Mark Paid or deletion changed
     * nothing), I3 (credit == journal, credits-check, negative only after an allowed deletion), I4 (notes never edited
     * or deleted, refund notes active), and the invoice side of the journal.
     */
    private function fuzz(int $seed, int $steps): void
    {
        mt_srand($seed);
        $svc = new RecordPaymentService;
        $users = [$this->user, User::factory()->create()];
        $invoices = [];
        $log = [];
        $n = 0;
        for ($i = 0; $i < 4; $i++) {
            $invoices[] = $this->invoice(mt_rand(100, 20000) / 100, referenceType: mt_rand(0, 4) === 0 ? Credit::class : null, user: $users[mt_rand(0, 1)])->id;
        }

        for ($step = 0; $step < $steps; $step++) {
            $before = $this->snapshot();
            $invoiceId = $invoices[array_rand($invoices)];
            $invoice = Invoice::query()->find($invoiceId);
            $transactions = InvoiceTransaction::query()->orderBy('id')->get();
            $kind = 'other';
            $refused = false;
            $roll = mt_rand(1, 100);
            try {
                if ($roll <= 6) {
                    $invoices[] = $this->invoice(mt_rand(100, 20000) / 100, referenceType: mt_rand(0, 4) === 0 ? Credit::class : null, user: $users[mt_rand(0, 1)])->id;
                    $op = 'new invoice #' . end($invoices);
                } elseif ($roll <= 22) {
                    $remaining = max(0, self::cents($invoice->fresh()->remaining));
                    $cents = [$remaining ?: mt_rand(1, 5000), $remaining + mt_rand(1, 5000), mt_rand(1, max(1, $remaining))][mt_rand(0, 2)];
                    $txid = 'g' . $seed . '-' . (++$n);
                    $status = mt_rand(0, 5) === 0 ? InvoiceTransactionStatus::Processing : InvoiceTransactionStatus::Succeeded;
                    ExtensionHelper::addPayment($invoiceId, 'Stripe', $cents / 100, null, $txid, $status, currency: 'USD');
                    $op = "gateway pay #{$invoiceId} {$cents}c {$status->value} {$txid}";
                } elseif ($roll <= 40) {
                    $kind = 'markpaid';
                    $remaining = max(0, self::cents($invoice->fresh()->remaining));
                    $cents = [$remaining ?: mt_rand(1, 5000), $remaining + mt_rand(1, 3000), mt_rand(1, max(1, $remaining))][mt_rand(0, 2)];
                    $txid = mt_rand(0, 2) === 0 ? null : 'm' . $seed . '-' . (++$n);
                    if ($transactions->isNotEmpty() && mt_rand(0, 3) === 0) {
                        $txid = $this->pickOne($transactions)->transaction_id ?? $txid; // replay
                    }
                    $reopen = (bool) mt_rand(0, 1);
                    $op = "markPaid #{$invoiceId} ({$invoice->status}) {$cents}c tx={$txid}" . ($reopen ? ' reopen' : '');
                    $svc->recordByStaff($invoice, null, $cents / 100, $txid, paidAt: Carbon::parse('2026-10-0' . mt_rand(1, 9)), reopen: $reopen);
                } elseif ($roll <= 48) {
                    $t = $svc->payWithCredit($invoiceId, (bool) mt_rand(0, 1));
                    $op = "payWithCredit #{$invoiceId} -> " . ($t?->id ?? 'none');
                } elseif ($roll <= 56) {
                    $refundable = $transactions->filter(fn ($t) => $t->status === InvoiceTransactionStatus::Succeeded
                        && self::cents($t->amount) - self::cents($t->refunded_amount) - self::cents($t->credited_amount) > 0);
                    if ($refundable->isEmpty()) {
                        $op = 'refund: none';
                    } else {
                        $t = $this->pickOne($refundable);
                        $max = self::cents($t->amount) - self::cents($t->refunded_amount) - self::cents($t->credited_amount);
                        $cents = mt_rand(0, 1) ? $max : mt_rand(1, $max);
                        $op = "refundLocally tx{$t->id} {$cents}c";
                        ExtensionHelper::refundLocally($t, $cents / 100);
                    }
                } elseif ($roll <= 68) {
                    if ($transactions->isEmpty()) {
                        $op = 'delete: none';
                    } else {
                        $t = $this->pickOne($transactions);
                        $allow = (bool) mt_rand(0, 1);
                        $kind = $allow ? 'delete-allow' : 'delete';
                        $op = "delete tx{$t->id} ({$t->status->value}, {$t->amount}, cred {$t->credited_amount})" . ($allow ? ' allowNegative' : '');
                        $svc->deleteTransaction($t, $allow);
                    }
                } elseif ($roll <= 74) {
                    if (in_array($invoice->status, ['unpaid', 'draft'], true)) {
                        $invoice->update(['status' => 'cancelled', 'cancellation_reason' => 'fuzz']);
                        $op = "cancel #{$invoiceId}";
                    } else {
                        $op = "cancel #{$invoiceId}: {$invoice->status}";
                    }
                } elseif ($roll <= 79) {
                    $op = "reopen #{$invoiceId} ({$invoice->status})";
                    if ($invoice->status === 'cancelled') {
                        $invoice->update(['status' => 'unpaid']);
                    }
                } elseif ($roll <= 86) {
                    $op = "flip paid #{$invoiceId} ({$invoice->status}, remaining {$invoice->fresh()->remaining})";
                    $invoice->update(['status' => 'paid']);
                } elseif ($roll <= 93) {
                    $cents = -mt_rand(1, 5000);
                    $this->note($invoice, $cents / 100);
                    $op = "note #{$invoiceId} {$cents}c";
                } else {
                    $notes = AdjustmentNote::query()->orderBy('id')->get()->filter(fn (AdjustmentNote $note) => $note->isVoidableByStaff());
                    if ($notes->isEmpty()) {
                        $op = 'void: none';
                    } else {
                        $note = $this->pickOne($notes);
                        $op = "toggle note {$note->id} ({$note->status->value})";
                        $note->update(['status' => $note->status === AdjustmentNoteStatus::Voided ? AdjustmentNoteStatus::Active : AdjustmentNoteStatus::Voided]);
                    }
                }
            } catch (DisplayException|InvalidArgumentException $e) {
                $refused = true;
                $op = ($op ?? 'op') . ' refused: ' . $e->getMessage();
            }
            $log[] = "{$step}: {$op}";
            unset($op);
            $context = "seed {$seed}\n" . implode("\n", array_slice($log, -12));
            $this->assertStep($before, $this->snapshot(), $kind, $refused, $context);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'invoices' => Invoice::query()->with('items', 'transactions', 'adjustmentNotes')->get()
                ->mapWithKeys(fn (Invoice $i) => [$i->id => [$i->status, self::cents($i->remaining)]])->all(),
            'notes' => AdjustmentNote::query()->orderBy('id')->get()
                ->mapWithKeys(fn (AdjustmentNote $n) => [$n->id => [$n->invoice_id, $n->type, (string) $n->amount, $n->description, $n->number, (bool) $n->is_refund, (bool) $n->is_cancellation, $n->status->value]])->all(),
            'transactions' => InvoiceTransaction::query()->orderBy('id')->get()
                ->map(fn (InvoiceTransaction $t) => [$t->id, $t->invoice_id, $t->status->value, (string) $t->amount, (string) $t->credited_amount, (string) $t->refunded_amount])->all(),
            'credits' => Credit::query()->get()->mapWithKeys(fn (Credit $c) => [$c->client_id . '/' . $c->currency_code => self::cents($c->amount)])->all(),
            'events' => MoneyEvent::query()->count(),
        ];
    }

    private function assertStep(array $before, array $after, string $kind, bool $refused, string $context): void
    {
        // I1: an invoice that became paid in this step has nothing left to pay.
        foreach ($after['invoices'] as $id => [$status, $remaining]) {
            if ($status === 'paid' && ($before['invoices'][$id][0] ?? null) !== 'paid') {
                $this->assertLessThanOrEqual(0, $remaining, "I1 invoice #{$id} became paid with {$remaining}c left.\n{$context}");
            }
        }
        // I2: a refused Mark Paid / deletion changes nothing.
        if ($refused && in_array($kind, ['markpaid', 'delete', 'delete-allow'], true)) {
            $this->assertEquals($before, $after, "I2 a refused {$kind} changed something.\n{$context}");
        }
        // I3: credit == journal; negative only after an allowed deletion.
        $journal = [];
        foreach (MoneyEvent::query()->orderBy('id')->get() as $event) {
            $key = $event->client_id . '/' . $event->currency_code;
            $journal[$key] = ($journal[$key] ?? 0) + $event->creditEffectCents();
        }
        foreach (array_unique(array_merge(array_keys($journal), array_keys($after['credits']))) as $key) {
            $this->assertSame($after['credits'][$key] ?? 0, $journal[$key] ?? 0, "I3 credit vs journal {$key}.\n{$context}");
            $now = $after['credits'][$key] ?? 0;
            $was = $before['credits'][$key] ?? 0;
            if ($now < 0 && $now < $was) {
                $this->assertSame('delete-allow', $kind, "I3 credit {$key} went negative ({$was} -> {$now}) without an allowed deletion.\n{$context}");
            }
        }
        $this->assertSame(0, Artisan::call('mycs:credits-check'), "I3 credits-check.\n{$context}");
        // I4: notes never edited or deleted (only status), refund notes always active.
        foreach ($before['notes'] as $id => $note) {
            $this->assertArrayHasKey($id, $after['notes'], "I4 note {$id} deleted.\n{$context}");
            $this->assertSame(array_slice($note, 0, 7), array_slice($after['notes'][$id], 0, 7), "I4 note {$id} edited.\n{$context}");
        }
        foreach ($after['notes'] as $id => $note) {
            if ($note[5]) {
                $this->assertSame('active', $note[7], "I4 refund note {$id} not active.\n{$context}");
            }
        }
        // Invoice side of the journal (docs/money-trail.md: payment − credit_in(overpayment) − refund).
        foreach (Invoice::query()->pluck('id') as $id) {
            $journalSide = MoneyEvent::query()->where('invoice_id', $id)->orderBy('id')->get()->sum(fn (MoneyEvent $e) => self::invoiceEffect($e));
            $onInvoice = InvoiceTransaction::query()->where('invoice_id', $id)->where('status', InvoiceTransactionStatus::Succeeded->value)->get()
                ->sum(fn (InvoiceTransaction $t) => self::cents($t->amount) - self::cents($t->credited_amount) - self::cents($t->refunded_amount));
            $this->assertSame($onInvoice, $journalSide, "journal vs invoice #{$id}.\n{$context}");
        }
    }

    private static function invoiceEffect(MoneyEvent $event): int
    {
        $cents = self::cents($event->amount);

        return match ($event->kind) {
            MoneyEventKind::Payment => $cents,
            MoneyEventKind::Refund => -$cents,
            // The part of a payment the invoice did not take: an overpayment, or what was over its total when it was
            // closed (2b, invoice_reduced).
            MoneyEventKind::CreditIn => in_array($event->cause, [MoneyTrail::CAUSE_OVERPAYMENT, MoneyTrail::CAUSE_INVOICE_REDUCED, MoneyTrail::CAUSE_REFUND_TO_CREDIT], true) ? -$cents : 0,
            MoneyEventKind::Reversal => -self::invoiceEffect($event->reverses),
            default => 0,
        };
    }
}
