<?php

namespace Tests\Unit;

use App\Services\Domains\Pricing\TldPriceMath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** The arithmetic of TLD prices (docs/domains.md D-6): decimal strings, margins, WHMCS's roundings, N years. */
class TldPriceMathTest extends TestCase
{
    public function test_margins_are_exact_to_the_cent(): void
    {
        $priced = fn (string $cost, string $type, string $margin) => TldPriceMath::round(TldPriceMath::withMargin($cost, $type, $margin), 'none');
        $this->assertSame('11.76', $priced('9.80', 'percent', '20'));
        $this->assertSame('16.50', $priced('9.80', 'percent', '68.37'));
        $this->assertSame('12.30', $priced('9.80', 'fixed', '2.50'));
        $this->assertSame('0.35', $priced('0.10', 'percent', '245'), 'no float drift');
        $this->assertSame('13.17', TldPriceMath::round(TldPriceMath::withMargin(TldPriceMath::convert('9.80', '1.1195'), 'percent', '20'), 'none'), 'one rounding: 13.16532');
    }

    public function test_roundings_go_up_to_a_familiar_price_never_down(): void
    {
        $cases = [
            ['11.76', 'none', '11.76'], ['11.76', '.00', '12.00'], ['12.00', '.00', '12.00'], ['12.01', '.00', '13.00'],
            ['11.76', '.50', '12.00'], ['11.26', '.50', '11.50'], ['11.50', '.50', '11.50'], ['11.00', '.50', '11.00'],
            ['11.76', '.99', '11.99'], ['11.99', '.99', '11.99'], ['12.00', '.99', '12.99'], ['0.20', '.99', '0.99'],
            ['0.00', '.99', '0.00'], ['0', '.50', '0.00'], ['10.004', '.00', '11.00'], ['10.004', 'none', '10.00'],
        ];
        foreach ($cases as [$price, $rounding, $expected]) {
            $this->assertSame($expected, TldPriceMath::round($price, $rounding), "{$price} {$rounding}");
        }
    }

    public function test_years_conversion_and_cents(): void
    {
        $this->assertSame('49.50', TldPriceMath::years('16.50', 3));
        $this->assertSame('10.97', TldPriceMath::cents(TldPriceMath::convert('9.80', '1.1195'))); // 10.9711
        $this->assertSame('9.81', TldPriceMath::cents('9.805'));
        $this->assertSame('9.80', TldPriceMath::cents('9.8'));
    }

    public function test_bad_input_is_refused(): void
    {
        foreach ([fn () => TldPriceMath::years('1.00', 11), fn () => TldPriceMath::convert('1.00', '0'), fn () => TldPriceMath::withMargin('1e3', 'percent', '1'), fn () => TldPriceMath::withMargin('-5.00', 'percent', '20'),
            fn () => TldPriceMath::cents("1.00\n"),
            fn () => TldPriceMath::round('1.00', '.75'), fn () => TldPriceMath::withMargin('1.00', 'other', '1')] as $call) {
            try {
                $call();
                $this->fail('accepted bad input');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_margin_over_cost_in_percent_one_decimal_half_away_from_zero(): void
    {
        $this->assertSame('107.7', TldPriceMath::marginPercent('23.33', '11.23'));
        $this->assertSame('-8.7', TldPriceMath::marginPercent('4.20', '4.60'));
        $this->assertSame('-100.0', TldPriceMath::marginPercent('0.00', '4.60'), 'free, at a cost');
        $this->assertSame('0.1', TldPriceMath::marginPercent('1.0005', '1'), 'half up');
        $this->assertSame('-0.1', TldPriceMath::marginPercent('0.9995', '1'), 'half away from zero below');
        $this->assertSame('0.0', TldPriceMath::marginPercent('4.5999', '4.60'), 'never «-0.0»');
        $this->assertSame('20.0', TldPriceMath::marginPercent('13.16532', '10.9711'), 'a converted cost, exact');
        $this->assertNull(TldPriceMath::marginPercent('5.00', '0.00'), 'no percentage of a zero cost');
    }

    public function test_a_price_below_its_cost_is_a_loss_and_up_to_ten_percent_above_it_is_thin(): void
    {
        $this->assertSame('loss', TldPriceMath::alert('4.59', '4.60'));
        $this->assertSame('loss', TldPriceMath::alert('0.00', '4.60'), 'free at a cost');
        $this->assertSame('thin', TldPriceMath::alert('4.60', '4.60'), 'at cost');
        $this->assertSame('thin', TldPriceMath::alert('11.00', '10.00'), '10% exactly');
        $this->assertNull(TldPriceMath::alert('11.01', '10.00'));
        $this->assertNull(TldPriceMath::alert('0.00', '0.00'));
        $this->assertNull(TldPriceMath::alert('3.00', '0'), 'a zero cost: nothing to compare');
        $this->expectException(InvalidArgumentException::class);
        TldPriceMath::alert('-1', '1');
    }

    public function test_a_cost_quoted_for_some_years_for_others(): void
    {
        $this->assertSame(['6.25', '12.50', '25.00', '9.80', '14.99'], [TldPriceMath::forYears('12.50', 1, 2), TldPriceMath::forYears('12.50', 2, 2),
            TldPriceMath::forYears('12.50', 4, 2), TldPriceMath::forYears('9.80', 1), TldPriceMath::forYears('29.97', 1, 2)]);
        $this->expectException(InvalidArgumentException::class);
        TldPriceMath::forYears('12.50', 1, 0);
    }
}
