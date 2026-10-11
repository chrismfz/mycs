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
 * The one way money is written to an invoice (docs/payments.md, PR 2a): gateways and credits.
 *
 * - Locks the customer first, then the invoice: every payment path of one customer is serialised,
 *   so a duplicate transaction id is found on any of the customer's invoices (a replayed PayPal
 *   subscription webhook lands on «the latest invoice», which may be another one).
 * - The same gateway + transaction id already succeeded: nothing (Τ2). A succeeded transaction never
 *   goes back to processing/failed.
 * - Only a positive amount, and the invoice's currency when the gateway states one (Τ3): otherwise
 *   nothing is written, it is logged and staff are told.
 * - Money the invoice cannot take (cancelled, already paid, or above what is left) is still recorded
 *   on the invoice (the money arrived) and the part it cannot take becomes account credit (Τ7).
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
        $cents = is_numeric($amount) ? (int) round((float) $amount * 100) : 0;

        if ($cents <= 0) {
            $this->refuse("Payment of «{$amount}» on invoice #{$invoiceId} refused: the amount must be positive.", $invoiceId, $transactionId, $gateway);

            return null;
        }

        $notices = [];
        $transaction = DB::transaction(function () use ($invoiceId, $gateway, $cents, $fee, $transactionId, $status, $isCreditTransaction, $currency, &$notices) {
            // Customer first, always: the credit paths lock the customer before their credit row too.
            $userId = Invoice::query()->whereKey($invoiceId)->value('user_id');
            if ($userId !== null) {
                User::query()->whereKey($userId)->lockForUpdate()->first();
            }
            $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();

            if ($currency !== null && strtoupper($currency) !== strtoupper((string) $invoice->currency_code)) {
                $notices[] = "Payment in {$currency} on invoice #{$invoice->id} ({$invoice->currency_code}) was NOT recorded: currency mismatch. Check it with the gateway.";

                return null;
            }

            $existing = $transactionId === null ? null : InvoiceTransaction::query()
                ->where('transaction_id', $transactionId)
                ->where('gateway_id', $gateway?->id)
                ->whereHas('invoice', fn ($query) => $query->where('user_id', $invoice->user_id))
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status === InvoiceTransactionStatus::Succeeded) {
                    if ($fee !== null && $existing->fee === null) {
                        $existing->update(['fee' => $fee]); // a late fee report (Stripe)
                    }

                    return $existing; // a replay: already recorded
                }
                if ($status !== InvoiceTransactionStatus::Succeeded) {
                    $existing->update(array_filter(['status' => $status, 'fee' => $fee], fn ($value) => $value !== null));

                    return $existing;
                }
                // processing/failed -> succeeded, on the invoice it was started on
                $target = $existing->invoice_id === $invoice->id ? $invoice
                    : Invoice::query()->whereKey($existing->invoice_id)->lockForUpdate()->firstOrFail();
                $excess = $this->excessCents($target, $cents);
                $existing->update(array_filter([
                    'amount' => $cents / 100,
                    'fee' => $fee,
                    'status' => $status,
                ], fn ($value) => $value !== null));
                $this->credit($target, $excess, $notices);

                return $existing;
            }

            $excess = $status === InvoiceTransactionStatus::Succeeded ? $this->excessCents($invoice, $cents) : 0;
            $transaction = $invoice->transactions()->create([
                'gateway_id' => $gateway?->id,
                'amount' => $cents / 100,
                'fee' => $fee,
                'transaction_id' => $transactionId,
                'status' => $status,
                'is_credit_transaction' => $isCreditTransaction,
            ]);
            $this->credit($invoice, $excess, $notices);

            return $transaction;
        });

        foreach ($notices as $notice) {
            $this->refuse($notice, $invoiceId, $transactionId, $gateway);
        }

        return $transaction;
    }

    /** The part of a payment the invoice cannot take: all of it unless pending/draft, else what exceeds the balance. */
    private function excessCents(Invoice $invoice, int $cents): int
    {
        if (!in_array($invoice->status, [Invoice::STATUS_PENDING, Invoice::STATUS_DRAFT], true)) {
            return $cents;
        }
        $remaining = (int) round((float) $invoice->fresh()->remaining * 100);

        return max(0, $cents - max(0, $remaining));
    }

    /** @param  list<string>  $notices */
    private function credit(Invoice $invoice, int $cents, array &$notices): void
    {
        if ($cents <= 0) {
            return;
        }
        $credit = Credit::query()->where('user_id', $invoice->user_id)->where('currency_code', $invoice->currency_code)
            ->lockForUpdate()->first()
            ?? $invoice->user->credits()->create(['currency_code' => $invoice->currency_code, 'amount' => 0]);
        $credit->amount = ((int) round((float) $credit->amount * 100) + $cents) / 100;
        $credit->save();

        $notices[] = sprintf(
            'Invoice #%d (%s) could not take %s %s of a payment; it was added to the customer\'s account credit.',
            $invoice->id, $invoice->status, number_format($cents / 100, 2, '.', ''), $invoice->currency_code,
        );
    }

    private function refuse(string $message, mixed $invoiceId, ?string $transactionId, ?Gateway $gateway): void
    {
        Log::warning($message, ['invoice_id' => $invoiceId, 'transaction_id' => $transactionId, 'gateway' => $gateway?->extension]);
        NotificationHelper::sendSystemEmailNotification('Payment needs attention', e($message));
    }
}
