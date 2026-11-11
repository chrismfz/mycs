<?php

namespace App\Services\Service;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\RecordPaymentService;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Sweep 10 (S10-STAFF-FREEDOM-1/2, owner 2026-11-12): an invoice that is open, owes, and was applied already (it gave
 * its service a period, or its upgrade was completed, and its payment was taken back since). Paying it gives nothing
 * again (RenewServiceService, ServiceUpgradeService: applied once), so staff who take back what it gave (the due date
 * moved back, the package changed back) and leave it open are told on that save. Read-only: it warns, nothing is
 * refused or changed. The money check's `service_unbilled` advises both steps (UnbilledServices).
 */
final class AppliedOpenInvoices
{
    /**
     * What to tell staff after they saved the service: its due date moved back before the end of a period an open
     * invoice gave it, or its product changed from the one an upgrade with an open invoice gave it.
     *
     * @param  DateTimeInterface|string|null  $dueBefore  its due date before the save
     * @param  int|null  $productBefore  its product before the save
     * @return list<string>
     */
    public static function warnings(Service $service, DateTimeInterface|string|null $dueBefore, ?int $productBefore): array
    {
        $warnings = [];
        $due = $service->expires_at === null ? null : CarbonImmutable::parse($service->expires_at)->toDateString();
        if ($due !== null && $dueBefore !== null && $due < CarbonImmutable::parse($dueBefore)->toDateString()) {
            $lines = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)
                ->whereNotNull('applied_at')->whereNotNull('period_end')->whereDate('period_end', '>', $due)
                ->where(fn ($line) => $line->whereNull('kind')->orWhereNotIn('kind', [InvoiceItem::KIND_CHARGE, InvoiceItem::KIND_DISCOUNT]))
                ->orderBy('invoice_id')->pluck('invoice_id')->unique();
            foreach (self::owing($lines->all(), $service) as $invoice) {
                $warnings[] = __('Invoice #:id is still open and was already applied: paying it will not renew the service again. Cancel it (or collect it and move the date forward).', ['id' => $invoice->id]);
            }
        }
        if ($productBefore !== null && (int) $service->product_id !== $productBefore) {
            $upgrades = ServiceUpgrade::query()->where('service_id', $service->id)->where('status', ServiceUpgrade::STATUS_COMPLETED)
                ->where('product_id', $productBefore)->whereNotNull('invoice_id')->orderBy('invoice_id')->pluck('invoice_id')->unique();
            foreach (self::owing($upgrades->all(), $service) as $invoice) {
                $warnings[] = __('Upgrade invoice #:id is still open and its upgrade was already applied: paying it will not change the package again. Cancel it (or collect it and change the package again).', ['id' => $invoice->id]);
            }
        }

        return $warnings;
    }

    /**
     * Those of them the customer can still pay: unpaid, the service's customer's, with something left to pay.
     *
     * @param  array<int, mixed>  $ids
     * @return list<Invoice>
     */
    private static function owing(array $ids, Service $service): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(Invoice::query()->whereKey($ids)->where('status', Invoice::STATUS_UNPAID)->where('client_id', $service->client_id)
            ->with(['items', 'adjustmentNotes', 'transactions'])->orderBy('id')->get()
            ->filter(fn (Invoice $invoice) => RecordPaymentService::toCents($invoice->remaining) > 0)->all());
    }
}
