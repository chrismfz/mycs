<?php

namespace App\Services\Invoice;

use App\Models\Credit;
use App\Models\Invoice;
use App\Helpers\NotificationHelper;
use App\Services\Money\MoneyTrail;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        if ($invoice->user_id === null) {
            if ($items->contains(fn ($item) => $item->applied_at === null)) {
                $message = "Credit purchase invoice #{$invoice->id} was paid but has no customer: its credit was NOT given. Handle it by hand.";
                Log::warning($message, ['invoice_id' => $invoice->id]);
                DB::afterCommit(fn () => NotificationHelper::sendSystemEmailNotification('Payment needs attention', e($message)));
            }

            return;
        }
        foreach ($items as $item) {
            if ($item->applied_at !== null) {
                continue;
            }

            $credit = Credit::query()->where('user_id', $invoice->user_id)
                ->where('currency_code', $invoice->currency_code)->lockForUpdate()->first();
            if ($credit !== null) {
                $credit->setAttribute('amount', (string) BigDecimal::of((string) $credit->getRawOriginal('amount'))->plus($item->price));
                $credit->save();
            } else {
                // A reassigned invoice can still have the previous owner cached in its user relation.
                $credit = Credit::query()->create([
                    'user_id' => $invoice->user_id,
                    'currency_code' => $invoice->currency_code,
                    'amount' => $item->price,
                ]);
            }
            // A line staff edited to a negative price takes credit away: journaled as such (toCents is positive-only).
            $cents = (int) BigDecimal::of((string) $item->price)->multipliedBy(100)->toScale(0, RoundingMode::HALF_UP)->toInt();
            if ($cents > 0) {
                (new MoneyTrail)->creditIn($credit, $cents, MoneyTrail::CAUSE_PURCHASE, MoneyTrail::SOURCE_SYSTEM, $invoice);
            } elseif ($cents < 0) {
                (new MoneyTrail)->creditOut($credit, -$cents, MoneyTrail::CAUSE_PURCHASE, MoneyTrail::SOURCE_SYSTEM, $invoice);
            }
            $item->forceFill(['applied_at' => now()])->save();
        }
    }
}
