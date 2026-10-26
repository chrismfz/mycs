<?php

namespace App\Services\Ekdosi;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\RecordPaymentService;
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
        $invoiceId = filter_var($body['invoice_id'] ?? null, FILTER_VALIDATE_INT);
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
            // The same payment again (a retry): answered as done, on its own invoice only.
            $earlier = InvoiceTransaction::query()->where('transaction_id', $transactionId)->lockForUpdate()->first();
            if ($earlier !== null) {
                return (int) $earlier->invoice_id === (int) $invoice->id
                    ? [200, ['status' => 'ok', 'note' => 'already_recorded', 'amount' => self::money($earlier->amount)]]
                    : [409, ['error' => 'transid_on_another_invoice', 'invoice_id' => (int) $earlier->invoice_id]];
            }
            if ($invoice->status === Invoice::STATUS_PAID) {
                return [200, ['status' => 'ok', 'note' => 'already_settled']];
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
                return [200, ['status' => 'ok', 'note' => 'already_settled']];
            }
            // What is left, at most: never account credit (the plugin's cap).
            $pay = bccomp($amount, $remaining, 2) > 0 ? $remaining : bcadd($amount, '0', 2);
            $transaction = ExtensionHelper::addPayment($invoice, null, $pay, null, $transactionId, currency: 'EUR');
            if ($transaction === null || $transaction->status !== InvoiceTransactionStatus::Succeeded || !$transaction->wasRecentlyCreated
                || (int) $transaction->invoice_id !== (int) $invoice->id) {
                throw new \RuntimeException('The Ekdosi payment of invoice ' . $invoice->id . ' was not recorded as expected.');
            }

            return [200, ['status' => 'ok', 'amount' => $pay, 'capped' => $pay !== bcadd($amount, '0', 2)]];
        });
    }

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
