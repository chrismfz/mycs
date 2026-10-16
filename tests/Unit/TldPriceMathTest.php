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
}
