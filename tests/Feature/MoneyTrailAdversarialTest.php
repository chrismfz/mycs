<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyTrail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Paymenter\Extensions\Gateways\AdvFakeGw\AdvFakeGw;
use RuntimeException;
use Tests\TestCase;

/**
 * Adversarial review of MT-1a (docs/money-trail.md §1). Each test attacks one of the claimed invariants:
 *  I1 every movement journals in its own DB transaction (rollback leaves nothing);
 *  I2 per customer and currency, Σ creditEffectCents() == credits.amount (from a zero opening balance);
 *  I3 per invoice, Σ payment − Σ credit_in(overpayment) − Σ refund (minus reversals)
 *     == Σ (amount − credited − refunded) over its succeeded transactions;
 *  I4 append-only, a reversal per event at most, deleting a transaction reverses its unreversed events;
 *  I5 no personal data; I6 a customer with events cannot be deleted.
 * Tests that hold stay as regression coverage.
 */
class MoneyTrailAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_enabled' => true]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    // ---------------------------------------------------------------- helpers

    private function invoiceFor(User $user, int $cents = 10000, bool $creditPurchase = false): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'pending', 'currency_code' => 'USD']);
        $invoice->items()->create(array_filter([
            'reference_type' => $creditPurchase ? Credit::class : null,
            'description' => $creditPurchase ? 'Deposit' : 'Hosting',
            'quantity' => 1,
            'price' => $cents / 100,
        ], fn ($value) => $value !== null));

        return $invoice->fresh();
    }

    private static function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /** I2: every (customer, currency) the journal or the credits table knows of. */
    private function assertCreditsMatchJournal(string $context = ''): void
    {
        $journal = [];
        foreach (MoneyEvent::query()->orderBy('id')->get() as $event) {
            $key = $event->user_id . '/' . $event->currency_code;
            $journal[$key] = ($journal[$key] ?? 0) + $event->creditEffectCents();
        }
        $actual = [];
        foreach (Credit::query()->get() as $credit) {
            $key = $credit->user_id . '/' . $credit->currency_code;
            $actual[$key] = ($actual[$key] ?? 0) + self::cents($credit->amount);
        }
        foreach (array_unique(array_merge(array_keys($journal), array_keys($actual))) as $key) {
            $this->assertSame($actual[$key] ?? 0, $journal[$key] ?? 0, "I2 credit vs journal for user/currency {$key}. {$context}");
        }
    }

    /** What an event puts on its invoice, in cents (I3); a reversal undoes its event. */
    private static function invoiceEffect(MoneyEvent $event): int
    {
        $cents = self::cents($event->amount);

        return match ($event->kind) {
            MoneyEventKind::Payment => $cents,
            MoneyEventKind::Refund => -$cents,
            MoneyEventKind::CreditIn => in_array($event->cause, [MoneyTrail::CAUSE_OVERPAYMENT, MoneyTrail::CAUSE_INVOICE_REDUCED], true) ? -$cents : 0, // 2b
            MoneyEventKind::Reversal => -self::invoiceEffect($event->reverses),
            default => 0,
        };
    }

    /** I3 for every invoice. */
    private function assertInvoicesMatchJournal(string $context = ''): void
    {
        foreach (Invoice::query()->get() as $invoice) {
            $journal = MoneyEvent::query()->where('invoice_id', $invoice->id)->orderBy('id')->get()
                ->sum(fn (MoneyEvent $event) => self::invoiceEffect($event));
            $onInvoice = InvoiceTransaction::query()->where('invoice_id', $invoice->id)
                ->where('status', InvoiceTransactionStatus::Succeeded->value)->get()
                ->sum(fn (InvoiceTransaction $t) => self::cents($t->amount) - self::cents($t->credited_amount) - self::cents($t->refunded_amount));
            $this->assertSame($onInvoice, $journal, "I3 invoice #{$invoice->id}. {$context}");
        }
    }

    /** I4: a reversal per event at most, and no unreversed event left on a deleted transaction. */
    private function assertReversalsSound(string $context = ''): void
    {
        $reversed = MoneyEvent::query()->whereNotNull('reverses_id')->pluck('reverses_id');
        $this->assertSame($reversed->count(), $reversed->unique()->count(), "I4 an event reversed twice. {$context}");
        $live = InvoiceTransaction::query()->pluck('id');
        $orphans = MoneyEvent::query()->whereNotNull('invoice_transaction_id')->whereNotIn('invoice_transaction_id', $live)
            ->where('kind', '!=', MoneyEventKind::Reversal->value)->whereDoesntHave('reversal')
            // what deleteTransaction writes itself for the part reversals do not cover
            ->where(fn ($q) => $q->whereNull('cause')->orWhere('cause', '!=', MoneyTrail::CAUSE_TRANSACTION_DELETED))
            ->count();
        $this->assertSame(0, $orphans, "I4 unreversed events of a deleted transaction. {$context}");
    }

    private function assertAll(string $context = ''): void
    {
        $this->assertCreditsMatchJournal($context);
        $this->assertInvoicesMatchJournal($context);
        $this->assertReversalsSound($context);
    }

    // ---------------------------------------------------------------- fuzz (I2, I3, I4)

    public function test_fuzz_seed_1(): void
    {
        $this->fuzz(1, 70);
    }

    public function test_fuzz_seed_2(): void
    {
        $this->fuzz(2, 70);
    }

    public function test_fuzz_seed_3(): void
    {
        $this->fuzz(3, 70);
    }

    public function test_fuzz_seed_4(): void
    {
        $this->fuzz(4, 70);
    }

    public function test_fuzz_seed_5(): void
    {
        $this->fuzz(5, 70);
    }

    /**
     * Random sequences over every journaled path: gateway/staff payments (exact, partial, overpaid, on paid or
     * cancelled invoices), processing → succeeded / failed, replays, credit payments (also all-or-nothing), allocation
     * of a sale's credit, local refunds, deletions, credit purchases. The invariants are checked after every step.
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
            $invoices[] = $this->invoiceFor($users[mt_rand(0, 1)], mt_rand(100, 20000), mt_rand(0, 3) === 0)->id;
        }

        for ($step = 0; $step < $steps; $step++) {
            $roll = mt_rand(1, 100);
            $invoiceId = $invoices[array_rand($invoices)];
            $invoice = Invoice::query()->find($invoiceId);
            $transactions = InvoiceTransaction::query()->orderBy('id')->get();
            try {
                if ($roll <= 8) {
                    $invoices[] = $this->invoiceFor($users[mt_rand(0, 1)], mt_rand(100, 20000), mt_rand(0, 2) === 0)->id;
                    $op = 'new invoice #' . end($invoices);
                } elseif ($roll <= 45) {
                    $remaining = max(0, self::cents($invoice->fresh()->remaining));
                    $cents = match (mt_rand(0, 3)) {
                        0 => $remaining > 0 ? $remaining : mt_rand(1, 5000),
                        1 => $remaining + mt_rand(1, 5000),
                        2 => mt_rand(1, max(1, $remaining)),
                        default => mt_rand(1, 20000),
                    };
                    $gateway = mt_rand(0, 4) === 0 ? null : 'Stripe';
                    $status = match (mt_rand(0, 9)) {
                        0 => InvoiceTransactionStatus::Failed,
                        1, 2 => InvoiceTransactionStatus::Processing,
                        default => InvoiceTransactionStatus::Succeeded,
                    };
                    $txid = 'fz' . $seed . '-' . (++$n);
                    if ($transactions->isNotEmpty() && mt_rand(0, 3) === 0) { // replay / processing → succeeded
                        $old = $this->pickOne($transactions);
                        if ($old->transaction_id !== null) {
                            $txid = $old->transaction_id;
                            $gateway = $old->gateway_id === null ? null : 'Stripe';
                            $invoiceId = mt_rand(0, 1) ? $old->invoice_id : $invoiceId;
                        }
                    }
                    ExtensionHelper::addPayment($invoiceId, $gateway, $cents / 100, null, $txid, $status, currency: 'USD');
                    $op = "pay #{$invoiceId} {$cents}c {$status->value} " . ($gateway ?? 'staff') . " {$txid}";
                } elseif ($roll <= 58) {
                    $covers = (bool) mt_rand(0, 1);
                    $t = $svc->payWithCredit($invoiceId, $covers);
                    $op = "payWithCredit #{$invoiceId}" . ($covers ? ' onlyIfCovers' : '') . ' -> ' . ($t?->id ?? 'none');
                } elseif ($roll <= 66) {
                    $sources = $transactions->filter(fn ($t) => $t->status === InvoiceTransactionStatus::Succeeded && self::cents($t->credited_amount) > 0);
                    if ($sources->isEmpty()) {
                        $op = 'allocate: no source';
                    } else {
                        $source = $this->pickOne($sources);
                        $t = $svc->allocateFromTransaction($source, $invoiceId);
                        $op = "allocate tx{$source->id} -> #{$invoiceId}: " . ($t?->id ?? 'none');
                    }
                } elseif ($roll <= 78) {
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
                } elseif ($roll <= 94) {
                    if ($transactions->isEmpty()) {
                        $op = 'delete: none';
                    } else {
                        $t = $this->pickOne($transactions);
                        $op = "delete tx{$t->id} ({$t->status->value}, {$t->amount}, cred {$t->credited_amount})";
                        $svc->deleteTransaction($t);
                    }
                } else {
                    if (in_array($invoice->status, ['pending', 'draft'], true)) {
                        $invoice->update(['status' => 'cancelled']);
                        $op = "cancel #{$invoiceId}";
                    } else {
                        $op = "cancel #{$invoiceId}: not open";
                    }
                }
            } catch (DisplayException|InvalidArgumentException $e) {
                $op = ($op ?? 'op') . ' refused: ' . $e->getMessage();
            }
            $log[] = "{$step}: {$op}";
            unset($op);
            $this->assertAll("seed {$seed}\n" . implode("\n", array_slice($log, -15)));
        }
    }

    // ---------------------------------------------------------------- I3: refunds of money that never arrived

    /**
     * prepareRefund never checks the transaction's status: a failed payment (with a transaction id, so the admin
     * Refund action is visible) can be refunded locally. The journal then records money going back to the customer
     * from a payment it never recorded (no payment event), and the invoice gets a credit note for it.
     */
    public function test_a_failed_payment_cannot_be_refunded_into_the_journal(): void
    {
        $invoice = $this->invoiceFor($this->user);
        $failed = ExtensionHelper::addFailedPayment($invoice->id, 'Stripe', 100.00, null, 'pi_failed', currency: 'USD');
        $this->assertSame(0, MoneyEvent::query()->count());

        try {
            ExtensionHelper::refundLocally($failed, 100.00);
        } catch (InvalidArgumentException|DisplayException) {
        }

        $this->assertSame(0, MoneyEvent::query()->where('kind', MoneyEventKind::Refund->value)->count(),
            'A refund was journaled for a payment that never succeeded.');
        $this->assertInvoicesMatchJournal();
    }

    public function test_a_processing_payment_cannot_be_refunded_into_the_journal(): void
    {
        $invoice = $this->invoiceFor($this->user);
        $processing = ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 100.00, null, 'pi_proc', currency: 'USD');

        try {
            ExtensionHelper::refundLocally($processing, 40.00);
        } catch (InvalidArgumentException|DisplayException) {
        }

        $this->assertInvoicesMatchJournal();
    }

    // ---------------------------------------------------------------- I2: reassigned invoice

    /**
     * Staff can move an invoice to another customer (admin form and API both allow user_id on update). Deleting its
     * payment afterwards changes the NEW owner's credit, but the reversals are written under the OLD owner (they
     * copy the reversed event's user_id): both customers' journals stop matching their credit.
     */
    public function test_deleting_a_payment_of_a_reassigned_invoice_keeps_both_customers_journals_right(): void
    {
        $other = User::factory()->create();
        $mine = $this->invoiceFor($this->user, 10000);
        $theirs = $this->invoiceFor($other, 10000);
        $payment = ExtensionHelper::addPayment($mine->id, 'Stripe', 120.00, null, 'pi_a', currency: 'USD'); // A: +20 credit
        ExtensionHelper::addPayment($theirs->id, 'Stripe', 120.00, null, 'pi_b', currency: 'USD');          // B: +20 credit
        $this->assertCreditsMatchJournal('before');

        // Fixed by refusing the move (InvoiceObserver::updating): an invoice with payments keeps its customer.
        try {
            $mine->update(['user_id' => $other->id]); // issued to the wrong customer, staff fix it
        } catch (DisplayException) {
        }
        $this->assertSame($this->user->id, $mine->fresh()->user_id);

        try {
            (new RecordPaymentService)->deleteTransaction($payment);
        } catch (DisplayException) {
        }

        $this->assertCreditsMatchJournal('after reassigning the invoice and deleting its payment');
    }

    /** Same with a credit payment: the credit goes back to the new owner, the journal gives it to the old one. */
    public function test_deleting_a_credit_payment_of_a_reassigned_invoice_keeps_the_journal_right(): void
    {
        $other = User::factory()->create();
        $deposit = $this->invoiceFor($this->user, 5000, creditPurchase: true);
        ExtensionHelper::addPayment($deposit->id, 'Stripe', 50.00, null, 'pi_dep', currency: 'USD'); // A buys 50 credit
        $invoice = $this->invoiceFor($this->user, 3000);
        $creditPayment = (new RecordPaymentService)->payWithCredit($invoice);
        $this->assertNotNull($creditPayment);

        try {
            $invoice->update(['user_id' => $other->id]);
        } catch (DisplayException) {
        }
        (new RecordPaymentService)->deleteTransaction($creditPayment);

        $this->assertCreditsMatchJournal();
    }

    // ---------------------------------------------------------------- I3: sub-cent amounts

    /**
     * The refund guard compares cents (1.005 → 100 cents); before bdc3619f refunded_amount was incremented with the raw
     * float (rounded to 1.01 by the decimal column/cast) while the event said 1.00. Held since refunds round once.
     */
    public function test_a_sub_cent_refund_keeps_the_invoice_and_journal_in_step(): void
    {
        $invoice = $this->invoiceFor($this->user);
        $transaction = ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_sub', currency: 'USD');

        try {
            ExtensionHelper::refundLocally($transaction, 1.005);
        } catch (InvalidArgumentException|LogicException) {
        }

        $this->assertInvoicesMatchJournal();
    }

    /**
     * A refund under half a cent passes every check BEFORE the gateway call (0 cents <= refundable), the gateway is
     * asked to refund, and only then the journal refuses a 0.00 event (LogicException): rolled back after the money
     * left, i.e. «Gateway refund succeeded but was NOT recorded locally». It must be refused before the gateway call.
     */
    public function test_a_sub_cent_gateway_refund_is_refused_before_the_gateway_is_called(): void
    {
        $this->fakeRefundGateway();
        $invoice = $this->invoiceFor($this->user);
        $transaction = ExtensionHelper::addPayment($invoice->id, 'AdvFakeGw', 100.00, null, 'fake_1', currency: 'USD');

        try {
            ExtensionHelper::refund($transaction, 0.004);
        } catch (\Throwable) {
        }

        $called = AdvFakeGw::$refunds;
        $recorded = MoneyEvent::query()->where('kind', MoneyEventKind::Refund->value)->count();
        $this->assertFalse($called > 0 && $recorded === 0, 'The gateway refunded but nothing was recorded (rolled back after the money left).');
    }

    private function fakeRefundGateway(): void
    {
        if (!class_exists(AdvFakeGw::class, false)) {
            eval('namespace Paymenter\Extensions\Gateways\AdvFakeGw;
                class AdvFakeGw {
                    public static int $refunds = 0;
                    public function __construct($config = []) {}
                    public function supportsRefunds(): bool { return true; }
                    public function refund($transaction, $amount): bool { self::$refunds++; return true; }
                }');
        }
        AdvFakeGw::$refunds = 0;
        Gateway::query()->firstOrCreate(['extension' => 'AdvFakeGw'], ['name' => 'Fake', 'type' => 'gateway']);
    }

    /** The gateway path journals like the local one, with the gateway's key as source (held). */
    public function test_a_gateway_refund_is_journaled_with_the_gateway_source(): void
    {
        $this->fakeRefundGateway();
        $invoice = $this->invoiceFor($this->user);
        $transaction = ExtensionHelper::addPayment($invoice->id, 'AdvFakeGw', 100.00, null, 'fake_2', currency: 'USD');

        ExtensionHelper::refund($transaction, 25.00);

        $this->assertSame('advfakegw', MoneyEvent::query()->where('kind', 'refund')->value('source'));
        $this->assertAll();
    }

    // ---------------------------------------------------------------- I2: credit purchase with a non-positive line

    /**
     * GrantBoughtCreditService changes the balance by the line's price but journals only a positive one: a credit line
     * edited to a negative price (staff edit the lines in the invoice form) moves the balance with no event.
     */
    public function test_a_negative_credit_line_does_not_move_credit_without_an_event(): void
    {
        $deposit = $this->invoiceFor($this->user, 5000, creditPurchase: true);
        ExtensionHelper::addPayment($deposit->id, 'Stripe', 50.00, null, 'pi_dep2', currency: 'USD');
        $odd = $this->invoiceFor($this->user, -1000, creditPurchase: true);

        try {
            $odd->update(['status' => 'paid']); // staff mark it paid (its total is negative, nothing to pay)
        } catch (DisplayException) {
            // Refused since 2b: an invoice paid over its total closes only through «Close as paid» (settle).
        }

        $this->assertCreditsMatchJournal();
    }

    // ---------------------------------------------------------------- I4: append-only

    /** The model refuses update/delete, but a builder (mass) update or delete never fires model events. */
    public function test_a_mass_update_or_delete_cannot_rewrite_the_journal(): void
    {
        $invoice = $this->invoiceFor($this->user);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_mass', currency: 'USD');
        $id = MoneyEvent::query()->value('id');

        try {
            MoneyEvent::query()->whereKey($id)->update(['amount' => 1]);
        } catch (LogicException|QueryException) {
        }
        try {
            MoneyEvent::query()->whereKey($id)->delete();
        } catch (LogicException|QueryException) {
        }

        $this->assertSame('100.00', MoneyEvent::query()->find($id)?->amount, 'The journal was rewritten through the query builder.');
    }

    public function test_deleting_the_same_transaction_twice_reverses_once(): void
    {
        $invoice = $this->invoiceFor($this->user);
        $transaction = ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, null, 'pi_twice', currency: 'USD');
        $stale = InvoiceTransaction::query()->find($transaction->id);

        (new RecordPaymentService)->deleteTransaction($transaction);
        (new RecordPaymentService)->deleteTransaction($stale);

        $this->assertSame(2, MoneyEvent::query()->where('kind', 'reversal')->count());
        $this->assertAll();
    }

    public function test_deleting_a_processing_then_succeeded_payment_reverses_it(): void
    {
        $invoice = $this->invoiceFor($this->user);
        ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 150.00, null, 'pi_ps', currency: 'USD');
        $transaction = ExtensionHelper::addPayment($invoice->id, 'Stripe', 150.00, null, 'pi_ps', currency: 'USD');

        (new RecordPaymentService)->deleteTransaction($transaction);

        $this->assertSame(0, self::cents(Credit::query()->where('user_id', $this->user->id)->value('amount')));
        $this->assertAll();
    }

    // ---------------------------------------------------------------- I1: rollback

    public function test_a_rolled_back_deletion_refund_or_credit_payment_leaves_no_event(): void
    {
        $this->giveCredit(0);
        $invoice = $this->invoiceFor($this->user);
        $transaction = ExtensionHelper::addPayment($invoice->id, 'Stripe', 120.00, null, 'pi_rb', currency: 'USD');
        $other = $this->invoiceFor($this->user, 1500);
        $before = MoneyEvent::query()->count();

        foreach ([
            fn () => (new RecordPaymentService)->deleteTransaction($transaction),
            fn () => ExtensionHelper::refundLocally($transaction, 10.00),
            fn () => (new RecordPaymentService)->payWithCredit($other),
            fn () => (new RecordPaymentService)->allocateFromTransaction($transaction, $other),
        ] as $step) {
            try {
                DB::transaction(function () use ($step) {
                    $step();
                    throw new RuntimeException('later step failed');
                });
            } catch (RuntimeException) {
            }
        }

        $this->assertSame($before, MoneyEvent::query()->count());
        $this->assertNotNull($transaction->fresh());
        $this->assertAll();
    }

    public function test_a_credit_purchase_paid_by_processing_then_succeeded_and_overpaid_adds_up(): void
    {
        $deposit = $this->invoiceFor($this->user, 5000, creditPurchase: true);
        ExtensionHelper::addProcessingPayment($deposit->id, 'Stripe', 65.00, null, 'pi_cp', currency: 'USD');
        $transaction = ExtensionHelper::addPayment($deposit->id, 'Stripe', 65.00, null, 'pi_cp', currency: 'USD');
        $this->assertSame(6500, self::cents(Credit::query()->where('user_id', $this->user->id)->value('amount')));
        $this->assertAll('after payment');

        (new RecordPaymentService)->deleteTransaction($transaction);

        $this->assertAll('after deletion');
    }

    private function giveCredit(float $amount): void
    {
        Credit::query()->firstOrCreate(['user_id' => $this->user->id, 'currency_code' => 'USD'], ['amount' => $amount]);
    }

    // ---------------------------------------------------------------- I5 / I6

    /** Held since bdc3619f: a staff payment without a gateway keeps no external reference. */
    public function test_a_staff_typed_transaction_id_does_not_carry_personal_data_into_the_journal(): void
    {
        $this->user->forceFill(['email' => 'payer-unique@example.test'])->save();
        $invoice = $this->invoiceFor($this->user);

        ExtensionHelper::addPayment($invoice->id, null, 100.00, null, 'bank transfer from payer-unique@example.test');

        $this->assertStringNotContainsString('payer-unique@example.test', (string) json_encode(DB::table('money_events')->get()));
    }

    /**
     * The admin «New transaction» form lets staff pick a gateway AND type the transaction id (TransactionsRelationManager):
     * gateway_id is then set, so MoneyTrail::externalReference() keeps the typed text verbatim.
     */
    public function test_a_staff_typed_transaction_id_with_a_gateway_picked_does_not_carry_personal_data(): void
    {
        $this->user->forceFill(['email' => 'payer-unique2@example.test'])->save();
        $invoice = $this->invoiceFor($this->user);
        $staff = User::factory()->create();
        $this->actingAs($staff);

        // As the admin form calls it (TransactionsRelationManager passes enteredByStaff).
        (new RecordPaymentService)->handle($invoice, Gateway::query()->where('extension', 'Stripe')->first(), '100.00', null,
            'transfer from payer-unique2@example.test', enteredByStaff: true);

        $this->assertStringNotContainsString('payer-unique2@example.test', (string) json_encode(DB::table('money_events')->get()));
    }

    // ---------------------------------------------------------------- customer-less invoices (bdc3619f)

    private function orphan(Invoice $invoice): Invoice
    {
        DB::table('invoices')->where('id', $invoice->id)->update(['user_id' => null]); // customer deleted before §8

        return $invoice->fresh();
    }

    /**
     * Docs §8: «money that lands on an invoice without a customer is recorded normally». A credit purchase without a
     * customer: GrantBoughtCreditService creates a credits row with user_id NULL (NOT NULL column) inside the payment's
     * transaction, so the whole payment rolls back and the gateway's money is not recorded at all.
     */
    public function test_a_payment_on_a_customer_less_credit_purchase_is_still_recorded(): void
    {
        $deposit = $this->orphan($this->invoiceFor($this->user, 5000, creditPurchase: true));

        try {
            ExtensionHelper::addPayment($deposit->id, 'Stripe', 50.00, null, 'pi_orphan_cp', currency: 'USD');
        } catch (\Throwable) {
        }

        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'pi_orphan_cp')->count(), 'The payment was not recorded.');
        $this->assertInvoicesMatchJournal();
    }

    /**
     * Deleting a (legacy) payment with a credited part on a customer-less invoice: lockCredit() finds nothing and
     * `$invoice->user->credits()` is called on null (Error, a 500) instead of a DisplayException staff can read.
     */
    public function test_deleting_a_credited_payment_on_a_customer_less_invoice_fails_cleanly(): void
    {
        $invoice = $this->invoiceFor($this->user);
        $transaction = $invoice->transactions()->create([
            'gateway_id' => null, 'amount' => 120.00, 'credited_amount' => 20.00, 'transaction_id' => 'legacy-orphan',
            'status' => InvoiceTransactionStatus::Succeeded,
        ]);
        $this->orphan($invoice);

        // Refused with a message staff can read (not an Error on a null customer); nothing changes. The invoice/journal
        // check (I3) does not apply: the legacy transaction predates the journal.
        try {
            (new RecordPaymentService)->deleteTransaction($transaction);
            $this->fail('The deletion was not refused.');
        } catch (DisplayException) {
        }

        $this->assertNotNull($transaction->fresh());
        $this->assertSame(0, MoneyEvent::query()->count());
        $this->assertCreditsMatchJournal();
    }

    /** Held: a plain payment and an overpayment on a customer-less invoice are journaled without a customer. */
    public function test_an_overpayment_on_a_customer_less_invoice_is_journaled_without_credit(): void
    {
        $invoice = $this->orphan($this->invoiceFor($this->user));

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, null, 'pi_orphan', currency: 'USD');

        $this->assertSame([['payment', null]], MoneyEvent::query()->get()->map(fn ($e) => [$e->kind->value, $e->user_id])->all());
        $this->assertSame(0, Credit::query()->count());
    }

    /** Held: a processing payment (no event yet) or a credit balance alone also block the delete (hasMoneyHistory). */
    public function test_a_customer_with_a_processing_payment_or_credit_cannot_be_deleted(): void
    {
        $invoice = $this->invoiceFor($this->user);
        ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 100.00, null, 'pi_sepa', currency: 'USD');
        $other = User::factory()->create();
        Credit::query()->create(['user_id' => $other->id, 'currency_code' => 'USD', 'amount' => 0.01]);

        foreach ([$this->user, $other] as $customer) {
            try {
                $customer->delete();
            } catch (DisplayException) {
            }
            $this->assertNotNull(User::query()->find($customer->id));
        }
    }

    public function test_a_customer_with_events_survives_a_mass_delete(): void
    {
        $invoice = $this->invoiceFor($this->user);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_md', currency: 'USD');

        try {
            User::query()->whereKey($this->user->id)->delete();
        } catch (QueryException|DisplayException) {
        }

        $this->assertNotNull(User::query()->find($this->user->id));
        $this->assertSame(1, MoneyEvent::query()->count());
    }
}
