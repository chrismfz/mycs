<?php

namespace MyCS\Pricing\Tests;

use MyCS\Pricing\DifferenceDiscount;
use MyCS\Pricing\DifferenceDiscountRefused;
use MyCS\Pricing\LinePricer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DifferenceDiscountTest extends TestCase
{
    public function test_a_percentage_of_the_net_rounded_half_up(): void
    {
        // 0.42 net at 24% = 0.52 (the owner's «Upgrade total due: 0.52 EUR»); 10% of 0.42 = 0.042 → 0.04, 0.05 with tax.
        $discount = DifferenceDiscount::of('0.42', '24', DifferenceDiscount::PERCENT, '10');

        $this->assertSame(['0.42', '0.52', '0.04', '0.05', '0.47', '10'],
            [$discount->fullNet, $discount->fullTotal, $discount->discountNet, $discount->discountTotal, $discount->dueTotal, $discount->percent]);
        $this->assertFalse($discount->none());
        $this->assertFalse($discount->waived());
    }

    public function test_a_percentage_with_decimals_keeps_them_on_its_label(): void
    {
        $discount = DifferenceDiscount::of('20.00', null, DifferenceDiscount::PERCENT, '12.5');

        $this->assertSame(['2.50', '17.50', '12.5'], [$discount->discountNet, $discount->dueTotal, $discount->percent]);
    }

    public function test_a_hundred_percent_waives_it(): void
    {
        $discount = DifferenceDiscount::of('10.32', '24', DifferenceDiscount::PERCENT, '100');

        $this->assertSame(['10.32', '0.00'], [$discount->discountNet, $discount->dueTotal]);
        $this->assertTrue($discount->waived());
        $this->assertSame('100', $discount->percent);
    }

    public function test_zero_percent_is_no_discount(): void
    {
        $discount = DifferenceDiscount::of('10.32', '24', DifferenceDiscount::PERCENT, '0');

        $this->assertTrue($discount->none());
        $this->assertSame('12.80', $discount->dueTotal);
        $this->assertNull($discount->percent);
    }

    public function test_an_amount_to_pay_is_with_tax_and_reached_exactly_where_it_can_be(): void
    {
        // 10.32 net at 24% = 12.80; staff round it down to 12.50: 0.24 net off, 0.30 with tax.
        $discount = DifferenceDiscount::of('10.32', '24', DifferenceDiscount::AMOUNT, '12.5');

        $this->assertSame(['12.80', '0.24', '0.30', '12.50'], [$discount->fullTotal, $discount->discountNet, $discount->discountTotal, $discount->dueTotal]);
        $this->assertNull($discount->percent);

        // 12.00 is one no line reaches: 0.64 net off is 0.79 with tax (12.01, above), 0.65 is 0.81: 11.99, a cent under.
        $this->assertSame('11.99', DifferenceDiscount::of('10.32', '24', DifferenceDiscount::AMOUNT, '12')->dueTotal);
    }

    public function test_an_amount_no_line_rounding_can_reach_is_the_cent_below_never_above(): void
    {
        // 0.52 with tax; 0.50 needs 0.02 off with tax: a net 0.01 is 0.01 with tax, 0.02 is 0.02, so 0.50 is reached.
        $this->assertSame('0.50', DifferenceDiscount::of('0.42', '24', DifferenceDiscount::AMOUNT, '0.50')->dueTotal);

        // A total no discount line reaches: every net cent 0.00–1.00 gives these totals off at 24%; 0.03 is skipped
        // (0.02 → 0.02, 0.03 → 0.04), so 1.24 − 0.03 = 1.21 becomes 1.20.
        $discount = DifferenceDiscount::of('1.00', '24', DifferenceDiscount::AMOUNT, '1.21');
        $this->assertSame(['0.03', '0.04', '1.20'], [$discount->discountNet, $discount->discountTotal, $discount->dueTotal]);
    }

    public function test_every_amount_to_pay_is_never_exceeded_and_at_most_a_cent_under(): void
    {
        foreach (['24', '13', '6', '17', null] as $rate) {
            $full = LinePricer::invoiceLine('3.17', 1, $rate)['total'];
            for ($cents = 0; $cents <= (int) round((float) $full * 100); $cents++) {
                $pay = number_format($cents / 100, 2, '.', '');
                $due = DifferenceDiscount::of('3.17', $rate, DifferenceDiscount::AMOUNT, $pay)->dueTotal;
                $under = $cents - (int) round((float) $due * 100);
                $this->assertTrue($under >= 0 && $under <= 1, "rate {$rate}, pay {$pay}: due {$due}");
            }
        }
    }

    public function test_the_full_amount_to_pay_is_no_discount_and_zero_waives(): void
    {
        $this->assertTrue(DifferenceDiscount::of('10.32', '24', DifferenceDiscount::AMOUNT, '12.80')->none());

        $zero = DifferenceDiscount::of('10.32', '24', DifferenceDiscount::AMOUNT, '0');
        $this->assertTrue($zero->waived());
        $this->assertSame('10.32', $zero->discountNet);
    }

    public function test_waive_takes_all_of_it(): void
    {
        $discount = DifferenceDiscount::of('10.32', '24', DifferenceDiscount::WAIVE);

        $this->assertSame(['10.32', '12.80', '0.00'], [$discount->discountNet, $discount->discountTotal, $discount->dueTotal]);
        $this->assertTrue($discount->waived());
    }

    public function test_without_tax_the_amount_is_the_net(): void
    {
        $discount = DifferenceDiscount::of('10.32', null, DifferenceDiscount::AMOUNT, '10');

        $this->assertSame(['0.32', '0.32', '10.00'], [$discount->discountNet, $discount->discountTotal, $discount->dueTotal]);
    }

    public function test_a_repriced_charge_keeps_the_discounts_share(): void
    {
        // A percentage stays it: 10% of 3.87 = 0.387 → 0.39.
        $this->assertSame('0.39', DifferenceDiscount::follow('10.32', '1.03', '3.87', '10.00'));
        // An amount keeps the share it was: 0.24 of 10.32, of 3.87 = 0.09.
        $this->assertSame('0.09', DifferenceDiscount::follow('10.32', '0.24', '3.87'));
        // Waived in all but name (all of it): all of the new one, never more.
        $this->assertSame('3.87', DifferenceDiscount::follow('10.32', '10.32', '3.87'));
        // Nothing left to charge, or nothing charged before: nothing off.
        $this->assertSame('0.00', DifferenceDiscount::follow('10.32', '1.03', '0', '10'));
        $this->assertSame('0.00', DifferenceDiscount::follow('0.00', '1.03', '3.87'));
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function refused(): array
    {
        return [
            'above 100%' => ['10.00', DifferenceDiscount::PERCENT, '100.01', DifferenceDiscount::REFUSED_TOO_LARGE],
            'more to pay than the charge' => ['10.00', DifferenceDiscount::AMOUNT, '12.41', DifferenceDiscount::REFUSED_TOO_LARGE],
            'negative' => ['10.00', DifferenceDiscount::AMOUNT, '-1', DifferenceDiscount::REFUSED_INVALID],
            'three decimals' => ['10.00', DifferenceDiscount::PERCENT, '10.005', DifferenceDiscount::REFUSED_INVALID],
            'a comma' => ['10.00', DifferenceDiscount::AMOUNT, '1,50', DifferenceDiscount::REFUSED_INVALID],
            'empty' => ['10.00', DifferenceDiscount::PERCENT, '', DifferenceDiscount::REFUSED_INVALID],
            'unknown mode' => ['10.00', 'free', '1', DifferenceDiscount::REFUSED_INVALID],
            'nothing due' => ['0.00', DifferenceDiscount::PERCENT, '10', DifferenceDiscount::REFUSED_NOTHING_DUE],
            'a refund' => ['-3.00', DifferenceDiscount::WAIVE, '', DifferenceDiscount::REFUSED_NOTHING_DUE],
        ];
    }

    #[DataProvider('refused')]
    public function test_refused(string $full, string $mode, string $value, string $reason): void
    {
        try {
            DifferenceDiscount::of($full, '24', $mode, $value);
            $this->fail('not refused');
        } catch (DifferenceDiscountRefused $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}
