<?php

namespace App\Services\ServiceUpgrade;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What a downgrade gives back as credit, with tax (adversarial P-1 and #326): from what was paid for the days left, never
 * the renewal price that was not paid (a first-cycle coupon, an offer), never the same money twice.
 */
final class DowngradeCredit
{
    /**
     * The credit a downgrade gives on $today, with tax, at most `$difference` (its renewal prices' difference for the days
     * left): what is left of the period's paid money less what the days left cost on the new plan (`$kept`: the customer
     * keeps them, G1 of #326), plus what is left of the upgrades paid within it, less what earlier downgrades gave back.
     * The days left are charged against the period's money only (H2): an upgrade undone at once gives back what it cost.
     */
    public static function credit(Service $service, CarbonImmutable $today, BigDecimal $difference, BigDecimal $kept): BigDecimal
    {
        [$period, $upgrades, $given] = self::parts($service, $today->startOfDay());
        $owed = BigDecimal::max($period->minus($kept), BigDecimal::zero())->plus($upgrades)->minus($given);

        return BigDecimal::min($difference, BigDecimal::max($owed, BigDecimal::zero()))->toScale(2);
    }

    /**
     * What is left after $today of: the paid period lines (one paid ahead whole; what each paid for its days, PaidForPeriod:
     * not a setup fee, not what its invoice gave back), the upgrades paid within them, and
     * the credits earlier downgrades gave for them.
     *
     * @return array{BigDecimal, BigDecimal, BigDecimal}
     */
    private static function parts(Service $service, CarbonImmutable $today): array
    {
        $lines = $service->livePeriodLines()->filter(fn (InvoiceItem $line) => $line->invoice?->status === Invoice::STATUS_PAID
            && $line->period_end->startOfDay()->greaterThan($today))->values();
        if ($lines->isEmpty()) {
            // No paid period at all: one bought with a coupon still running paid nothing for it (F1); one imported
            // without its lines (a used-up promotion included: G3) is priced as it renews, as a cycle change prices it.
            $period = $service->coupon_id !== null && $service->coupon_expired_at === null
                ? BigDecimal::zero() : BigDecimal::of(ChangeCycle::unused($service, $today));
        } else {
            $period = BigDecimal::zero();
            foreach ($lines as $line) {
                $period = $period->plus(self::left(PaidForPeriod::of($line), $line->period_start, $line->period_end, $today));
            }
        }

        [$upgrades, $given] = self::withinPeriod($service, $today);

        return [$period, $upgrades, $given];
    }

    /**
     * What is left after $today of the upgrades paid within the service's period, and of what earlier downgrades gave back
     * for it: also counted by a cycle change «now» (sweep UPGRADES-1), which credits the days left as they were paid. Each
     * upgrade's money is spread to the end of the period it was made in (any live period line holding its moment, paid or
     * not), or on to the expiry it was applied at, a renewal paid ahead included (paidEnd, sweep 2); one of an earlier
     * period was spent there and counts nothing (UPGRADES-2). Each line counts what its invoice kept (PaidForPeriod). A service with no line holding it
     * (imported without its lines) counts one made in its current period to its expiry, as before.
     *
     * @return array{BigDecimal, BigDecimal}
     */
    public static function withinPeriod(Service $service, CarbonImmutable $today): array
    {
        $today = $today->startOfDay();
        $periods = $service->livePeriodLines();
        $currentStart = false; // read once, when an upgrade falls in no line
        $upgrades = BigDecimal::zero();
        $given = BigDecimal::zero();
        foreach (ServiceUpgrade::query()->where('service_id', $service->id)->where('status', ServiceUpgrade::STATUS_COMPLETED)->orderBy('id')->get() as $upgrade) {
            $at = CarbonImmutable::instance($upgrade->created_at);
            $line = self::lineAt($periods, $at);
            if ($line !== null && self::billedAgainAfter($line, $at)) {
                continue; // a cycle change «now» after it counted it in its credit and billed its days again (REV-UPG-1)
            }
            $end = $line === null ? null : self::paidEnd($upgrade, $periods, $line, $at);
            if ($end === null && $service->expires_at !== null) {
                if ($currentStart === false) {
                    $currentStart = $service->currentPeriodStart();
                }
                $end = $currentStart === null || $at->greaterThanOrEqualTo($currentStart) ? CarbonImmutable::instance($service->expires_at) : null;
            }
            if ($end === null || !$end->startOfDay()->greaterThan($today)) {
                continue; // an earlier period's: its money and any credit for it were for days gone
            }
            // What it paid (its lines name the upgrade, not a period: F4), each for its own days: its invoice's from its
            // moment to the end of the days its charge was priced for, a shortfall's (a renewal paid meanwhile) from
            // there on (review REV2-PC-2: an unpaid shortfall's days were never paid, the charge's money never spread
            // over them).
            $lines = InvoiceItem::query()->where('reference_type', ServiceUpgrade::class)->where('reference_id', $upgrade->id)->get();
            $chargeEnd = self::chargeEnd($upgrade, $lines, $line, $end);
            foreach ($lines as $paid) {
                if ($paid->invoice?->status !== Invoice::STATUS_PAID) {
                    continue;
                }
                $upgrades = (int) $paid->invoice_id === (int) $upgrade->invoice_id
                    ? $upgrades->plus(self::left(PaidForPeriod::of($paid), $upgrade->created_at, $chargeEnd, $today))
                    : $upgrades->plus(self::left(PaidForPeriod::of($paid), $chargeEnd, $end, $today));
            }
            // What an earlier downgrade gave back for these days (F2).
            if ($upgrade->credit_cents !== null && $upgrade->credit_until !== null) {
                $given = $given->plus(self::left((string) BigDecimal::of($upgrade->credit_cents)->dividedBy(100, 2),
                    $upgrade->created_at, $upgrade->credit_until, $today));
            }
        }

        return [$upgrades, $given];
    }

    /** What is left after $today of $amount, spread over [$start, $end): all of it when the period starts later. */
    private static function left(string $amount, CarbonInterface $start, CarbonInterface $end, CarbonImmutable $today): BigDecimal
    {
        $start = CarbonImmutable::instance($start)->startOfDay();
        $end = CarbonImmutable::instance($end)->startOfDay();
        $days = (int) $start->diffInDays($end);
        if ($days < 1 || !$end->greaterThan($today)) {
            return BigDecimal::zero();
        }
        $left = min($days, (int) $today->max($start)->diffInDays($end));

        return BigDecimal::of($amount)->multipliedBy($left)->dividedBy($days, 2, RoundingMode::HalfUp);
    }

    /**
     * The end of the days an upgrade's money paid for: the end of the period line it was made in, or, when it was applied
     * later than that (`paid_until`, its expiry then: a renewal paid ahead, sweep 2 F-PLAN-CHANGE-2), that day, over the
     * live lines that follow on from it. One billed again after it (a cycle change «now») ends them: those days were
     * credited and billed again (REV-UPG-1).
     *
     * @param  Collection<int, InvoiceItem>  $periods
     */
    private static function paidEnd(ServiceUpgrade $upgrade, Collection $periods, InvoiceItem $line, CarbonImmutable $at): CarbonImmutable
    {
        $end = CarbonImmutable::instance($line->period_end)->startOfDay();
        if ($upgrade->paid_until === null) {
            return $end;
        }
        $until = CarbonImmutable::instance($upgrade->paid_until)->startOfDay();
        while ($end->lessThan($until)) {
            $next = $periods->first(fn (InvoiceItem $other) => $other->period_start->startOfDay()->equalTo($end) && !self::billedAgainAfter($other, $at));
            if ($next === null) {
                break;
            }
            $end = CarbonImmutable::instance($next->period_end)->startOfDay()->min($until);
        }

        return $end;
    }

    /**
     * The end of the days the upgrade's own charge paid for, at most $end: the expiry it was priced to (`charged_until`).
     * One from before that column with a shortfall billed (on another invoice) was priced to the end of the period line
     * it was made in, the renewal paid meanwhile being the shortfall's; without one, to $end, as before.
     *
     * @param  Collection<int, InvoiceItem>  $lines  the upgrade's lines
     */
    private static function chargeEnd(ServiceUpgrade $upgrade, Collection $lines, ?InvoiceItem $at, CarbonImmutable $end): CarbonImmutable
    {
        $end = $end->startOfDay();
        if ($upgrade->charged_until !== null) {
            return CarbonImmutable::instance($upgrade->charged_until)->startOfDay()->min($end);
        }
        $shortfall = $lines->contains(fn (InvoiceItem $line) => (int) $line->invoice_id !== (int) $upgrade->invoice_id);
        if (!$shortfall || $at === null) {
            return $end;
        }

        return CarbonImmutable::instance($at->period_end)->startOfDay()->min($end);
    }

    /** @param  Collection<int, InvoiceItem>  $lines */
    private static function lineAt(Collection $lines, CarbonImmutable $at): ?InvoiceItem
    {
        return $lines->first(fn (InvoiceItem $line) => !$line->period_start->greaterThan($at) && $line->period_end->greaterThan($at));
    }

    /**
     * Whether $line was billed after the moment $at it holds, from the day it was billed: what a cycle change «now» bills
     * (its period starts on its day; a renewal is billed ahead of its period). That change credited the upgrade's days
     * left, or took a downgrade's credit for them off, already (sweep UPGRADES-1): counting it again in the new line
     * would give it twice, or take it twice (REV-UPG-1).
     */
    private static function billedAgainAfter(InvoiceItem $line, CarbonImmutable $at): bool
    {
        return $line->created_at !== null && $line->created_at->greaterThan($at)
            && $line->period_start->startOfDay()->equalTo($line->created_at->copy()->startOfDay());
    }
}
