<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\Service;
use App\Models\User;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one way money is written to an invoice (docs/payments.md, PR 2a): gateways, staff and credits.
 *
 * - Locks the customer first, then the invoice, then the credit row: every payment path of one customer
 *   is serialised, so a duplicate transaction id is found on any of the customer's invoices (a replayed
 *   PayPal subscription webhook lands on «the latest invoice», which may be another one).
 * - The same gateway + transaction id already succeeded: nothing (Τ2). A succeeded transaction never
 *   goes back to processing/failed.
 * - Only a positive amount, and the currency of the invoice the money lands on, when the gateway states
 *   one (Τ3): otherwise nothing is written, it is logged and staff are told.
 * - Money the invoice cannot take (cancelled, already paid, or above what is left) is still recorded on
 *   the invoice (the money arrived); the part it cannot take becomes account credit and is kept on the
 *   transaction as credited_amount, so it never counts on the invoice as well (Τ7).
 * - Every movement writes its money events in the same transaction (docs/money-trail.md §1, MT-1a).
 */
class RecordPaymentService
{
    public function handle(
        Invoice|int $invoice,
        ?Gateway $gateway,
        mixed $amount,
        mixed $fee = null,
        ?string $transactionId = null,
        InvoiceTransactionStatus $status = InvoiceTransactionStatus::Succeeded,
        bool $isCreditTransaction = false,
        ?string $currency = null,
        bool $enteredByStaff = false,
        ?\DateTimeInterface $paidAt = null,
    ): ?InvoiceTransaction {
        $invoiceId = $invoice instanceof Invoice ? $invoice->getKey() : $invoice;
        $transactionId = $transactionId === null || $transactionId === '' ? null : (string) $transactionId;
        $cents = self::toCents($amount);

        if ($cents <= 0) {
            $this->notify("Payment of «{$this->show($amount)}» on invoice #{$invoiceId} refused: the amount must be positive.", $invoiceId, $transactionId, $gateway);

            return null;
        }

        $notices = [];
        $transaction = DB::transaction(function () use ($invoiceId, $gateway, $cents, $fee, $transactionId, $status, $isCreditTransaction, $currency, $enteredByStaff, $paidAt, &$notices) {
            $invoice = $this->lockInvoice($invoiceId);

            // Same id on the same gateway, or recorded by staff without a gateway: the same money. Staff
            // without a gateway match any gateway's id (re-entering what a webhook already recorded).
            $existing = $transactionId === null ? null : InvoiceTransaction::query()
                ->where('transaction_id', $transactionId)
                ->when($gateway !== null, fn ($query) => $query->where(
                    fn ($query) => $query->where('gateway_id', $gateway->id)->orWhereNull('gateway_id')
                ))
                ->orderByRaw('case when status = ? then 0 else 1 end', [InvoiceTransactionStatus::Succeeded->value])
                ->whereHas('invoice', fn ($query) => $query->where('user_id', $invoice->user_id))
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status === InvoiceTransactionStatus::Succeeded) {
                if ($fee !== null && $existing->fee === null) {
                    $existing->update(['fee' => $fee]); // a late fee report (Stripe)
                }

                return $existing; // a replay: already recorded
            }

            // The money lands on the invoice the transaction was started on.
            $target = $existing === null || $existing->invoice_id === $invoice->id ? $invoice
                : Invoice::query()->whereKey($existing->invoice_id)->lockForUpdate()->firstOrFail();
            if ($currency !== null && strtoupper($currency) !== strtoupper((string) $target->currency_code)) {
                $notices[] = "Payment in {$currency} on invoice #{$target->id} ({$target->currency_code}) was NOT recorded: currency mismatch. Check it with the gateway.";

                return null;
            }

            if ($existing !== null && $status !== InvoiceTransactionStatus::Succeeded) {
                $existing->update(array_filter(['status' => $status, 'fee' => $fee], fn ($value) => $value !== null));

                return $existing;
            }

            $excess = $status === InvoiceTransactionStatus::Succeeded ? $this->excessCents($target, $cents) : 0;
            // An invoice left without a customer (deleted before the journal): the money is still recorded, but
            // there is no account to credit what it cannot take. Staff are told.
            if ($excess > 0 && $target->user_id === null) {
                $notices[] = sprintf('Invoice #%d has no customer: %s %s of a payment could not become account credit. Handle it by hand.',
                    $target->id, number_format($excess / 100, 2, '.', ''), $target->currency_code);
                $excess = 0;
            }
            $values = array_filter([
                'gateway_id' => $gateway?->id,
                'amount' => $cents / 100,
                'fee' => $fee,
                'transaction_id' => $transactionId,
                'status' => $status,
                'is_credit_transaction' => $isCreditTransaction,
                'credited_amount' => $excess / 100,
            ], fn ($value) => $value !== null);
            if ($existing !== null) {
                unset($values['gateway_id'], $values['transaction_id'], $values['is_credit_transaction']);
                // Staff completing a processing payment by hand: the day the money arrived, as for a new one.
                if ($paidAt !== null) {
                    $existing->created_at = Carbon::instance($paidAt);
                }
                $existing->update($values);
                $transaction = $existing;
            } else {
                $transaction = $target->transactions()->make($values);
                // The day the money arrived, when staff record it later (a bank transfer): the transaction's date.
                if ($paidAt !== null) {
                    $transaction->created_at = Carbon::instance($paidAt);
                }
                $transaction->save();
            }
            // Only money that arrived is journaled: a processing/failed payment is, once (and if) it succeeds.
            if ($status === InvoiceTransactionStatus::Succeeded) {
                // Staff completing a gateway's processing payment: the money still came through that gateway.
                $source = MoneyTrail::sourceFor($gateway ?? $transaction->gateway, $isCreditTransaction || (bool) $transaction->is_credit_transaction);
                // The provider's id only when the gateway recorded it; what staff type can name the payer (§9).
                $reference = $enteredByStaff || $transaction->gateway_id === null ? null : $transaction->transaction_id;
                (new MoneyTrail)->payment($target, $transaction, $source, reference: $reference);
                $this->addCredit($target, $transaction, $excess, $source, $notices);
            }

            return $transaction;
        });

        foreach ($notices as $notice) {
            $this->notify($notice, $invoiceId, $transactionId, $gateway);
        }

        return $transaction;
    }

    /**
     * Pay an open invoice from the customer's account credit, under the same locks: the credit is read
     * and spent in one place, so nothing can save a stale copy of it. Pays what is left, or as much as
     * the credit covers ($onlyIfCovers: all of it or nothing, as the cron does). Null when nothing is paid.
     */
    public function payWithCredit(Invoice|int $invoice, bool $onlyIfCovers = false): ?InvoiceTransaction
    {
        $invoiceId = $invoice instanceof Invoice ? $invoice->getKey() : $invoice;

        return DB::transaction(function () use ($invoiceId, $onlyIfCovers) {
            $invoice = $this->lockInvoice($invoiceId);
            if (!in_array($invoice->status, [Invoice::STATUS_PENDING, Invoice::STATUS_DRAFT], true)) {
                return null;
            }
            $credit = $this->lockCredit($invoice);
            $available = $credit === null ? 0 : CreditLedger::cents($credit);
            $remaining = max(0, self::toCents($invoice->withLockedBalance()->remaining));
            $cents = min($available, $remaining);
            if ($credit === null || $cents <= 0 || ($onlyIfCovers && $available < $remaining)) {
                return null;
            }

            return $this->spendCredit($invoice, $cents);
        });
    }

    /**
     * Pay an open invoice with the credit another transaction left ($source->credited_amount), e.g. one PayPal
     * subscription sale for several services. Idempotent and resumable: what the source already paid
     * (its allocations) is subtracted, and a service the source already paid a cycle of is skipped, so a retried
     * webhook finishes an interrupted allocation without paying a second cycle or spending other credit.
     * Null when nothing is paid.
     */
    public function allocateFromTransaction(InvoiceTransaction $source, Invoice|int $target): ?InvoiceTransaction
    {
        $targetId = $target instanceof Invoice ? $target->getKey() : $target;

        return DB::transaction(function () use ($source, $targetId) {
            $invoice = $this->lockInvoice($targetId);
            $source = InvoiceTransaction::query()->whereKey($source->getKey())->lockForUpdate()->first();
            $sourceInvoice = $source === null ? null : Invoice::query()->whereKey($source->invoice_id)->first();
            if ($source === null || $sourceInvoice === null || $source->status !== InvoiceTransactionStatus::Succeeded
                || $sourceInvoice->user_id !== $invoice->user_id || $sourceInvoice->currency_code !== $invoice->currency_code
                || !in_array($invoice->status, [Invoice::STATUS_PENDING, Invoice::STATUS_DRAFT], true)) {
                return null;
            }

            $allocations = InvoiceTransaction::query()->where('source_transaction_id', $source->id)->lockForUpdate()->get();
            $paidServices = InvoiceItem::query()->where('reference_type', Service::class)
                ->whereIn('invoice_id', $allocations->pluck('invoice_id')->push($source->invoice_id))->pluck('reference_id');
            $targetServices = $invoice->items()->where('reference_type', Service::class)->pluck('reference_id');
            if ($targetServices->intersect($paidServices)->isNotEmpty()) {
                return null; // one cycle per service per sale
            }

            $budget = self::toCents($source->credited_amount) - $allocations->sum(fn (InvoiceTransaction $paid) => self::toCents($paid->amount));
            $credit = $this->lockCredit($invoice);
            $available = $credit === null ? 0 : CreditLedger::cents($credit);
            $remaining = max(0, self::toCents($invoice->withLockedBalance()->remaining));
            $cents = min($budget, $available, $remaining);
            if ($credit === null || $cents <= 0) {
                return null;
            }

            return $this->spendCredit($invoice, $cents, $source->id);
        });
    }

    /**
     * Staff recording a payment by hand, as in WHMCS (docs/money-trail.md MT-1c): «Mark Paid» (what is left) and «Add
     * Payment» (any part), through handle() like any payment. The invoice becomes paid only when the payment covers
     * it; its status is never set on its own. On a cancelled invoice the payment would only become credit: with
     * $reopen staff confirm reopening it (pending, its cancellation note voided) and the payment lands on it, both in
     * one transaction.
     *
     * $upToRemaining («Mark Paid»): more than what is left is refused, read under the locks. A double submit or two
     * staff at once would otherwise turn the second payment into account credit silently; a real overpayment is
     * recorded with «Add Payment».
     *
     * @throws DisplayException nothing recorded: not a positive amount, a cancelled invoice without $reopen, more than
     *                          what is left, or a transaction id already recorded or belonging to another invoice
     */
    public function recordByStaff(Invoice $invoice, ?Gateway $gateway, mixed $amount, ?string $transactionId = null, mixed $fee = null, ?\DateTimeInterface $paidAt = null, bool $reopen = false, bool $upToRemaining = false): InvoiceTransaction
    {
        if (self::toCents($amount) <= 0) {
            throw new DisplayException(__('The amount must be positive.'));
        }

        return DB::transaction(function () use ($invoice, $gateway, $amount, $transactionId, $fee, $paidAt, $reopen, $upToRemaining) {
            $locked = $this->lockInvoice($invoice->getKey());
            if ($locked->status === Invoice::STATUS_CANCELLED) {
                if (!$reopen) {
                    throw new DisplayException(__('This invoice is cancelled: reopen it to record the payment on it.'));
                }
                $locked->status = Invoice::STATUS_PENDING;
                $locked->save();
            }
            $remaining = self::toCents($locked->withLockedBalance()->remaining);
            if ($upToRemaining && self::toCents($amount) > $remaining) {
                throw new DisplayException(__('More than what is left to pay (:remaining): record an overpayment with Add Payment.', [
                    'remaining' => number_format(max(0, $remaining) / 100, 2, '.', ''),
                ]));
            }

            $transaction = $this->handle($locked, $gateway, $amount, $fee, $transactionId, enteredByStaff: true, paidAt: $paidAt);
            if ($transaction === null) {
                throw new DisplayException(__('Payment not recorded: see the log for details.'));
            }
            if (!$transaction->wasRecentlyCreated && !$transaction->wasChanged('status')) {
                throw new DisplayException(__('This gateway transaction is already recorded (invoice #:invoice): nothing was added.', ['invoice' => $transaction->invoice_id]));
            }
            // A typed id matching another invoice's processing payment: the money would land there, not here.
            if ((int) $transaction->invoice_id !== (int) $locked->getKey()) {
                throw new DisplayException(__('This transaction id belongs to a payment on invoice #:invoice: nothing was recorded here.', ['invoice' => $transaction->invoice_id]));
            }

            return $transaction;
        });
    }

    /**
     * Delete a transaction (staff correcting a mistake) without leaving money behind: the part that became
     * account credit is taken back (and the credit it bought, on a credit purchase), and a payment made with
     * credit gives it back. Refused when it paid other invoices, or when the payment has refunds. When that credit
     * was already spent, staff confirm it ($allowNegativeCredit, decision 1): the credit goes negative, the customer
     * owes it (docs/money-trail.md §1, MT-1c).
     *
     * @throws DisplayException
     */
    public function deleteTransaction(InvoiceTransaction $transaction, bool $allowNegativeCredit = false): void
    {
        DB::transaction(function () use ($transaction, $allowNegativeCredit) {
            $invoice = $this->lockInvoice($transaction->invoice_id);
            $transaction = InvoiceTransaction::query()->whereKey($transaction->getKey())->lockForUpdate()->first();
            if ($transaction === null) {
                return;
            }
            if (InvoiceTransaction::query()->where('source_transaction_id', $transaction->id)->lockForUpdate()->exists()) {
                throw new DisplayException(__('This payment already paid other invoices with its credit: it cannot be deleted.'));
            }
            // Its refunds stay on the invoice (adjustment notes): without the row, the refund history and the
            // gateway's transaction id are gone, and a replayed webhook records the whole payment again.
            if (self::toCents($transaction->refunded_amount) > 0) {
                throw new DisplayException(__('This payment has refunds: it cannot be deleted (its refund history and gateway transaction id must stay).'));
            }

            // Its journal entries not undone yet (none for a payment that never succeeded): each gets a reversal.
            // Locked: read current, not the transaction's snapshot. Its gap locks can make two concurrent deletions
            // of neighbouring transactions deadlock (InnoDB rolls one back; staff retry); events of a transaction
            // are only written under that transaction's row lock, which is held here.
            $events = MoneyEvent::query()->where('invoice_transaction_id', $transaction->id)
                ->where('kind', '!=', MoneyEventKind::Reversal->value)->whereDoesntHave('reversal')
                ->orderBy('id')->lockForUpdate()->get();

            // Credit to give back (+) or take back (-). A payment made with credit: what is still on the invoice
            // goes back (its credited part is already back). Any other payment: what became credit is taken back.
            // On a credit purchase that gave its credit, what is on the invoice bought credit: taken back too.
            $change = 0;
            if ($transaction->status === InvoiceTransactionStatus::Succeeded) {
                $credited = self::toCents($transaction->credited_amount);
                $onInvoice = self::toCents($transaction->amount) - self::toCents($transaction->refunded_amount) - $credited;
                $change = $transaction->is_credit_transaction ? $onInvoice : -$credited;
                if ($this->lockBoughtCredit($invoice)) {
                    $change -= $onInvoice;
                }
            }
            $credit = null;
            $balance = 0;
            if ($change !== 0 || $events->contains(fn (MoneyEvent $event) => $event->creditEffectCents() !== 0)) {
                if ($invoice->user_id === null) {
                    throw new DisplayException(__('This invoice has no customer, so the account credit this payment moved cannot be taken back: handle it by hand.'));
                }
                $credit = $this->lockCredit($invoice)
                    ?? $invoice->user->credits()->create(['currency_code' => $invoice->currency_code, 'amount' => 0]);
                $balance = CreditLedger::cents($credit);
                if ($balance + $change < 0 && !$allowNegativeCredit) {
                    throw new DisplayException(__('Part of this payment became account credit and the customer has already used it: delete it on its own and confirm that their credit goes negative (they owe it).'));
                }
            }

            // The journal follows the credit step by step (each event's balance after), then the row is saved once.
            $trail = new MoneyTrail;
            $journaled = 0;
            foreach ($events as $event) {
                $effect = -$event->creditEffectCents();
                if ($effect !== 0) {
                    $balance += $effect;
                    $journaled += $effect;
                    $credit->amount = $balance / 100;
                }
                $trail->reverse($event, MoneyTrail::CAUSE_TRANSACTION_DELETED, $effect !== 0 ? $credit : null);
            }
            // What the reversals do not cover: credit a credit purchase bought, or a payment from before the journal.
            $rest = $change - $journaled;
            if ($rest !== 0) {
                $balance += $rest;
                $credit->amount = $balance / 100;
                $rest > 0
                    ? $trail->creditIn($credit, $rest, MoneyTrail::CAUSE_TRANSACTION_DELETED, MoneyTrail::SOURCE_MANUAL, $invoice, $transaction)
                    : $trail->creditOut($credit, -$rest, MoneyTrail::CAUSE_TRANSACTION_DELETED, MoneyTrail::SOURCE_MANUAL, $invoice, $transaction);
            }
            $credit?->save();

            $transaction->delete();

            // The invoice follows (decision 1): a paid invoice its payments no longer cover is open again. Its services
            // stay renewed, as in WHMCS: staff decide (docs/money-trail.md §1, 2c).
            $invoice->refresh();
            if ($invoice->status === Invoice::STATUS_PAID && self::toCents($invoice->withLockedBalance()->remaining) > 0) {
                $invoice->status = Invoice::STATUS_PENDING;
                $invoice->save();
            }
        });
    }

    /**
     * Customer first, then the invoice. Every path that writes money (payments, credit, refunds, deletions)
     * takes them in this order before any transaction row: no deadlock.
     *
     * The owner is read before any lock: staff can move an unpaid invoice in between. Then the owner the locked
     * invoice really has is locked too, so that customer's payment paths stay serialised. That one lock comes after
     * the invoice's; the rare deadlock it can cause is detected by InnoDB and rolls one side back.
     */
    public function lockInvoice(mixed $invoiceId): Invoice
    {
        $userId = Invoice::query()->whereKey($invoiceId)->value('user_id');
        if ($userId !== null) {
            User::query()->whereKey($userId)->lockForUpdate()->first();
        }

        $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
        if ($invoice->user_id !== null && (int) $invoice->user_id !== (int) $userId) {
            User::query()->whereKey($invoice->user_id)->lockForUpdate()->first();
        }

        return $invoice;
    }

    /**
     * Whether the invoice gave its customer account credit: a credit line GrantBoughtCreditService applied
     * (applied_at, given only once the invoice is paid). Taking its money back (refund, deletion) takes that
     * credit back; an invoice that gave nothing takes nothing. Call under the payments' locks (customer, invoice):
     * the lines are read locked, before the credit row.
     *
     * @throws DisplayException a credit purchase that gave credit and has other lines: which part of a payment
     *                          bought the credit is not known
     */
    public function lockBoughtCredit(Invoice $invoice): bool
    {
        $items = $invoice->items()->lockForUpdate()->get(['reference_type', 'applied_at']);
        if (!$items->contains(fn (InvoiceItem $item) => $item->reference_type === Credit::class && $item->applied_at !== null)) {
            return false;
        }
        if ($items->contains(fn (InvoiceItem $item) => $item->reference_type !== Credit::class)) {
            throw new DisplayException(__('This invoice bought account credit together with other items: handle it by hand (take the credit back first).'));
        }

        return true;
    }

    /** The part of a payment the invoice cannot take: all of it unless pending/draft, else what exceeds the balance. */
    private function excessCents(Invoice $invoice, int $cents): int
    {
        if (!in_array($invoice->status, [Invoice::STATUS_PENDING, Invoice::STATUS_DRAFT], true)) {
            return $cents;
        }

        return max(0, $cents - max(0, self::toCents($invoice->withLockedBalance()->remaining)));
    }

    private function lockCredit(Invoice $invoice): ?Credit
    {
        return Credit::query()->where('user_id', $invoice->user_id)->where('currency_code', $invoice->currency_code)
            ->lockForUpdate()->first();
    }

    /** Pay $cents of the invoice from its customer's credit (checked by the caller under the same locks). */
    private function spendCredit(Invoice $invoice, int $cents, ?int $sourceId = null): InvoiceTransaction
    {
        $transaction = $invoice->transactions()->create(array_filter([
            'amount' => $cents / 100,
            'status' => InvoiceTransactionStatus::Succeeded,
            'is_credit_transaction' => true,
            'source_transaction_id' => $sourceId,
        ], fn ($value) => $value !== null));

        // Credit one sale left, paying other invoices: its events are grouped under that sale's transaction.
        $group = $sourceId === null ? null : 'transaction:' . $sourceId;
        (new CreditLedger)->remove($invoice->user_id, $invoice->currency_code, $cents, MoneyTrail::CAUSE_CREDIT_PAYMENT,
            MoneyTrail::SOURCE_CREDIT, $invoice, $transaction, group: $group);
        (new MoneyTrail)->payment($invoice, $transaction, MoneyTrail::SOURCE_CREDIT, $group);

        return $transaction;
    }

    /** @param  list<string>  $notices */
    private function addCredit(Invoice $invoice, InvoiceTransaction $transaction, int $cents, string $source, array &$notices): void
    {
        if ($cents <= 0) {
            return;
        }
        (new CreditLedger)->add($invoice->user_id, $invoice->currency_code, $cents, MoneyTrail::CAUSE_OVERPAYMENT, $source, $invoice, $transaction);

        $notices[] = sprintf(
            'Invoice #%d (%s) could not take %s %s of a payment; it was added to the customer\'s account credit.',
            $invoice->id, $invoice->status, number_format($cents / 100, 2, '.', ''), $invoice->currency_code,
        );
    }

    /** Positive, finite amounts only, in cents; anything else is 0. */
    public static function toCents(mixed $amount): int
    {
        if (is_bool($amount) || !is_numeric($amount)) {
            return 0;
        }
        $value = (float) $amount;
        if (!is_finite($value) || abs($value) > 1e12) {
            return 0;
        }

        return (int) round($value * 100);
    }

    private function show(mixed $amount): string
    {
        return is_scalar($amount) ? (string) $amount : get_debug_type($amount);
    }

    /** Logged now; staff are mailed after the commit (never for a rolled-back payment). */
    private function notify(string $message, mixed $invoiceId, ?string $transactionId, ?Gateway $gateway): void
    {
        Log::warning($message, ['invoice_id' => $invoiceId, 'transaction_id' => $transactionId, 'gateway' => $gateway?->extension]);
        DB::afterCommit(fn () => NotificationHelper::sendSystemEmailNotification('Payment needs attention', e($message)));
    }
}
