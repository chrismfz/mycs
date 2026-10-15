<?php

namespace App\Services\Service;

use App\Exceptions\DisplayException;
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
use Throwable;

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
 * payment arrived). An ended service (cancelled or terminated) is never revived by a payment:
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

            if ($locked->isEnded()) {
                PaymentsLog::warning('Payment for an ended service: not revived.', ['service_id' => $locked->id, 'invoice_item_id' => $item?->id]);
                // A debt kept when it ended (Δ3a) is settled by its payment: nothing for staff to do.
                if ($item !== null && $item->applied_at === null && $item->invoice?->kept_as_debt_at === null) {
                    // A payment, not the cron: the money is the staff's to refund or credit (as for an upgrade, SVC-6).
                    DB::afterCommit(fn () => NotificationHelper::sendSystemEmailNotification('Payment for an ended service: needs staff attention', e(
                        "Invoice #{$item->invoice_id} paid a renewal of service #{$locked->id}, which is {$locked->status}: it was not revived. Refund the payment or move it to credit."
                    )));
                }

                return;
            }

            if ($locked->plan === null) {
                // Nothing to price a period with (a plan deleted before BIL-6, an import with none): staff set one; the
                // invoice stays to be applied (BIL-9) and the cron tells them.
                throw new DisplayException("Service #{$locked->id} has no plan: its payment cannot be applied (set its plan).");
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
        // Suspended for non-payment and still overdue after this payment (another period unpaid): it stays suspended, else
        // the next cron run would suspend it again (Δ3a). Staff are told; they unsuspend it by hand if they wish.
        $stillOverdue = !$manualHold && $service->status === Service::STATUS_SUSPENDED && $paidUntil !== null && $service->stillOverdue($paidUntil);
        if ($stillOverdue) {
            $id = $service->id;
            $until = $paidUntil->toDateString();
            DB::afterCommit(fn () => NotificationHelper::sendSystemEmailNotification('Payment applied, service still overdue: needs staff attention', e(
                "A payment renewed service #{$id} until {$until}, which is still past Suspend Days: it stays suspended until the rest is paid (unsuspend it by hand to give it time)."
            )));
            $manualHold = true; // kept as it is, as a hold would be
        }
        // Its product is set up by staff (Auto Setup «on accept» or «never», Σ1-b): paid, it waits for them, pending.
        $waitsForStaff = $service->status === Service::STATUS_PENDING && $service->product !== null && !$service->product->setsUpOnPayment();
        if (!$manualHold && $service->status === Service::STATUS_SUSPENDED && !$service->provisioningServer()) {
            // No server to unsuspend (UnsuspendJob mails it otherwise): the customer told it was suspended is told it
            // is active again. After commit; a failing mail never undoes the renewal.
            $id = $service->id;
            DB::afterCommit(function () use ($id) {
                try {
                    $renewed = Service::query()->find($id);
                    if ($renewed?->client !== null) {
                        NotificationHelper::serverUnsuspendedNotification($renewed->client, $renewed);
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }
        if (!$manualHold && $service->provisioningServer()) {
            // Dispatched after commit: the worker sees the renewed service, and nothing runs on a rollback.
            if ($service->status == Service::STATUS_SUSPENDED) {
                UnsuspendJob::dispatch($service)->afterCommit();
            } elseif ($service->status == Service::STATUS_PENDING && !$waitsForStaff) {
                CreateJob::dispatch($service)->afterCommit();
            }
        }

        $service->expires_at = $paidUntil?->toMutable();
        // A pending one with a server turns active once its account is made (CreateJob, Σ1): paid now, it may still
        // fail on the server and wait in Pending Module Actions. Without a server there is nothing to make.
        $awaitsCreate = $service->status === Service::STATUS_PENDING && $service->provisioningServer() !== null;
        if (!$manualHold && !$awaitsCreate && !$waitsForStaff) {
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
