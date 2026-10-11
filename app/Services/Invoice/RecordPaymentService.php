<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\NotificationHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
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

            $existing = $transactionId === null ? null : InvoiceTransaction::query()
                ->where('transaction_id', $transactionId)
                ->where('gateway_id', $gateway?->id)
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
            $credit = Credit::query()->where('user_id', $invoice->user_id)->where('currency_code', $invoice->currency_code)
                ->lockForUpdate()->first();
            $available = $credit === null ? 0 : self::toCents($credit->amount);
            $remaining = max(0, self::toCents($invoice->remaining));
            $cents = min($available, $remaining);
            if ($cents <= 0 || ($onlyIfCovers && $available < $remaining)) {
                return null;
            }

            $credit->amount = ($available - $cents) / 100;
            $credit->save();

            return $invoice->transactions()->create([
                'amount' => $cents / 100,
                'status' => InvoiceTransactionStatus::Succeeded,
                'is_credit_transaction' => true,
            ]);
        });
    }

    /** Customer first, then the invoice (every payment path takes them in this order: no deadlock). */
    private function lockInvoice(mixed $invoiceId): Invoice
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

        return max(0, $cents - max(0, self::toCents($invoice->fresh()->remaining)));
    }

    /** @param  list<string>  $notices */
    private function addCredit(Invoice $invoice, int $cents, array &$notices): void
    {
        if ($cents <= 0) {
            return;
        }
        $credit = Credit::query()->where('user_id', $invoice->user_id)->where('currency_code', $invoice->currency_code)
            ->lockForUpdate()->first()
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
