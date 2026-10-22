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
     * What is left after $today of: the paid period lines (one paid ahead whole), the upgrades paid within them, and
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
                $period = $period->plus(self::left((string) $line->line_total, $line->period_start, $line->period_end, $today));
            }
        }

        $upgrades = BigDecimal::zero();
        $given = BigDecimal::zero();
        foreach (ServiceUpgrade::query()->where('service_id', $service->id)->where('status', ServiceUpgrade::STATUS_COMPLETED)->get() as $upgrade) {
            // What it paid (its lines name the upgrade, not a period: F4), to the end of the period it was made in.
            $end = self::endOfPeriodAt($lines, CarbonImmutable::instance($upgrade->created_at)) ?? $service->expires_at;
            if ($end !== null) {
                $paidLines = InvoiceItem::query()->where('reference_type', ServiceUpgrade::class)->where('reference_id', $upgrade->id)
                    ->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_PAID))->get();
                foreach ($paidLines as $line) {
                    $upgrades = $upgrades->plus(self::left((string) $line->line_total, $upgrade->created_at, $end, $today));
                }
            }
            // What an earlier downgrade gave back for these days (F2).
            if ($upgrade->credit_cents !== null && $upgrade->credit_until !== null) {
                $given = $given->plus(self::left((string) BigDecimal::of($upgrade->credit_cents)->dividedBy(100, 2),
                    $upgrade->created_at, $upgrade->credit_until, $today));
            }
        }

        return [$period, $upgrades, $given];
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

    /** @param  Collection<int, InvoiceItem>  $lines */
    private static function endOfPeriodAt($lines, CarbonImmutable $at): ?CarbonImmutable
    {
        $line = $lines->first(fn (InvoiceItem $line) => !$line->period_start->greaterThan($at) && $line->period_end->greaterThan($at));

        return $line === null ? null : CarbonImmutable::instance($line->period_end);
    }
}
