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
            $cents = (int) round((float) $item->price * 100);
            $ledger = new CreditLedger;
            if ($cents > 0) {
                $ledger->add($invoice->client_id, $invoice->currency_code, $cents, MoneyTrail::CAUSE_PURCHASE, MoneyTrail::SOURCE_SYSTEM, $invoice);
            } elseif ($cents < 0) {
                $ledger->remove($invoice->client_id, $invoice->currency_code, -$cents, MoneyTrail::CAUSE_PURCHASE,
                    MoneyTrail::SOURCE_SYSTEM, $invoice, mayGoNegative: true);
            }
            $item->forceFill(['applied_at' => now()])->save();
        }
    }
}
