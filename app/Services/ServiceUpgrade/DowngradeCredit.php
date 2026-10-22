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
 * The most a downgrade may give back as credit, with tax (adversarial P-1 and #326): what is left, after today, of what
 * was paid for the service (its paid period lines, one paid ahead whole, and the upgrades paid within them), less what
 * earlier downgrades already gave back for those days. Never the renewal price that was not paid (a first-cycle coupon,
 * an offer), never the same money twice.
 */
final class DowngradeCredit
{
    public static function cap(Service $service, CarbonImmutable $today): BigDecimal
    {
        $today = $today->startOfDay();
        $lines = $service->livePeriodLines()->filter(fn (InvoiceItem $line) => $line->invoice?->status === Invoice::STATUS_PAID
            && $line->period_end->startOfDay()->greaterThan($today))->values();
        if ($lines->isEmpty()) {
            // No paid period at all: one bought with a coupon still running gave nothing to give back (F1); one imported
            // without its lines (a used-up promotion included: G3) is priced as it renews, as a cycle change prices it.
            return $service->coupon_id !== null && $service->coupon_expired_at === null
                ? BigDecimal::zero() : BigDecimal::of(ChangeCycle::unused($service, $today));
        }

        $paid = BigDecimal::zero();
        foreach ($lines as $line) {
            $paid = $paid->plus(self::left((string) $line->line_total, $line->period_start, $line->period_end, $today));
        }
        // What upgrades paid within those periods (their lines name the upgrade, not a period: F4).
        $upgrades = ServiceUpgrade::query()->where('service_id', $service->id)->where('status', ServiceUpgrade::STATUS_COMPLETED)->get();
        foreach ($upgrades as $upgrade) {
            $end = self::endOfPeriodAt($lines, CarbonImmutable::instance($upgrade->created_at)) ?? $service->expires_at;
            if ($end === null) {
                continue;
            }
            $lineTotals = InvoiceItem::query()->where('reference_type', ServiceUpgrade::class)->where('reference_id', $upgrade->id)
                ->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_PAID))->get();
            foreach ($lineTotals as $line) {
                $paid = $paid->plus(self::left((string) $line->line_total, $upgrade->created_at, $end, $today));
            }
            // And what earlier downgrades gave back for these days (F2).
            if ($upgrade->credit_cents !== null && $upgrade->credit_until !== null) {
                $paid = $paid->minus(self::left(BigDecimal::of($upgrade->credit_cents)->dividedBy(100, 2)->__toString(),
                    $upgrade->created_at, $upgrade->credit_until, $today));
            }
        }

        return BigDecimal::max($paid, BigDecimal::zero())->toScale(2);
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
