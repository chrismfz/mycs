<?php

namespace MyCS\BillingPeriod;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A product's pro rata billing (WHMCS «Prorata Billing», MyCS docs/billing-cursor.md): every service of the product
 * renews on the same day of the month. The first period runs from the order to the next such day, priced by its days;
 * ordered on or after the «charge next month» day, it also takes the full period after it, so the first renewal is not
 * days away. Month and year cycles only (a day or week cycle has no day of the month to align to).
 */
final class Prorata
{
    /** The highest day a product can align to: one every month has. */
    public const MAX_DAY = 28;

    /**
     * The first period: aligned is the first renewal day after the start, end where the first period ends (the aligned day,
     * or a full period after it when the next one is billed too).
     *
     * @param  int  $day  the day of the month its services renew on (1–28)
     * @param  int|null  $nextAfterDay  ordered on or after this day of the month, the next full period is billed too
     * @return array{aligned: CarbonImmutable, end: CarbonImmutable, partial: bool, extended: bool}
     */
    public static function firstPeriod(CarbonInterface $start, string $unit, int $count, int $day, ?int $nextAfterDay = null): array
    {
        self::check($unit, $count, $day);
        $start = CarbonImmutable::instance($start)->startOfDay();
        if ($start->day === $day) {
            $end = BillingPeriod::end($start, $unit, $count);

            return ['aligned' => $end, 'end' => $end, 'partial' => false, 'extended' => false];
        }

        $aligned = $start->day < $day ? $start->setDay($day) : $start->addMonthNoOverflow()->setDay($day);
        $extended = $nextAfterDay !== null && $start->day >= $nextAfterDay;

        return [
            'aligned' => $aligned,
            'end' => $extended ? BillingPeriod::end($aligned, $unit, $count) : $aligned,
            'partial' => true,
            'extended' => $extended,
        ];
    }

    /**
     * The first period's price for a recurring $price: the days to the aligned day as a share of a full period from the
     * start, plus the full period after it when that one is billed too. Decimal, rounded half-up to cents once per part.
     */
    public static function firstPrice(string $price, CarbonInterface $start, string $unit, int $count, int $day, ?int $nextAfterDay = null): string
    {
        $period = self::firstPeriod($start, $unit, $count, $day, $nextAfterDay);
        if (!$period['partial']) {
            return (string) BigDecimal::of($price)->toScale(2, RoundingMode::HalfUp);
        }
        $stub = BigDecimal::of(BillingPeriod::prorate($price, $start, $period['aligned'], BillingPeriod::end($start, $unit, $count)));

        return (string) ($period['extended'] ? $stub->plus(BigDecimal::of($price)->toScale(2, RoundingMode::HalfUp)) : $stub);
    }

    /**
     * What is left on $today of a first period from $start to $end (an upgrade's difference, SVC-14), priced the way the
     * period was: the days to the aligned day as a share of a full period from the start, the rest as the full period it
     * is. A period that starts on the day is an ordinary one.
     */
    public static function remaining(string $amount, CarbonInterface $start, CarbonInterface $end, CarbonInterface $today, string $unit, int $count, int $day): string
    {
        self::check($unit, $count, $day);
        $start = CarbonImmutable::instance($start)->startOfDay();
        $end = CarbonImmutable::instance($end)->startOfDay();
        $today = CarbonImmutable::instance($today)->startOfDay();
        if ($start->day === $day) {
            return BillingPeriod::remaining($amount, $start, $end, $today);
        }
        $aligned = CarbonImmutable::instance(BillingPeriod::end($start, $unit, $count))->min(self::firstPeriod($start, $unit, $count, $day)['aligned'])->min($end);

        $from = $today->max($start);
        $stubDays = $from->lessThan($aligned) ? (int) $from->diffInDays($aligned) : 0;
        $fullDays = (int) $start->diffInDays(BillingPeriod::end($start, $unit, $count));
        $stub = BigDecimal::of($amount)->multipliedBy($stubDays)->dividedBy($fullDays, 2, RoundingMode::HalfUp);
        $rest = $aligned->lessThan($end) ? BigDecimal::of(BillingPeriod::remaining($amount, $aligned, $end, $today->max($aligned))) : BigDecimal::zero();

        return (string) $stub->plus($rest);
    }

    private static function check(string $unit, int $count, int $day): void
    {
        if (!in_array($unit, ['month', 'year'], true)) {
            throw new InvalidArgumentException("Pro rata billing aligns month and year cycles only, not «{$unit}».");
        }
        if ($count < 1) {
            throw new InvalidArgumentException("A billing period must be at least 1 {$unit}, got {$count}.");
        }
        if ($day < 1 || $day > self::MAX_DAY) {
            throw new InvalidArgumentException('The pro rata day is a day every month has (1–' . self::MAX_DAY . "), got {$day}.");
        }
    }
}
