<?php

namespace MyCS\BillingPeriod\Tests;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use MyCS\BillingPeriod\Prorata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Checked against nixpal's WHMCS (2025–26 orders on day-1 products, «charge next month» after the 20th). */
final class ProrataTest extends TestCase
{
    /** @return array<string, array{string, int, ?int, string, string}> start, day, next-after, end, price of 30.00 a month */
    public static function months(): array
    {
        return [
            'on the day: a full month' => ['2026-01-01', 1, 20, '2026-02-01', '30.00'],
            'the 10th of a 31-day month: 22 of 31 days' => ['2026-01-10', 1, 20, '2026-02-01', '21.29'],
            'the 19th of a 30-day month: 12 of 30 days' => ['2026-04-19', 1, 20, '2026-05-01', '12.00'],
            'the 20th: 11 days and the next month' => ['2026-04-20', 1, 20, '2026-06-01', '41.00'],
            'the 25th of a 30-day month' => ['2026-06-25', 1, 20, '2026-08-01', '36.00'],
            'no charge-next-month day' => ['2026-04-25', 1, null, '2026-05-01', '6.00'],
            'a day later in the same month' => ['2026-03-02', 5, 20, '2026-03-05', '2.90'],
            'past the day: next month' => ['2026-03-10', 5, 20, '2026-04-05', '25.16'],
            'february' => ['2026-02-15', 1, 20, '2026-03-01', '15.00'],
        ];
    }

    #[DataProvider('months')]
    public function test_monthly(string $start, int $day, ?int $nextAfter, string $end, string $price): void
    {
        $start = CarbonImmutable::parse($start);
        $this->assertSame($end, Prorata::firstPeriod($start, 'month', 1, $day, $nextAfter)['end']->toDateString());
        $this->assertSame($price, Prorata::firstPrice('30.00', $start, 'month', 1, $day, $nextAfter));
    }

    public function test_a_year_is_aligned_to_the_day_and_priced_on_the_year(): void
    {
        $start = CarbonImmutable::parse('2026-01-10');
        $period = Prorata::firstPeriod($start, 'year', 1, 1, 20);
        $this->assertSame('2026-02-01', $period['end']->toDateString());
        $this->assertSame('22.00', Prorata::firstPrice('365.00', $start, 'year', 1, 1, 20)); // 22 of 365 days

        $late = Prorata::firstPeriod(CarbonImmutable::parse('2026-01-25'), 'year', 1, 1, 20);
        $this->assertSame(['2026-02-01', '2027-02-01'], [$late['aligned']->toDateString(), $late['end']->toDateString()]);
    }

    public function test_time_of_day_is_dropped(): void
    {
        $this->assertSame('2026-02-01', Prorata::firstPeriod(CarbonImmutable::parse('2026-01-10 23:59'), 'month', 1, 1)['end']->toDateString());
    }

    public function test_refused(): void
    {
        foreach ([['day', 1, 1], ['week', 1, 1], ['month', 0, 1], ['month', 1, 0], ['month', 1, 29]] as [$unit, $count, $day]) {
            try {
                Prorata::firstPeriod(CarbonImmutable::parse('2026-01-10'), $unit, $count, $day);
                $this->fail("{$unit} {$count} {$day} was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
