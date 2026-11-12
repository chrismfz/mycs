<?php

namespace MyCS\Pricing\Tests;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MyCS\Pricing\Tiers;
use MyCS\Pricing\UsageCharge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Adversarial review of #820 (Tiers, UsageCharge). */
final class UsageChargeAdversarialTest extends TestCase
{
    private static function athens(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone('Europe/Athens'));
    }

    /**
     * ADV-820-1: a period whose end is midnight in another timezone than its start passes the midnight check (each is
     * checked in its own zone), and the day loop then runs one extra local day: October from Athens midnight to UTC
     * midnight of 1 November has 32 days, and an IP added at 01:00 on 1 November (Athens) is charged on «2026-11-01»
     * inside October, and again in November. Either refuse the mixed period or stop at the end.
     */
    public function test_a_period_ending_at_midnight_in_another_zone_does_not_count_a_day_of_the_next_period(): void
    {
        $ip = [[self::athens('2026-11-01 01:00'), null]];

        try {
            $counts = UsageCharge::dailyCounts($ip, self::athens('2026-10-01'),
                new DateTimeImmutable('2026-11-01', new DateTimeZone('UTC')));
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertArrayNotHasKey('2026-11-01', $counts, 'October counted a day of November');
        $this->assertSame(0, UsageCharge::unitDays($counts), 'an IP added in November was charged in October');
    }

    /**
     * ADV-820-2: the midnight check reads H:i:s only, so a start/end with microseconds passes. The days then run from
     * 00:00:00.5 to 00:00:00.5, and an IP added at 00:00:00.2 on 1 November is charged on 31 October (and again on
     * 1 November in the next period): a double-charged day.
     */
    public function test_a_period_boundary_with_microseconds_is_not_midnight(): void
    {
        $ip = [[self::athens('2026-11-01 00:00:00.2'), null]];

        try {
            $counts = UsageCharge::dailyCounts($ip, self::athens('2026-10-01 00:00:00.5'), self::athens('2026-11-01 00:00:00.5'));
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertSame(0, UsageCharge::unitDays($counts), 'an IP added in November was charged on 31 October');
    }

    public static function provideUnparseablePrices(): array
    {
        return ['empty' => [''], 'leading space' => [' 1.80'], 'text' => ['abc'], 'null' => [null], 'false' => [false], 'hex' => ['0x10']];
    }

    /**
     * ADV-820-3: a staff-saved price that is not a number escapes Tiers::of as Brick\Math NumberFormatException (a
     * RuntimeException), not the InvalidArgumentException every other bad step gets; a caller that catches
     * InvalidArgumentException to show «bad tiers» lets it through as a 500 / failed cron.
     */
    #[DataProvider('provideUnparseablePrices')]
    public function test_an_unparseable_price_is_an_invalid_argument(mixed $price): void
    {
        $this->expectException(InvalidArgumentException::class);

        Tiers::of([['from' => 1, 'price' => $price]]);
    }

    /**
     * ADV-820-4: booleans pass as numbers: price true is accepted as 1.00 (false is refused), and from true as 1.
     * «checked here, not trusted» should refuse them.
     */
    public function test_booleans_are_not_prices_or_quantities(): void
    {
        foreach ([[['from' => 1, 'price' => true]], [['from' => true, 'price' => '1.80']]] as $steps) {
            try {
                Tiers::of($steps);
                $this->fail('accepted ' . json_encode($steps));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * ADV-820-5: adjustment() with an amount that is not in cents throws Brick\Math RoundingNecessaryException
     * (toScale(2) without a rounding mode), and with text NumberFormatException: neither is an InvalidArgumentException.
     */
    public function test_adjustment_refuses_non_cent_input_as_an_invalid_argument(): void
    {
        foreach ([['1.005', '0.00'], ['105.00', ' 1'], ['', '0']] as [$actual, $billed]) {
            try {
                UsageCharge::adjustment($actual, $billed);
                $this->fail("accepted {$actual} − {$billed}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
