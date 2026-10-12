<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
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
    ): ?InvoiceTransaction {
        $invoiceId = $invoice instanceof Invoice ? $invoice->getKey() : $invoice;
        $transactionId = $transactionId === null || $transactionId === '' ? null : (string) $transactionId;
        $cents = self::toCents($amount);

        if ($cents <= 0) {
            $this->notify("Payment of «{$this->show($amount)}» on invoice #{$invoiceId} refused: the amount must be positive.", $invoiceId, $transactionId, $gateway);

            return null;
        }

        $notices = [];
        $transaction = DB::transaction(function () use ($invoiceId, $gateway, $cents, $fee, $transactionId, $status, $isCreditTransaction, $currency, &$notices) {
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
                $existing->update($values);
                $transaction = $existing;
            } else {
                $transaction = $target->transactions()->create($values);
            }
            $this->addCredit($target, $excess, $notices);

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
            $available = $credit === null ? 0 : self::toCents($credit->amount);
            $remaining = max(0, self::toCents($invoice->withLockedBalance()->remaining));
            $cents = min($available, $remaining);
            if ($credit === null || $cents <= 0 || ($onlyIfCovers && $available < $remaining)) {
                return null;
            }

            return $this->spendCredit($invoice, $credit, $cents);
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
            $available = $credit === null ? 0 : self::toCents($credit->amount);
            $remaining = max(0, self::toCents($invoice->withLockedBalance()->remaining));
            $cents = min($budget, $available, $remaining);
            if ($credit === null || $cents <= 0) {
                return null;
            }

            return $this->spendCredit($invoice, $credit, $cents, $source->id);
        });
    }

    /**
     * Delete a transaction (staff correcting a mistake) without leaving money behind: the part that became
     * account credit is taken back, and a payment made with credit gives it back. Refused when that credit
     * was already spent, when it paid other invoices, or when the payment has refunds.
     *
     * @throws DisplayException
     */
    public function deleteTransaction(InvoiceTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
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

            // Credit to give back (+) or take back (-). A payment made with credit: what is still on the invoice
            // goes back (its credited part is already back). Any other payment: what became credit is taken back.
            $change = 0;
            if ($transaction->status === InvoiceTransactionStatus::Succeeded) {
                $credited = self::toCents($transaction->credited_amount);
                $change = $transaction->is_credit_transaction
                    ? self::toCents($transaction->amount) - self::toCents($transaction->refunded_amount) - $credited
                    : -$credited;
            }
            if ($change !== 0) {
                $credit = $this->lockCredit($invoice)
                    ?? $invoice->user->credits()->create(['currency_code' => $invoice->currency_code, 'amount' => 0]);
                $available = self::toCents($credit->amount);
                if ($available + $change < 0) {
                    throw new DisplayException(__('Part of this payment became account credit and the customer has already used it: it cannot be deleted.'));
                }
                $credit->amount = ($available + $change) / 100;
                $credit->save();
            }

            $transaction->delete();
        });
    }

    /**
     * Customer first, then the invoice. Every path that writes money (payments, credit, refunds, deletions)
     * takes them in this order before any transaction row: no deadlock.
     */
    public function lockInvoice(mixed $invoiceId): Invoice
    {
        $userId = Invoice::query()->whereKey($invoiceId)->value('user_id');
        if ($userId !== null) {
            User::query()->whereKey($userId)->lockForUpdate()->first();
        }

        return Invoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
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

    private function spendCredit(Invoice $invoice, Credit $credit, int $cents, ?int $sourceId = null): InvoiceTransaction
    {
        $credit->amount = (self::toCents($credit->amount) - $cents) / 100;
        $credit->save();

        return $invoice->transactions()->create(array_filter([
            'amount' => $cents / 100,
            'status' => InvoiceTransactionStatus::Succeeded,
            'is_credit_transaction' => true,
            'source_transaction_id' => $sourceId,
        ], fn ($value) => $value !== null));
    }

    /** @param  list<string>  $notices */
    private function addCredit(Invoice $invoice, int $cents, array &$notices): void
    {
        if ($cents <= 0) {
            return;
        }
        $credit = $this->lockCredit($invoice)
            ?? $invoice->user->credits()->create(['currency_code' => $invoice->currency_code, 'amount' => 0]);
        $credit->amount = (self::toCents($credit->amount) + $cents) / 100;
        $credit->save();

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
