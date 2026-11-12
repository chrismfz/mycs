<?php

namespace MyCS\Pricing\Tests;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MyCS\Pricing\Tiers;
use MyCS\Pricing\UsageCharge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** The examples of docs/zonecloud-bulk-license.md §6.4, and what must hold for any month. */
final class UsageChargeTest extends TestCase
{
    private const TZ = 'Europe/Athens';

    private static function tiers(string $mode = Tiers::GRADUATED): Tiers
    {
        return Tiers::of([['from' => 1, 'price' => '1.80'], ['from' => 51, 'price' => '1.50']], $mode);
    }

    private static function at(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone(self::TZ));
    }

    /** $n units there from $from (midnight) to $to (midnight), or still there. */
    private static function units(int $n, string $from, ?string $to = null): array
    {
        return array_fill(0, $n, [self::at($from), $to === null ? null : self::at($to)]);
    }

    private static function month(array $intervals, string $start, string $end, string $counting = UsageCharge::IP_DAYS, ?Tiers $tiers = null): string
    {
        $counts = UsageCharge::dailyCounts($intervals, self::at($start), self::at($end));

        return UsageCharge::charge($tiers ?? self::tiers(), $counts, count($counts), $counting);
    }

    // --- Tiers -----------------------------------------------------------------------------------------------------

    public function test_graduated_prices_each_unit_at_its_own_step(): void
    {
        $tiers = self::tiers();

        $this->assertSame(['0.00', '18.00', '90.00', '91.50', '105.00', '135.00', '72.00', '201.00'],
            array_map(fn ($n) => (string) $tiers->cost($n)->toScale(2), [0, 10, 50, 51, 60, 80, 40, 124]));
        $this->assertSame([[50, '1.80'], [10, '1.50']],
            array_map(fn ($row) => [$row[0], (string) $row[1]], $tiers->breakdown(60)));
    }

    public function test_volume_prices_every_unit_at_the_step_reached_and_has_its_cliff(): void
    {
        $tiers = self::tiers(Tiers::VOLUME);

        // Example 7: one more IP, cheaper.
        $this->assertSame('90.00', (string) $tiers->cost(50));
        $this->assertSame('76.50', (string) $tiers->cost(51));
        $this->assertSame([[60, '1.50']], array_map(fn ($row) => [$row[0], (string) $row[1]], $tiers->breakdown(60)));
    }

    public function test_more_steps_and_a_free_one(): void
    {
        $tiers = Tiers::of([['from' => 1, 'price' => '0'], ['from' => '3', 'price' => '2'], ['from' => 6, 'price' => '1.5']]);

        // 2 × 0 + 3 × 2 + 2 × 1.50
        $this->assertSame('9.00', (string) $tiers->cost(7));
        $this->assertSame([], $tiers->breakdown(0));
    }

    public static function provideBadTiers(): array
    {
        return [
            'none' => [[]],
            'not from 1' => [[['from' => 2, 'price' => '1.80']]],
            'not rising' => [[['from' => 1, 'price' => '1.80'], ['from' => 1, 'price' => '1.50']]],
            'falling' => [[['from' => 1, 'price' => '1.80'], ['from' => 51, 'price' => '1.50'], ['from' => 40, 'price' => '1']]],
            'negative price' => [[['from' => 1, 'price' => '-1']]],
            'sub-cent price' => [[['from' => 1, 'price' => '1.805']]],
            'from not a whole number' => [[['from' => '1.5', 'price' => '1']]],
            'from a float' => [[['from' => 1.0, 'price' => '1']]],
            'price a float' => [[['from' => 1, 'price' => 1.8]]],
            'no price' => [[['from' => 1]]],
        ];
    }

    #[DataProvider('provideBadTiers')]
    public function test_tiers_refuse_what_cannot_be_priced(array $steps): void
    {
        $this->expectException(InvalidArgumentException::class);

        Tiers::of($steps);
    }

    public function test_an_unknown_mode_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Tiers::of([['from' => 1, 'price' => '1']], 'stairs');
    }

    // --- The examples of §6.4 ------------------------------------------------------------------------------------

    public function test_example_1_sixty_ips_all_october_cost_one_month(): void
    {
        $actual = self::month(self::units(60, '2026-10-01'), '2026-10-01', '2026-11-01');

        $this->assertSame('105.00', $actual);
        $this->assertSame('0.00', UsageCharge::adjustment($actual, '105.00'));
    }

    public function test_example_2_sixty_then_eighty_from_the_eleventh(): void
    {
        $ips = array_merge(self::units(60, '2026-10-01'), self::units(20, '2026-10-11'));

        $actual = self::month($ips, '2026-10-01', '2026-11-01');

        // (10 × 105.00 + 21 × 135.00) / 31 = 3885 / 31 = 125.3225…
        $this->assertSame('125.32', $actual);
        $this->assertSame('20.32', UsageCharge::adjustment($actual, '105.00'));
        // 2α: November's advance (80 IPs) and October's correction on the invoice of 1/11.
        $this->assertSame('155.32', (string) self::tiers()->cost(80)->plus(UsageCharge::adjustment($actual, '105.00')));
    }

    public function test_example_3_eighty_then_forty_from_the_sixteenth_of_november(): void
    {
        $ips = array_merge(self::units(40, '2026-11-01'), self::units(40, '2026-11-01', '2026-11-16'));

        $actual = self::month($ips, '2026-11-01', '2026-12-01');

        // (15 × 135.00 + 15 × 72.00) / 30
        $this->assertSame('103.50', $actual);
        $this->assertSame('-31.50', UsageCharge::adjustment($actual, '135.00'));
        // 3α: December's advance (40 IPs) less the correction.
        $this->assertSame('40.50', (string) self::tiers()->cost(40)->plus(UsageCharge::adjustment($actual, '135.00')));
    }

    public function test_example_4_one_more_ip_for_one_day(): void
    {
        $ips = array_merge(self::units(60, '2026-10-01'), [[self::at('2026-10-20 09:00'), self::at('2026-10-20 17:00')]]);

        // (30 × 105.00 + 1 × 106.50) / 31 = 105.048…
        $this->assertSame('105.05', self::month($ips, '2026-10-01', '2026-11-01'));
    }

    public function test_example_5_a_first_period_from_the_twentieth_is_priced_on_the_whole_cycle(): void
    {
        $counts = UsageCharge::dailyCounts(self::units(10, '2026-10-20'), self::at('2026-10-20'), self::at('2026-11-01'));

        $this->assertCount(12, $counts);
        // 18.00 × 12 / 31 = 6.967…
        $this->assertSame('6.97', UsageCharge::charge(self::tiers(), $counts, 31));
    }

    public function test_example_6_peak_counts_the_most_ips_of_the_month(): void
    {
        $ips = array_merge(self::units(60, '2026-10-01'), self::units(20, '2026-10-11'));

        $this->assertSame('135.00', self::month($ips, '2026-10-01', '2026-11-01', UsageCharge::PEAK));
    }

    public function test_example_8_the_biggest_customer_at_the_general_tiers(): void
    {
        $this->assertSame('201.00', self::month(self::units(124, '2026-12-01'), '2026-12-01', '2027-01-01'));
    }

    // --- Distinct IPs of the period (the rule decided on 2026-11-12, §6.0) ------------------------------------------

    private static function distinct(array $intervals, string $start, string $end): int
    {
        return UsageCharge::distinctUnits($intervals, self::at($start), self::at($end));
    }

    public function test_the_decided_examples_count_every_ip_that_was_on_the_list(): void
    {
        $tiers = self::tiers();
        $ips = fn (int $n, string $from, ?string $to = null, int $offset = 0) => array_map(
            fn ($i) => ['10.0.0.' . ($i + $offset), self::at($from), $to === null ? null : self::at($to)], range(1, $n));

        // 1: 60 all October. 2: 20 more from the 11th. 3: 40 of 80 leave on the 16th.
        $this->assertSame(60, self::distinct($ips(60, '2026-10-01'), '2026-10-01', '2026-11-01'));
        $this->assertSame('135.00', UsageCharge::periodCharge($tiers,
            self::distinct(array_merge($ips(60, '2026-10-01'), $ips(20, '2026-10-11', null, 60)), '2026-10-01', '2026-11-01'), 31, 31));
        $this->assertSame('135.00', UsageCharge::periodCharge($tiers,
            self::distinct(array_merge($ips(40, '2026-11-01'), $ips(40, '2026-11-01', '2026-11-16', 40)), '2026-11-01', '2026-12-01'), 30, 30));
        // 4 and 5: one more for a day, a server replaced: 61.
        $this->assertSame('106.50', UsageCharge::periodCharge($tiers,
            self::distinct(array_merge($ips(60, '2026-10-01'), [['10.9.9.9', self::at('2026-10-20 09:00'), self::at('2026-10-20 17:00')]]), '2026-10-01', '2026-11-01'), 31, 31));
        $this->assertSame(61, self::distinct(array_merge($ips(59, '2026-10-01'),
            [['10.9.9.1', self::at('2026-10-01'), self::at('2026-10-15 12:00')], ['10.9.9.2', self::at('2026-10-15 12:05'), null]]), '2026-10-01', '2026-11-01'));
        // 6: a first period of 12 days of a 31-day cycle.
        $this->assertSame('6.97', UsageCharge::periodCharge($tiers, self::distinct($ips(10, '2026-10-20'), '2026-10-20', '2026-11-01'), 12, 31));
    }

    public function test_an_ip_counts_once_however_often_it_came_and_went_and_never_outside_the_period(): void
    {
        $this->assertSame(1, self::distinct([
            ['10.0.0.1', self::at('2026-10-02'), self::at('2026-10-03')],
            ['10.0.0.1', self::at('2026-10-10'), self::at('2026-10-11')],
            ['10.0.0.1', self::at('2026-10-20'), null],
        ], '2026-10-01', '2026-11-01'));
        $this->assertSame(0, self::distinct([
            ['10.0.0.2', self::at('2026-09-01'), self::at('2026-10-01')], // left at the period's first instant
            ['10.0.0.3', self::at('2026-11-01'), null],                   // came at its end
            ['10.0.0.4', self::at('2026-10-05 10:00'), self::at('2026-10-05 10:00')], // never there
        ], '2026-10-01', '2026-11-01'));
        $this->assertSame(1, self::distinct([['10.0.0.5', self::at('2026-09-01'), self::at('2026-10-01 00:00:01')]], '2026-10-01', '2026-11-01'));
    }

    public function test_a_period_charge_refuses_what_cannot_be_a_period(): void
    {
        foreach ([[0, 31], [32, 31]] as [$days, $cycle]) {
            try {
                UsageCharge::periodCharge(self::tiers(), 1, $days, $cycle);
                $this->fail("{$days} of {$cycle}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        self::distinct([], '2026-10-01', '2026-10-01');
    }

    // --- Days ------------------------------------------------------------------------------------------------------

    public function test_a_day_counts_if_the_ip_was_there_at_all_and_never_if_it_never_was(): void
    {
        $counts = UsageCharge::dailyCounts([
            [self::at('2026-10-02 23:59'), self::at('2026-10-03 00:01')], // two days
            [self::at('2026-10-05 10:00'), self::at('2026-10-05 10:00')], // never there
            [self::at('2026-10-06 10:00'), self::at('2026-10-06 09:00')], // removed before added: never there
            [self::at('2026-10-07 00:00'), self::at('2026-10-08 00:00')], // exactly the 7th
        ], self::at('2026-10-01'), self::at('2026-10-10'));

        $this->assertSame(['2026-10-01' => 0, '2026-10-02' => 1, '2026-10-03' => 1, '2026-10-04' => 0, '2026-10-05' => 0,
            '2026-10-06' => 0, '2026-10-07' => 1, '2026-10-08' => 0, '2026-10-09' => 0], $counts);
        $this->assertSame(3, UsageCharge::unitDays($counts));
    }

    public function test_a_replacement_the_same_day_is_one_extra_ip_day(): void
    {
        $ips = array_merge(self::units(59, '2026-10-01'), [
            [self::at('2026-10-01'), self::at('2026-10-15 12:00')],
            [self::at('2026-10-15 12:05'), null],
        ]);

        // 30 days of 60 and the 15th with 61: (30 × 105.00 + 106.50) / 31
        $this->assertSame('105.05', self::month($ips, '2026-10-01', '2026-11-01'));
    }

    public function test_days_are_local_days_across_a_change_of_summer_time(): void
    {
        // Athens leaves summer time on 25 October 2026: that day has 25 hours, still one day.
        $counts = UsageCharge::dailyCounts([[self::at('2026-10-25 23:30'), self::at('2026-10-26 00:30')]],
            self::at('2026-10-24'), self::at('2026-10-28'));

        $this->assertSame(['2026-10-24' => 0, '2026-10-25' => 1, '2026-10-26' => 1, '2026-10-27' => 0], $counts);
    }

    public function test_instants_from_another_timezone_are_compared_as_instants(): void
    {
        // 22:30 UTC on the 2nd is 01:30 on the 3rd in Athens.
        $utc = new DateTimeImmutable('2026-10-02 22:30', new DateTimeZone('UTC'));

        $counts = UsageCharge::dailyCounts([[$utc, null]], self::at('2026-10-01'), self::at('2026-10-05'));

        $this->assertSame(['2026-10-01' => 0, '2026-10-02' => 0, '2026-10-03' => 1, '2026-10-04' => 1], $counts);
    }

    public function test_bad_periods_are_refused(): void
    {
        foreach ([['2026-10-01 10:00', '2026-11-01'], ['2026-10-01', '2026-10-01'], ['2026-10-02', '2026-10-01']] as [$start, $end]) {
            try {
                UsageCharge::dailyCounts([], self::at($start), self::at($end));
                $this->fail("{$start} → {$end} was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([[[], 31], [['2026-10-01' => 1, '2026-10-02' => 1], 1]] as [$counts, $cycle]) {
            try {
                UsageCharge::charge(self::tiers(), $counts, $cycle);
                $this->fail('a period longer than its cycle, or of no days, was charged');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        UsageCharge::charge(self::tiers(), ['2026-10-01' => 1], 31, 'average');
    }

    // --- Any month -------------------------------------------------------------------------------------------------

    /** @return list<array{0: DateTimeImmutable, 1: DateTimeImmutable|null}> */
    private static function randomIntervals(Randomizer $random, DateTimeImmutable $start, int $days): array
    {
        $intervals = [];
        for ($i = $random->getInt(0, 140); $i > 0; $i--) {
            $added = $start->modify('+' . $random->getInt(-40, $days) * 1440 + $random->getInt(0, 1439) . ' minutes');
            $removed = $random->getInt(0, 2) === 0 ? null
                : $added->modify('+' . $random->getInt(0, 60 * 1440) . ' minutes');
            $intervals[] = [$added, $removed];
        }

        return $intervals;
    }

    public function test_any_month_obeys_what_a_customer_can_check(): void
    {
        $random = new Randomizer(new Mt19937(20261112));
        $months = [['2026-02-01', '2026-03-01'], ['2026-10-01', '2026-11-01'], ['2026-11-01', '2026-12-01'], ['2028-02-01', '2028-03-01']];

        for ($run = 0; $run < 300; $run++) {
            [$from, $to] = $months[$run % count($months)];
            $tiers = $run % 2 ? self::tiers() : self::tiers(Tiers::VOLUME);
            $start = self::at($from);
            $end = self::at($to);
            $intervals = self::randomIntervals($random, $start, (int) $start->diff($end)->days);
            $counts = UsageCharge::dailyCounts($intervals, $start, $end);
            $cycle = count($counts);
            $charge = BigDecimal::of(UsageCharge::charge($tiers, $counts, $cycle));
            $daily = array_map(fn ($units) => $tiers->cost($units), array_values($counts));
            $dearest = BigDecimal::max(...$daily)->toScale(2);
            $cheapest = BigDecimal::min(...$daily)->toScale(2);

            // Never more than its dearest day for the whole month, never less than its cheapest.
            $this->assertTrue($charge->isLessThanOrEqualTo($dearest), "run {$run}: {$charge} > {$dearest}");
            $this->assertTrue($charge->isGreaterThanOrEqualTo($cheapest), "run {$run}: {$charge} < {$cheapest}");
            // Graduated costs rise with the IPs, so there that dearest day is the peak.
            if ($tiers->mode === Tiers::GRADUATED) {
                $this->assertSame((string) $dearest, UsageCharge::charge($tiers, $counts, $cycle, UsageCharge::PEAK), "run {$run}");
            }

            // Graduated: one more IP for the whole month never lowers the charge, and adds at most its first price.
            if ($tiers->mode === Tiers::GRADUATED) {
                $more = BigDecimal::of(UsageCharge::charge($tiers, UsageCharge::dailyCounts(
                    array_merge($intervals, [[$start, null]]), $start, $end), $cycle));
                $this->assertTrue($more->isGreaterThanOrEqualTo($charge), "run {$run}: one more IP lowered it");
                $this->assertTrue($more->minus($charge)->isLessThanOrEqualTo('1.80'), "run {$run}: one more IP added {$more->minus($charge)}");
            }

            // Two halves of the month, each on the same cycle, add up to the month within a cent each.
            $mid = $start->modify('+14 days');
            $first = UsageCharge::charge($tiers, UsageCharge::dailyCounts($intervals, $start, $mid), $cycle);
            $second = UsageCharge::charge($tiers, UsageCharge::dailyCounts($intervals, $mid, $end), $cycle);
            $this->assertTrue(BigDecimal::of($first)->plus($second)->minus($charge)->abs()->isLessThanOrEqualTo('0.01'),
                "run {$run}: {$first} + {$second} vs {$charge}");
        }
    }
}
