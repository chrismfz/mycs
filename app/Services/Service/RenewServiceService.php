<?php

namespace App\Services\Service;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Jobs\Server\CreateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Service;
use App\Observers\ServiceObserver;
use App\Services\Money\PaymentsLog;
use App\Support\AfterCommit;
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
                // A server job the commit that applied it decided and the queue never took (predeploy S4): its marker
                // brought this replay, and the row its failed push wrote asks for it again.
                ModuleAction::askAgainNotQueued($locked);
                $this->paidAgain($locked, $item);
                $service->setRawAttributes($locked->getAttributes(), true);

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
                $this->activate($locked, $end === null ? null : $locked->paidThrough($end, lines: $lines), $start);
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
            // An addon keeps its parent's day, not its line's: started prorated to its parent's date (code review 1 of #637), or
            // its cycle changed «now» to a period ending on it (ChangeCycle, owner 2026-11-08).
            if (!($locked->parent_service_id !== null && $locked->billing_day !== null)) {
                $locked->rememberBillingDay($item->period_start);
            }
            if ($locked->coupon_id && $locked->coupon_started_at === null && $locked->status === Service::STATUS_PENDING) {
                $locked->coupon_started_at = $item->period_start;
            }

            $item->forceFill(['applied_at' => now()])->save();

            if ($current !== null && !$paidUntil->greaterThan($current) && $locked->status !== Service::STATUS_PENDING
                && ($item->period_start->greaterThan($current) || $this->paidByAnotherLine($item, $lines))) {
                return; // a replay, a duplicate, or ahead of an unpaid period: nothing new is paid
            }

            $this->activate($locked, $paidUntil, $item->period_start ? CarbonImmutable::parse($item->period_start) : null); // never earlier than $current: the chain starts there
            $service->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * A line applied before, on an invoice paid again since (set back to unpaid, or charged back, then paid; the dispute
     * won): not a replay. Its period was renewed then and is not renewed again, but a service suspended for non-payment
     * meanwhile is treated as on any payment: back when nothing else is overdue, else kept suspended and staff told; and
     * the line counts as paid since the suspension, so the cron bills its next period (sweep3 L-SERVICE-STATE-1: else
     * never billed, and terminated with nothing open to pay). A replay of the same payment (the cron's, BIL-9) applied
     * the line after the invoice became paid, and changes nothing.
     */
    private function paidAgain(Service $service, InvoiceItem $item): void
    {
        $paidSince = Invoice::query()->whereKey($item->invoice_id)->where('status', Invoice::STATUS_PAID)->value('apply_pending_since');
        if ($paidSince === null || !$item->applied_at->lessThan(CarbonImmutable::parse($paidSince))) {
            return;
        }
        if ($service->isEnded() || $service->status !== Service::STATUS_SUSPENDED || $service->dunning_suspended_at === null
            || $service->plan === null || in_array($service->plan->type, ['one-time', 'free'], true) || $service->expires_at === null) {
            return;
        }
        $item->forceFill(['applied_at' => now()])->save();
        $this->activate($service, CarbonImmutable::parse($service->expires_at)->startOfDay());
    }

    private function activate(Service $service, ?CarbonImmutable $paidUntil, ?CarbonImmutable $periodStart = null): void
    {
        // A renewal of one already running: a module that renews by hand gets a task (OfflineSsl). Only when this payment
        // moves its paid date on, for a period that starts after the service did: the period it started with, paid after
        // staff started it (by hand, accepted unpaid, set up on order), is its order, never a renewal (R2-1, R3-1 of #532).
        $started = $service->activated_at ?? $service->created_at;
        $renewal = $service->status !== Service::STATUS_PENDING && $paidUntil !== null
            && ($service->expires_at === null || $paidUntil->greaterThan($service->expires_at))
            && ($periodStart === null || $started === null || $periodStart->startOfDay()->greaterThan(CarbonImmutable::parse($started)->startOfDay()));
        $manualHold = $service->status === Service::STATUS_SUSPENDED && $service->dunning_suspended_at === null;
        // Suspended for non-payment and still overdue after this payment (another period unpaid): it stays suspended, else
        // the next cron run would suspend it again (Δ3a). Staff are told; they unsuspend it by hand if they wish.
        $stillOverdue = !$manualHold && $service->status === Service::STATUS_SUSPENDED && $paidUntil !== null && $service->stillOverdue($paidUntil);
        if ($stillOverdue) {
            $id = $service->id;
            $until = $paidUntil->toDateString();
            DB::afterCommit(fn () => NotificationHelper::sendSystemEmailNotification('Payment applied, service still overdue: needs staff attention', e(
                "A payment renewed service #{$id} until {$until}, which is still past Suspend Days: it stays suspended until the rest is paid (to give it time: set «Do Not Suspend Until» and unsuspend it; an unsuspension alone is undone by the next run)."
            )));
            $manualHold = true; // kept as it is, as a hold would be
        }
        // An addon paid while its parent is suspended stays suspended, from now on with it: back when its parent is
        // (AddonCascade). Not one staff hold: their hold stays theirs.
        if (!$manualHold && $service->status === Service::STATUS_SUSPENDED && $service->parent_service_id !== null
            && Service::query()->whereKey($service->parent_service_id)->value('status') === Service::STATUS_SUSPENDED) {
            $manualHold = true;
            $service->suspend_reason = Service::SUSPEND_REASON_PARENT;
            $service->dunning_suspended_at = null;
            $addonId = $service->id;
            DB::afterCommit(fn () => AddonCascade::settle($addonId)); // its parent read without a lock: read again (code review 6)
        }
        // Its product is set up by staff (Auto Setup «on accept» or «never», Σ1-b): paid, it waits for them, pending.
        // An addon never waits for an Accept: its setup is staff's task anyway, and a later start would move its date off its
        // parent's (code review of #637). Nor one its order's Accept left waiting for this payment (a one-time plan not
        // paid: AcceptOrder::waitsForPayment, sweep L-SERVICE-STATE-2): staff looked already, and no second Accept comes.
        // It starts as staff chose on that Accept: the module create only if they ran it, their welcome email (REV3-LSS-1).
        $acceptedWaitingForPayment = $service->status === Service::STATUS_PENDING && $service->accepted_run_create !== null;
        $acceptedByHand = $acceptedWaitingForPayment && (!$service->accepted_run_create || !$service->hasServerActions());
        $acceptedSendEmail = (bool) ($service->accepted_send_email ?? true);
        $waitsForStaff = $service->status === Service::STATUS_PENDING && $service->product !== null && !$service->product->setsUpOnPayment()
            && $service->parent_service_id === null && !$acceptedWaitingForPayment;
        // Suspended for non-payment, now paid: the unsuspension goes through the queue also without a server (its job
        // only mails then), behind the cron's suspension still queued, so a suspension the worker never did is told
        // nothing either (combined review of #411 and #418). With this transaction: nothing runs on a rollback, and the
        // worker sees the renewed service (AfterCommit::job, predeploy S4).
        if (!$manualHold && $service->status === Service::STATUS_SUSPENDED) {
            AfterCommit::job(new UnsuspendJob($service));
        }
        if (!$manualHold && $service->hasServerActions()) {
            if ($service->status == Service::STATUS_PENDING && !$waitsForStaff && $service->isAddon()) {
                // An addon's creation is a task for staff on its parent's machine, no module (ADV-634-6): made once this
                // commits, the addon started now (suspended with a suspended parent: AddonCascade).
                $id = $service->id;
                DB::afterCommit(function () use ($id) {
                    try {
                        ($addon = Service::query()->find($id)) !== null ? ExtensionHelper::createServer($addon) : null;
                    } catch (Throwable $e) {
                        report($e);
                    }
                });
            } elseif ($service->status == Service::STATUS_PENDING && !$waitsForStaff && $acceptedWaitingForPayment) {
                if (!$acceptedByHand) {
                    AfterCommit::job(new CreateJob($service, $acceptedSendEmail, byStaff: true));
                }
            } elseif ($service->status == Service::STATUS_PENDING && !$waitsForStaff) {
                AfterCommit::job(new CreateJob($service));
            }
        }

        $service->expires_at = $paidUntil?->toMutable();
        // A pro rata product's first period ends on its day (PR D): paid after it, the service starts already expired, and
        // gets Suspend Days from today rather than being suspended the day it starts (adversarial D-ADV-4).
        // An addon's prorated first period too (code review 3 of #637).
        if ($service->status === Service::STATUS_PENDING && $paidUntil !== null && $paidUntil->lessThan(CarbonImmutable::today())
            && ($service->product?->proratesPlan($service->plan) || $service->parent_service_id !== null)) {
            $hold = CarbonImmutable::today()->addDays(max(0, (int) config('settings.cronjob_order_suspend', 8)));
            if ($service->suspend_hold_until === null || $hold->greaterThan($service->suspend_hold_until)) {
                $service->suspend_hold_until = $hold->toMutable();
            }
        }
        // A pending one with a server turns active once its account is made (CreateJob, Σ1): paid now, it may still
        // fail on the server and wait in Pending Module Actions. Without a server there is nothing to make.
        $awaitsCreate = $service->status === Service::STATUS_PENDING && $service->hasServerActions() && !$service->isAddon()
            && !$acceptedByHand;
        if (!$manualHold && !$awaitsCreate && !$waitsForStaff) {
            if ($acceptedByHand && $acceptedSendEmail) {
                // Built by hand on that Accept: staff's «Send welcome email», as AcceptOrder sends it (review R5).
                $id = $service->id;
                DB::afterCommit(fn () => ($started = Service::query()->find($id)) && $started->client
                    ? NotificationHelper::serverCreatedNotification($started->client, $started, byStaff: true) : null);
            }
            $service->status = Service::STATUS_ACTIVE;
        }
        if ($acceptedWaitingForPayment) {
            $service->accepted_run_create = null; // its Accept's choices used: a later payment is a plain one
            $service->accepted_send_email = null;
        }
        ServiceObserver::$renewing = true;
        try {
            $service->save();
        } finally {
            ServiceObserver::$renewing = false;
        }
        if ($renewal) {
            // Still suspended after it (more owed, or staff's hold): the task says so, never a plain «buy it» (R2-2).
            $id = $service->id;
            $until = $paidUntil->toDateString();
            $note = $manualHold ? 'it is still suspended (more owed, or held by staff): check before buying' : null;
            DB::afterCommit(fn () => ($renewed = Service::query()->find($id)) !== null ? ExtensionHelper::renewByHand($renewed, $until, $note) : null);
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
