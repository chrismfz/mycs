<?php

namespace App\Services\Invoice;

use App\Helpers\NotificationHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Services\Money\PaymentsLog;
use Illuminate\Support\Facades\DB;

class GrantBoughtCreditService
{
    /**
     * Called by InvoiceObserver inside the paid transition's transaction, with customer and invoice
     * locked. Credit and applied_at must commit with paid: a refund/delete can then take it back even
     * before the after-commit provisioning callback runs. A failure rolls back the payment too.
     */
    public function handle(Invoice $invoice): void
    {
        $items = $invoice->items()->where('reference_type', Credit::class)->lockForUpdate()->get();
        // An invoice left without a customer (deleted before money-trail §8): its payment is still recorded, but there
        // is no account to give the credit to. The lines stay unapplied; staff are told.
        if ($invoice->client_id === null) {
            if ($items->contains(fn ($item) => $item->applied_at === null)) {
                $message = "Credit purchase invoice #{$invoice->id} was paid but has no customer: its credit was NOT given. Handle it by hand.";
                PaymentsLog::warning($message, ['invoice_id' => $invoice->id]);
                DB::afterCommit(fn () => NotificationHelper::sendSystemEmailNotification('Payment needs attention', e($message)));
            }

            return;
        }
        foreach ($items as $item) {
            if ($item->applied_at !== null) {
                continue;
            }

            // By the current client_id: a reassigned invoice can still have the previous owner cached in its relation.
            // A line staff edited to a negative price takes credit away (it may leave the balance negative).
            // What the line charges (Δ1: × quantity; a credit line has no tax), the same amount the invoice settles.
            $cents = $item->totalCents();
            $ledger = new CreditLedger;
            if ($cents > 0) {
                $ledger->add($invoice->client_id, $invoice->currency_code, $cents, MoneyTrail::CAUSE_PURCHASE, MoneyTrail::SOURCE_SYSTEM, $invoice);
            } elseif ($cents < 0) {
                $ledger->remove($invoice->client_id, $invoice->currency_code, -$cents, MoneyTrail::CAUSE_PURCHASE,
                    MoneyTrail::SOURCE_SYSTEM, $invoice, mayGoNegative: true);
            }
            $item->forceFill(['applied_at' => now()])->save();
        }
        // Paid again after a chargeback, or a refund or deletion while it was open again, took part of its credit (paid
        // anew, or the dispute won): what they took comes back, once (their credit-out reversed). Its lines stay applied
        // through those, so they give nothing twice (sweep2 F-REFUND-CHARGEBACK-1, sweep3 L-LEDGER-INVARIANT-1).
        // Only as far as money stays on it: closed by a note (a lost dispute written off), a take is not given back for
        // nothing (sweep3 L-LEDGER-INVARIANT-2). The credit it then holds: what its lines bought, up to the money kept.
        $payments = new RecordPaymentService;
        $takes = RecordPaymentService::takesSinceGrant($invoice);
        if ($takes->isEmpty()) {
            return;
        }
        $room = min($payments->boughtCreditCents($invoice), RecordPaymentService::keptCents($invoice)) - $payments->boughtCreditLeftCents($invoice);
        foreach ($takes as $taken) {
            if ($room <= 0) {
                break; // not given back: it stays a take, given back if the invoice is paid again with money for it
            }
            $ledger = new CreditLedger;
            $ledger->giveBack($taken, MoneyTrail::CAUSE_PURCHASE);
            $room -= RecordPaymentService::toCents($taken->amount);
            if ($room < 0) {
                // Given back in part: the part no money stays for is taken again, still an open take (purchase_reopened,
                // not a final purchase_refunded), given back if the invoice is paid again with money for it (a partial
                // write-off undone and paid, review REV3-LLI-1), as a whole take kept open is.
                $ledger->remove($invoice->client_id, $invoice->currency_code, -$room, MoneyTrail::CAUSE_PURCHASE_REOPENED,
                    MoneyTrail::SOURCE_SYSTEM, $invoice, mayGoNegative: true);
            }
        }
    }
}
