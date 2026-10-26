<?php

namespace App\Services\Ekdosi;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\PaymentsLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E3 of the Ekdosi bridge (docs/ekdosi-bridge.md): Ekdosi records here a payment it received for an invoice it issued on
 * credit («τιμολόγιο πριν την πληρωμή»), as its WHMCS plugin's `op=add_payment` did:
 *
 * - once: its transaction is `ekdosi:<transid>`; the same again answers «already recorded», on another invoice 409;
 * - never more than is owed: the amount is cut to what is left, so it never becomes account credit (the plugin's rule);
 * - nothing on an invoice already paid («already_settled», 200, as the plugin), nor on a draft, cancelled or refunded
 *   one, one in another currency, or one with a payment still on its way (409);
 * - under the payment locks (customer, then invoice; RecordPaymentService::lockInvoice), with the balance read locked.
 */
final class EkdosiPayments
{
    public const PREFIX = 'ekdosi:';

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: int, 1: array<string, mixed>} the HTTP status and the answer
     */
    public function record(array $body): array
    {
        // An int, or a string of digits: never `true` or `1.0` read as invoice 1 (ADV-4 of #464).
        $sent = $body['invoice_id'] ?? null;
        $invoiceId = is_int($sent) || (is_string($sent) && preg_match('/^[1-9]\d{0,18}$/D', $sent)) ? (int) $sent : false;
        $amount = is_string($body['amount'] ?? null) || is_int($body['amount'] ?? null) || is_float($body['amount'] ?? null) ? trim((string) $body['amount']) : '';
        $transId = is_string($body['transid'] ?? null) || is_int($body['transid'] ?? null) ? trim((string) $body['transid']) : '';
        if ($invoiceId === false || $invoiceId < 1 || !preg_match('/^\d{1,13}(\.\d{1,2})?$/', $amount) || bccomp($amount, '0', 2) <= 0
            || $transId === '' || mb_strlen($transId) > 100) {
            return [400, ['error' => 'bad_request', 'message' => 'add_payment requires {"invoice_id": <int>, "amount": "<positive, 2 decimals>", "transid": "<string>"}.']];
        }
        $transactionId = self::PREFIX . $transId;
        if (!Invoice::query()->whereKey($invoiceId)->whereNotNull('client_id')->exists()) {
            return [404, ['error' => 'invoice_not_found', 'invoice_id' => $invoiceId]];
        }

        return DB::transaction(function () use ($invoiceId, $amount, $transactionId) {
            $invoice = (new RecordPaymentService)->lockInvoice($invoiceId)->withLockedBalance();
            // Every transaction with this id, as the database compares it (on MariaDB without case or accents).
            $earlier = InvoiceTransaction::query()->where('transaction_id', $transactionId)->lockForUpdate()->get();
            // Another id that only the collation calls the same («ABC-1» / «abc-1»): never taken as this payment (ADV-3).
            if ($earlier->contains(fn (InvoiceTransaction $t) => $t->transaction_id !== $transactionId)) {
                return [409, ['error' => 'transid_conflict', 'message' => 'A payment with a near-identical transid (case or accents) exists: use a distinct one.']];
            }
            // Only Ekdosi's own (no gateway) are this payment: a gateway's with this id is another's money (R2-1), and a
            // processing one is never taken over (R2-2) — a conflict for a human, never «done» or completed.
            if ($earlier->contains(fn (InvoiceTransaction $t) => $t->gateway_id !== null
                && !($t->status === InvoiceTransactionStatus::Succeeded && (int) $t->invoice_id !== (int) $invoice->id))
                || $earlier->contains(fn (InvoiceTransaction $t) => $t->status === InvoiceTransactionStatus::Processing)) {
                return [409, ['error' => 'transid_conflict', 'message' => 'Another payment (a gateway\'s, or one still processing) has this transid.']];
            }
            // The same payment again (a retry): a succeeded Ekdosi one on this invoice. A failed one (ADV-2) is not money:
            // RecordPaymentService completes it below.
            $done = $earlier->first(fn (InvoiceTransaction $t) => $t->status === InvoiceTransactionStatus::Succeeded);
            if ($done !== null) {
                return (int) $done->invoice_id === (int) $invoice->id && $done->gateway_id === null
                    ? [200, ['status' => 'ok', 'note' => 'already_recorded', 'amount' => self::money($done->amount), 'remaining' => self::money($invoice->remaining)]]
                    : [409, ['error' => 'transid_on_another_invoice', 'invoice_id' => (int) $done->invoice_id]];
            }
            if ($earlier->contains(fn (InvoiceTransaction $t) => (int) $t->invoice_id !== (int) $invoice->id)) {
                return [409, ['error' => 'transid_on_another_invoice', 'invoice_id' => (int) $earlier->first()->invoice_id]];
            }
            // Only for an invoice Ekdosi issued an active document from (review of #464): with none, or one cancelled,
            // Ekdosi would hold money for a sale MyCS would offer it again to issue.
            if (!$invoice->ekdosiDocuments()->where('state', EkdosiDocument::ACTIVE)->exists()) {
                return [409, ['error' => 'no_active_document', 'message' => 'The invoice has no active fiscal document from Ekdosi.']];
            }
            if ($invoice->status === Invoice::STATUS_PAID) {
                // Paid here already (a gateway, a transfer): Ekdosi's money is not recorded — staff are told (ADV-1).
                self::tell("Ekdosi reported a payment of {$amount} € ({$transactionId}) for invoice #" . ($invoice->number ?: $invoice->id)
                    . ', already paid here: it was not recorded. The customer may have paid twice.', $invoice->id, $transactionId);

                return [200, ['status' => 'ok', 'note' => 'already_settled', 'remaining' => '0.00']];
            }
            if ($invoice->status !== Invoice::STATUS_UNPAID) {
                return [409, ['error' => 'invoice_not_payable', 'message' => 'The invoice is ' . $invoice->status . '.']];
            }
            if ($invoice->currency_code !== 'EUR') {
                return [409, ['error' => 'invoice_not_payable', 'message' => 'The invoice is in ' . $invoice->currency_code . '.']];
            }
            if ($invoice->transactions->contains(fn (InvoiceTransaction $t) => $t->status === InvoiceTransactionStatus::Processing)) {
                return [409, ['error' => 'payment_on_its_way', 'message' => 'A payment of this invoice is still being processed.']];
            }
            $remaining = number_format((float) $invoice->remaining, 2, '.', '');
            if (bccomp($remaining, '0', 2) <= 0) {
                self::tell("Ekdosi reported a payment of {$amount} € ({$transactionId}) for invoice #" . ($invoice->number ?: $invoice->id)
                    . ', which owes nothing: it was not recorded.', $invoice->id, $transactionId);

                return [200, ['status' => 'ok', 'note' => 'already_settled', 'remaining' => '0.00']];
            }
            // What is left, at most: never account credit (the plugin's cap).
            $pay = bccomp($amount, $remaining, 2) > 0 ? $remaining : bcadd($amount, '0', 2);
            $transaction = ExtensionHelper::addPayment($invoice, null, $pay, null, $transactionId, currency: 'EUR');
            // A new one, or the failed/processing one with this id completed (ADV-2).
            if ($transaction === null || $transaction->status !== InvoiceTransactionStatus::Succeeded
                || !($transaction->wasRecentlyCreated || $earlier->contains('id', $transaction->id))
                || (int) $transaction->invoice_id !== (int) $invoice->id) {
                throw new \RuntimeException('The Ekdosi payment of invoice ' . $invoice->id . ' was not recorded as expected.');
            }
            $capped = $pay !== bcadd($amount, '0', 2);
            $left = bcsub($remaining, $pay, 2);
            // Money that does not add up is told, never silent (review of #464): more than was owed (the part over is
            // not recorded), or less (the invoice stays unpaid here, its reminders go on).
            if ($capped) {
                self::tell("Ekdosi reported a payment of {$amount} € ({$transactionId}) for invoice #" . ($invoice->number ?: $invoice->id)
                    . ", which owed {$remaining} €: {$pay} € was recorded, " . bcsub($amount, $pay, 2) . ' € was not.', $invoice->id, $transactionId);
            } elseif (bccomp($left, '0', 2) > 0) {
                self::tell("Ekdosi reported a payment of {$pay} € ({$transactionId}) for invoice #" . ($invoice->number ?: $invoice->id)
                    . ": recorded; {$left} € is still owed here.", $invoice->id, $transactionId);
            }

            return [200, ['status' => 'ok', 'amount' => $pay, 'capped' => $capped, 'remaining' => $left]];
        });
    }

    /** What staff must look at: the payments log, and the system mail after the commit (as RecordPaymentService). */
    private static function tell(string $message, int $invoiceId, string $transactionId): void
    {
        // Once per payment: a retry of the same transid (a lost answer, a sync run again) is not told again (R2-3).
        if (!Cache::add('ekdosi-payment-told:' . sha1($transactionId), true, now()->addDays(90))) {
            return;
        }
        PaymentsLog::warning($message, ['invoice_id' => $invoiceId, 'transaction_id' => $transactionId, 'source' => 'ekdosi']);
        DB::afterCommit(fn () => NotificationHelper::sendSystemEmailNotification('Payment needs attention', e($message)));
    }

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
