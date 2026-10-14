<?php

namespace MyCS\BillingPeriod\Tests;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use MyCS\BillingPeriod\BillingPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BillingPeriodTest extends TestCase
{
    /** @return array<string, array{string, string, int, ?int, string}> */
    public static function periods(): array
    {
        return [
            'month from the 31st' => ['2026-01-31', 'month', 1, null, '2026-02-28'],
            'back to the 31st' => ['2026-02-28', 'month', 1, 31, '2026-03-31'],
            'clamped to the 30th' => ['2026-03-31', 'month', 1, 31, '2026-04-30'],
            'clamped 30th is still the anchor' => ['2026-04-30', 'month', 1, 31, '2026-05-31'],
            'leap day yearly' => ['2024-02-29', 'year', 1, null, '2025-02-28'],
            'back to a leap day' => ['2027-02-28', 'year', 1, 29, '2028-02-29'],
            'quarterly' => ['2026-10-31', 'month', 3, null, '2027-01-31'],
            'week' => ['2026-10-12', 'week', 1, null, '2026-10-19'],
            'days' => ['2026-10-12', 'day', 10, null, '2026-10-22'],
            'start not on the anchor uses its own day' => ['2026-03-20', 'month', 1, 1, '2026-04-20'],
            'mid-month start ignores a 31st anchor' => ['2026-02-15', 'month', 1, 31, '2026-03-15'],
            'time of day is dropped' => ['2026-10-12 17:45:00', 'month', 1, null, '2026-11-12'],
        ];
    }

    #[DataProvider('periods')]
    public function test_period_end(string $start, string $unit, int $count, ?int $anchor, string $end): void
    {
        $this->assertSame($end, BillingPeriod::end(CarbonImmutable::parse($start), $unit, $count, $anchor)->toDateString());
    }

    public function test_units_that_are_not_whole_days_are_refused(): void
    {
        foreach (['hour', null, 'fortnight'] as $unit) {
            try {
                BillingPeriod::end(CarbonImmutable::parse('2026-10-12'), $unit, 1);
                $this->fail('Accepted unit ' . var_export($unit, true));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_empty_period_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BillingPeriod::end(CarbonImmutable::parse('2026-10-12'), 'month', 0);
    }

    public function test_ambiguous_days_are_the_last_day_of_short_months(): void
    {
        $this->assertTrue(BillingPeriod::isAmbiguousDay(CarbonImmutable::parse('2027-02-28')));
        $this->assertTrue(BillingPeriod::isAmbiguousDay(CarbonImmutable::parse('2026-11-30')));
        $this->assertFalse(BillingPeriod::isAmbiguousDay(CarbonImmutable::parse('2026-10-31')));
        $this->assertFalse(BillingPeriod::isAmbiguousDay(CarbonImmutable::parse('2026-11-29')));
    }

    public function test_decimal_proration_rounds_once_without_float_conversion(): void
    {
        $start = CarbonImmutable::parse('2026-10-15');
        $end = CarbonImmutable::parse('2026-11-01');
        $full = CarbonImmutable::parse('2026-11-15');
        $this->assertSame('5.48', BillingPeriod::prorate('10.00', $start, $end, $full));
        $this->assertSame('10.97', BillingPeriod::prorate('20.00', $start, $end, $full));
        $this->assertSame('100.00', BillingPeriod::prorate('100.00', $start, $full, $full));
        $this->assertSame('5483870967741935.48', BillingPeriod::prorate('10000000000000000.00', $start, $end, $full));
    }

    public function test_period_start_is_one_cycle_back(): void
    {
        $this->assertSame('2026-02-28', BillingPeriod::start(CarbonImmutable::parse('2026-03-31'), 'month', 1)->toDateString());
        $this->assertSame('2025-11-15', BillingPeriod::start(CarbonImmutable::parse('2026-11-15 13:00'), 'year', 1)->toDateString());
        $this->assertSame('2024-02-28', BillingPeriod::start(CarbonImmutable::parse('2026-02-28'), 'year', 2)->toDateString());
        $this->assertSame('2026-10-01', BillingPeriod::start(CarbonImmutable::parse('2026-10-15'), 'week', 2)->toDateString());
        $this->assertSame('2026-08-15', BillingPeriod::start(CarbonImmutable::parse('2026-11-15'), 'month', 3)->toDateString());
    }

    public function test_the_remaining_part_counts_whole_days_left(): void
    {
        $start = CarbonImmutable::parse('2026-01-01');
        $end = CarbonImmutable::parse('2027-01-01');
        $this->assertSame('55.15', BillingPeriod::remaining('110.00', $start, $end, CarbonImmutable::parse('2026-07-02 18:00')));
        $this->assertSame('-50.41', BillingPeriod::remaining('-100.00', $start, $end, CarbonImmutable::parse('2026-07-01')));
        $this->assertSame('0.00', BillingPeriod::remaining('110.00', $start, $end, CarbonImmutable::parse('2027-02-01')), 'ended: nothing left');
        $this->assertSame('119.34', BillingPeriod::remaining('110.00', $start, $end, CarbonImmutable::parse('2025-12-01')), 'paid ahead: 396 days to its end');
    }

    public function test_invalid_pricing_interval_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $start = CarbonImmutable::parse('2026-10-15');
        BillingPeriod::prorate('10.00', $start, $start, $start->addMonth());
    }
}
