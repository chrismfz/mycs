<?php

namespace App\Services\Invoice;

use App\Exceptions\DisplayException;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use Illuminate\Support\Facades\DB;

/**
 * One payment over several invoices (PR F1, decision 7, sweep Π2.9/Π3.1): a bank transfer or a PayPal payment that covers
 * a customer's invoices. Recorded once, on the first invoice (oldest due first); what it leaves as credit pays the others,
 * each its whole remaining in that order, as allocations of that payment (source_transaction_id, its money events grouped
 * under it). What is left after the last becomes account credit. All in one transaction under the customer → invoices
 * locks, so nothing pays an invoice in between. No container invoice (WHMCS Mass Pay): each invoice holds its own share.
 *
 * Only unpaid invoices of one customer in one currency. One that was paid meanwhile is refused by the form; under the locks
 * an invoice with nothing left simply takes nothing.
 */
final class PayInvoices
{
    /**
     * @param  list<int>  $invoiceIds
     * @return array{0: InvoiceTransaction, 1: list<InvoiceTransaction>} the payment, and what it paid on the other invoices
     */
    public function recordByStaff(array $invoiceIds, ?Gateway $gateway, mixed $amount, ?string $transactionId = null, ?\DateTimeInterface $paidAt = null): array
    {
        $invoiceIds = array_values(array_unique(array_map('intval', $invoiceIds)));
        if (count($invoiceIds) < 2) {
            throw new DisplayException(__('Choose at least two invoices: one invoice is paid with Mark Paid.'));
        }
        if (RecordPaymentService::toCents($amount) <= 0) {
            throw new DisplayException(__('The amount must be positive.'));
        }

        return DB::transaction(function () use ($invoiceIds, $gateway, $amount, $transactionId, $paidAt) {
            $invoices = self::lock($invoiceIds);
            foreach ($invoices as $invoice) {
                if ($invoice->status !== Invoice::STATUS_UNPAID) {
                    throw new DisplayException(__('Invoice :invoice is not unpaid: only unpaid invoices are paid together.', ['invoice' => '#' . ($invoice->number ?: $invoice->id)]));
                }
            }
            if ($invoices->pluck('currency_code')->unique()->count() !== 1) {
                throw new DisplayException(__('Only invoices in one currency are paid together.'));
            }
            $ordered = $invoices->sortBy(fn (Invoice $invoice) => [$invoice->due_at?->toDateString() ?? '9999-12-31', $invoice->id])->values();

            // On the first: its remaining, and the rest as credit (RecordPaymentService). Staff never type the id of
            // another invoice's payment here: recordByStaff refuses it.
            $payment = (new RecordPaymentService)->recordByStaff($ordered->first(), $gateway, $amount, $transactionId, paidAt: $paidAt);
            $paid = [];
            foreach ($ordered->slice(1) as $invoice) {
                $allocation = (new RecordPaymentService)->allocateFromTransaction($payment, $invoice, oneCyclePerService: false);
                if ($allocation !== null) {
                    $paid[] = $allocation;
                }
            }

            return [$payment->fresh(), $paid];
        });
    }

    /**
     * The invoices, locked: their customer first, then each in id order (the one order every money path takes).
     *
     * @param  list<int>  $ids
     * @return \Illuminate\Database\Eloquent\Collection<int, Invoice>
     */
    private static function lock(array $ids): \Illuminate\Database\Eloquent\Collection
    {
        sort($ids);
        $clients = Invoice::query()->whereKey($ids)->distinct()->pluck('client_id');
        if ($clients->count() !== 1 || $clients->first() === null) {
            throw new DisplayException(__('Only invoices of one customer are paid together.'));
        }
        $client = (int) $clients->first();
        Client::lock($client);
        $invoices = Invoice::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        if ($invoices->count() !== count($ids) || $invoices->contains(fn (Invoice $invoice) => (int) $invoice->client_id !== $client)) {
            throw new DisplayException(__('One of these invoices changed meanwhile: reload the page.'));
        }

        return $invoices;
    }
}
