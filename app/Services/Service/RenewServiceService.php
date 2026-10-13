<?php

namespace App\Services\Service;

use App\Helpers\NotificationHelper;
use App\Jobs\Server\CreateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Observers\ServiceObserver;
use App\Services\Money\PaymentsLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Renew a service after an invoice line is paid (docs/billing-cursor.md).
 *
 * Every paid service line ends up with a period: a line without one (first order, older invoices,
 * the WHMCS import, an invoice staff made by hand) gets the first whole period no live invoice covers
 * yet, from the expiry (decision A), or starting today for a service that never started. The paid-until date
 * (expires_at) then moves over contiguous PAID periods only: paying the same period twice, an
 * older invoice after a newer one, or a period staff already extended adds nothing; a period paid
 * ahead of an unpaid one waits and is picked up once the gap is paid.
 *
 * A payment that adds no time changes nothing when it is a duplicate (the period was paid by
 * another line), a replay of a line already applied (applied_at), or ahead of an unpaid period, so
 * it never unsuspends a service suspended for another reason. On its first processing it does
 * activate a service whose date staff already moved over that period (grace given before the
 * payment arrived). A cancelled service is never revived by a payment:
 * staff decide (docs/billing-cursor.md, PR 3).
 */
class RenewServiceService
{
    public function handle(Service $service, ?InvoiceItem $item = null): void
    {
        DB::transaction(function () use ($service, $item) {
            $locked = Service::query()->whereKey($service->getKey())->lockForUpdate()->firstOrFail();
            // Re-read the line under the service lock: a concurrent payment may have given it its period.
            $item = $item ? (InvoiceItem::query()->whereKey($item->getKey())->first() ?? $item) : null;

            // A replay never overrides staff corrections, even if paidThrough could move forward.
            if ($item?->applied_at !== null) {
                return;
            }

            if ($locked->status === Service::STATUS_CANCELLED) {
                PaymentsLog::warning('Payment for a cancelled service: not revived.', ['service_id' => $locked->id, 'invoice_item_id' => $item?->id]);
                if ($item !== null && $item->applied_at === null) {
                    // A payment, not the cron: the money is the staff's to refund or credit (as for an upgrade, SVC-6).
                    DB::afterCommit(fn () => NotificationHelper::sendSystemEmailNotification('Payment for a cancelled service: needs staff attention', e(
                        "Invoice #{$item->invoice_id} paid a renewal of service #{$locked->id}, which is cancelled: it was not revived. Refund the payment or move it to credit."
                    )));
                }

                return;
            }

            if ($locked->plan->type == 'one-time' || $locked->plan->type == 'free') {
                // Nothing to renew: a payment only starts a pending one (never unsuspends one).
                if ($locked->status === Service::STATUS_PENDING) {
                    $this->activate($locked, null);
                    $service->setRawAttributes($locked->getAttributes(), true);
                }

                return;
            }

            $current = $locked->expires_at ? CarbonImmutable::parse($locked->expires_at)->startOfDay() : null;
            $lines = $locked->livePeriodLines();

            if ($item === null) {
                // Free renewal from the cron: one period from what is paid.
                $start = $locked->paidThrough($current ?? CarbonImmutable::today(), lines: $lines);
                $end = $locked->periodEnd($start, $lines);
                $locked->rememberBillingDay($start);
                $this->activate($locked, $end === null ? null : $locked->paidThrough($end, lines: $lines));
                $service->setRawAttributes($locked->getAttributes(), true);

                return;
            }

            if ($item->period_start === null || $item->period_end === null) {
                // The first whole period no live invoice covers yet, so two payments always buy two periods,
                // in whatever order they arrive.
                $start = match (true) {
                    $current === null => CarbonImmutable::today(),
                    // The order's own invoice, after staff already started the service by hand: its
                    // period is the first one (from the order), not one more after it.
                    $this->isFirstOrderLine($locked, $item, $lines, $current) => CarbonImmutable::parse($locked->created_at ?? $current)->startOfDay(),
                    default => $locked->uncoveredPeriodStart($lines),
                };
                $end = $locked->periodEnd($start, $lines);
                $fill = ['period_start' => $start, 'period_end' => $end];
                if ($locked->status === Service::STATUS_PENDING) {
                    // First order: the line text gets the period that actually starts today.
                    $fill['description'] = $locked->describePeriod($start, $end);
                }
                $item->forceFill($fill)->save();
                $lines = $locked->livePeriodLines();
            }

            $paidUntil = $locked->paidThrough($current ?? $item->period_start, lines: $lines);
            $locked->rememberBillingDay($item->period_start);
            if ($locked->coupon_id && $locked->coupon_started_at === null && $locked->status === Service::STATUS_PENDING) {
                $locked->coupon_started_at = $item->period_start;
            }

            $item->forceFill(['applied_at' => now()])->save();

            if ($current !== null && !$paidUntil->greaterThan($current) && $locked->status !== Service::STATUS_PENDING
                && ($item->period_start->greaterThan($current) || $this->paidByAnotherLine($item, $lines))) {
                return; // a replay, a duplicate, or ahead of an unpaid period: nothing new is paid
            }

            $this->activate($locked, $paidUntil); // never earlier than $current: the chain starts there
            $service->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function activate(Service $service, ?CarbonImmutable $paidUntil): void
    {
        $manualHold = $service->status === Service::STATUS_SUSPENDED && $service->dunning_suspended_at === null;
        if (!$manualHold && $service->product->server) {
            // Dispatched after commit: the worker sees the renewed service, and nothing runs on a rollback.
            if ($service->status == Service::STATUS_SUSPENDED) {
                UnsuspendJob::dispatch($service)->afterCommit();
            } elseif ($service->status == Service::STATUS_PENDING) {
                CreateJob::dispatch($service)->afterCommit();
            }
        }

        $service->expires_at = $paidUntil?->toMutable();
        if (!$manualHold) {
            $service->status = Service::STATUS_ACTIVE;
        }
        ServiceObserver::$renewing = true;
        try {
            $service->save();
        } finally {
            ServiceObserver::$renewing = false;
        }
    }

    /**
     * The order's own line on a service staff started by hand: the service's first invoice line, never
     * billed by period, and staff already gave it (at least) its whole first period from the order.
     *
     * @param  Collection<int, InvoiceItem>  $lines
     */
    private function isFirstOrderLine(Service $service, InvoiceItem $item, Collection $lines, CarbonImmutable $current): bool
    {
        if ($lines->isNotEmpty() || $service->created_at === null
            || (int) $service->invoiceItems()->min('invoice_id') !== (int) $item->invoice_id) {
            return false;
        }
        $firstEnd = $service->periodEnd(CarbonImmutable::parse($service->created_at)->startOfDay(), $lines);

        return $firstEnd !== null && $current->greaterThanOrEqualTo($firstEnd);
    }

    /** @param  Collection<int, InvoiceItem>  $lines */
    private function paidByAnotherLine(InvoiceItem $item, Collection $lines): bool
    {
        return $lines->contains(fn (InvoiceItem $line) => $line->id !== $item->id
            && $line->invoice?->status === Invoice::STATUS_PAID
            && $line->period_start->lessThanOrEqualTo($item->period_start)
            && $line->period_end->greaterThanOrEqualTo($item->period_end));
    }
}
