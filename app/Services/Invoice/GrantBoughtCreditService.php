<?php

namespace App\Services\Invoice;

use App\Models\Credit;
use App\Models\Invoice;
use App\Services\Money\MoneyTrail;
use Brick\Math\BigDecimal;

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
            $cents = RecordPaymentService::toCents($item->price);
            if ($cents > 0) {
                (new MoneyTrail)->creditIn($credit, $cents, MoneyTrail::CAUSE_PURCHASE, MoneyTrail::SOURCE_SYSTEM, $invoice);
            }
            $item->forceFill(['applied_at' => now()])->save();
        }
    }
}
