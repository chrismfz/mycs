<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Money\CreditAutoApply;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cancel open invoices the app gives up on (the cron, a cancellation request): account credit paid on them goes back
 * to the customer first (MT-1e: credit is also applied in part). An upgrade's invoice through the model (its
 * cancellation note), the others by a plain update.
 *
 * Left open for staff, as a failure the cron mails: an invoice that holds money a gateway or a bank transfer paid (it
 * would sit on a cancelled invoice, neither credited nor refunded) and, with $onlyFor, an invoice that also bills
 * something else (another service's renewal would be cancelled with it). MT-2 makes the cancellations line-aware and
 * decides what that money becomes (audit sweep SVC-4).
 */
final class CancelOpenInvoices
{
    /**
     * @param  iterable<Invoice>  $invoices
     * @param  Service|null  $onlyFor  cancel only invoices that bill nothing but this service
     * @return array<string, Throwable> what was left open or failed, by invoice («invoice_cancelled #id»)
     *
     * @throws Throwable inside a caller's transaction: a failure (a deadlock rolled the caller's work back already)
     *                   is the caller's, never swallowed here
     */
    public static function handle(iterable $invoices, bool $throughModel = false, ?Service $onlyFor = null): array
    {
        $nested = DB::transactionLevel() > 0;
        $failures = [];
        foreach ($invoices as $invoice) {
            if (($reason = self::keepOpen($invoice, $onlyFor)) !== null) {
                $failures["invoice_cancelled #{$invoice->id}"] = new DisplayException("Invoice #{$invoice->id} left open: {$reason}");

                continue;
            }
            try {
                DB::transaction(function () use ($invoice, $throughModel) {
                    (new CreditAutoApply)->giveBack($invoice);
                    $throughModel
                        ? $invoice->refresh()->update(['status' => Invoice::STATUS_CANCELLED])
                        : Invoice::query()->whereKey($invoice->id)->where('status', Invoice::STATUS_PENDING)->update(['status' => Invoice::STATUS_CANCELLED]);
                });
            } catch (Throwable $e) {
                if ($nested) {
                    throw $e;
                }
                report($e);
                $failures["invoice_cancelled #{$invoice->id}"] = $e;
            }
        }

        return $failures;
    }

    /** Why an invoice must stay open for staff, or null. */
    private static function keepOpen(Invoice $invoice, ?Service $onlyFor): ?string
    {
        if ($invoice->transactions()->where('status', InvoiceTransactionStatus::Succeeded)->where('is_credit_transaction', false)->exists()) {
            return 'it holds money a gateway or a transfer paid (refund it or move it to credit, then cancel it).';
        }
        // Its own lines: this service's renewals and upgrades; a line with no reference (a setup fee, a promotion, an
        // imported WHMCS Promo/Setup line) goes with the invoice.
        $upgrades = ServiceUpgrade::query()->where('service_id', $onlyFor?->id)->pluck('id');
        if ($onlyFor !== null && $invoice->items()->whereNotNull('reference_type')->where(fn ($query) => $query
            ->whereNot(fn ($own) => $own->where('reference_type', Service::class)->where('reference_id', $onlyFor->id))
            ->whereNot(fn ($own) => $own->where('reference_type', ServiceUpgrade::class)->whereIn('reference_id', $upgrades))
        )->exists()) {
            return "it also bills something other than service #{$onlyFor->id} (remove that line, or cancel it by hand).";
        }

        return null;
    }
}
