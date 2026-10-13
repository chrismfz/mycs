<?php

namespace App\Services\Invoice;

use App\Models\Invoice;
use App\Services\Money\CreditAutoApply;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cancel open invoices the app gives up on (the cron, a cancellation request): account credit paid on them goes back
 * to the customer first (MT-1e: credit is also applied in part). An invoice whose credit cannot be given back stays
 * open for staff. An upgrade's invoice through the model (its cancellation note), the others by a plain update.
 *
 * Whole invoices, as before: one that also renews other services is cancelled too (audit sweep SVC-4; MT-2 makes the
 * mass cancellations line-aware and gives them their credit note).
 */
final class CancelOpenInvoices
{
    /**
     * @param  iterable<Invoice>  $invoices
     * @return array<string, Throwable> the failures, by invoice («invoice_cancelled #id»)
     */
    public static function handle(iterable $invoices, bool $throughModel = false): array
    {
        $failures = [];
        foreach ($invoices as $invoice) {
            try {
                DB::transaction(function () use ($invoice, $throughModel) {
                    (new CreditAutoApply)->giveBack($invoice);
                    $throughModel
                        ? $invoice->refresh()->update(['status' => Invoice::STATUS_CANCELLED])
                        : Invoice::query()->whereKey($invoice->id)->where('status', Invoice::STATUS_PENDING)->update(['status' => Invoice::STATUS_CANCELLED]);
                });
            } catch (Throwable $e) {
                report($e);
                $failures["invoice_cancelled #{$invoice->id}"] = $e;
            }
        }

        return $failures;
    }
}
