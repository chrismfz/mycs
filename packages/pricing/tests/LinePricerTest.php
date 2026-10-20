<?php

namespace MyCS\Pricing\Tests;

use InvalidArgumentException;
use MyCS\Pricing\Discount;
use MyCS\Pricing\LinePricer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinePricerTest extends TestCase
{
    public function test_no_discount_no_tax(): void
    {
        $line = LinePricer::price('10', '5');

        $this->assertSame(['10.00', '5.00', '10.00', '5.00', '15.00'], [$line->price, $line->setupFee, $line->grossPrice, $line->grossSetupFee, $line->firstPayment()]);
        $this->assertFalse($line->hasDiscount());
    }

    public function test_exclusive_tax_is_added_and_rounded_once(): void
    {
        $line = LinePricer::price('19.99', '0', taxRate: '24', taxMode: LinePricer::TAX_EXCLUSIVE);

        // 19.99 × 24% = 4.7976 → 4.80
        $this->assertSame(['19.99', '4.80', '24.79'], [$line->price, $line->priceTax, $line->grossPrice]);
    }

    public function test_inclusive_tax_is_taken_out_of_the_catalog_amount(): void
    {
        $line = LinePricer::price('24.80', '0', taxRate: '24', taxMode: LinePricer::TAX_INCLUSIVE);

        $this->assertSame(['4.80', '24.80'], [$line->priceTax, $line->grossPrice]);
    }

    /** CART-8: the discount comes off the catalog (net) amount before tax, so a recalculation gives the same price. */
    public function test_a_fixed_discount_comes_off_before_exclusive_tax(): void
    {
        $line = LinePricer::price('20', '0', new Discount('fixed', '5', 'price'), '24', LinePricer::TAX_EXCLUSIVE);

        $this->assertSame(['15.00', '5.00', '3.60', '18.60'], [$line->price, $line->priceDiscount, $line->priceTax, $line->grossPrice]);
    }

    /** CART-8: «price and setup fee» with a fixed amount is one amount per line, not the amount twice. */
    public function test_a_fixed_discount_on_all_is_one_amount_price_first(): void
    {
        $line = LinePricer::price('4', '20', new Discount('fixed', '10', 'all'));

        $this->assertSame(['4.00', '6.00'], [$line->priceDiscount, $line->setupFeeDiscount]);
        $this->assertSame(['0.00', '14.00', '14.00'], [$line->price, $line->setupFee, $line->firstPayment()]);
    }

    public function test_a_fixed_discount_never_goes_below_zero(): void
    {
        $line = LinePricer::price('3', '0', new Discount('fixed', '10', 'price'));

        $this->assertSame(['0.00', '3.00'], [$line->price, $line->priceDiscount]);
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function percentages(): array
    {
        return [
            'price only' => ['price', '9.99', '25.00', '7.49', '25.00'],
            'setup fee only' => ['setup_fee', '9.99', '25.00', '9.99', '18.75'],
            'both' => ['all', '9.99', '25.00', '7.49', '18.75'],
        ];
    }

    #[DataProvider('percentages')]
    public function test_a_percentage_applies_to_what_it_says(string $appliesTo, string $price, string $setup, string $expectedPrice, string $expectedSetup): void
    {
        $line = LinePricer::price($price, $setup, new Discount('percentage', '25', $appliesTo));

        $this->assertSame([$expectedPrice, $expectedSetup], [$line->price, $line->setupFee]);
    }

    public function test_a_percentage_above_100_is_capped(): void
    {
        $this->assertSame('0.00', LinePricer::price('10', '0', new Discount('percentage', '150', 'price'))->price);
    }

    public function test_zero_rate_means_no_tax(): void
    {
        $line = LinePricer::price('10', '0', taxRate: '0', taxMode: LinePricer::TAX_EXCLUSIVE);

        $this->assertSame(['0.00', '10.00'], [$line->priceTax, $line->grossPrice]);
    }

    public function test_amounts_are_rounded_to_cents_half_up(): void
    {
        $this->assertSame('10.01', LinePricer::price('10.005', '0')->price);
    }

    public function test_net_amounts_come_out_of_inclusive_prices(): void
    {
        $line = LinePricer::price('24.80', '12.40', taxRate: '24', taxMode: LinePricer::TAX_INCLUSIVE);

        $this->assertSame(['20.00', '10.00', '30.00'], [$line->netPrice, $line->netSetupFee, $line->firstPaymentNet()]);
    }

    public function test_net_amounts_of_exclusive_prices_are_the_catalog_ones(): void
    {
        $line = LinePricer::price('20', '5', new Discount('fixed', '5', 'price'), '24', LinePricer::TAX_EXCLUSIVE);

        $this->assertSame(['15.00', '5.00', '20.00'], [$line->netPrice, $line->netSetupFee, $line->firstPaymentNet()]);
    }

    /** CART-14: tax on the line, not per unit. */
    public function test_an_invoice_line_is_taxed_once_on_its_total(): void
    {
        $this->assertSame(['net' => '99.00', 'tax' => '23.76', 'total' => '122.76'], LinePricer::invoiceLine('0.99', 100, '24'));
        $this->assertSame(['net' => '10.00', 'tax' => '0.00', 'total' => '10.00'], LinePricer::invoiceLine('10', 1, null));
        $this->assertSame(['net' => '-5.00', 'tax' => '-1.20', 'total' => '-6.20'], LinePricer::invoiceLine('-5', 1, '24'));
    }

    public function test_bad_input_is_refused(): void
    {
        foreach ([
            fn () => LinePricer::price('-1', '0'),
            fn () => LinePricer::price('1', '0', taxRate: '-5'),
            fn () => LinePricer::price('1', '0', taxMode: 'gross'),
            fn () => new Discount('bogus', '1'),
            fn () => new Discount('fixed', '-1'),
            fn () => new Discount('fixed', '1', 'tax'),
        ] as $call) {
            try {
                $call();
                $this->fail('accepted bad input');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_group_discount_takes_its_percentage_of_what_the_coupon_left(): void
    {
        // 20.00 less a 25% coupon = 15.00; a 10% group = 1.50 off: 13.50; the setup fee 5.00 less 10% = 4.50.
        $line = LinePricer::price('20', '5', new Discount('percentage', '25', 'price'), groupPercent: '10');

        $this->assertSame(['13.50', '4.50', '1.50', '0.50'], [$line->price, $line->setupFee, $line->priceGroupDiscount, $line->setupFeeGroupDiscount]);
        $this->assertTrue($line->hasDiscount());
        $this->assertTrue($line->hasGroupDiscount());
    }

    public function test_a_group_discount_rounds_half_up_once_and_comes_before_tax(): void
    {
        // 0.35 × 10% = 0.035 → 0.04 off: 0.31; then 24% tax = 0.0744 → 0.07.
        $line = LinePricer::price('0.35', '0', taxRate: '24', taxMode: LinePricer::TAX_EXCLUSIVE, groupPercent: '10');

        $this->assertSame(['0.31', '0.04', '0.07', '0.38'], [$line->price, $line->priceGroupDiscount, $line->priceTax, $line->grossPrice]);
        $this->assertFalse($line->hasDiscount(), 'a group discount is not a coupon');
    }

    public function test_no_group_or_zero_is_no_discount_and_a_bad_percentage_is_refused(): void
    {
        foreach ([null, '0', '0.00'] as $none) {
            $line = LinePricer::price('9.99', '0', groupPercent: $none);
            $this->assertSame(['9.99', '0.00'], [$line->price, $line->priceGroupDiscount]);
            $this->assertFalse($line->hasGroupDiscount());
        }
        $this->assertSame('0.00', LinePricer::price('9.99', '0', groupPercent: '100')->price);

        foreach (['-1', '100.01'] as $bad) {
            try {
                LinePricer::price('9.99', '0', groupPercent: $bad);
                $this->fail("{$bad} accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_price_override_makes_the_amounts_its_value_never_more(): void
    {
        // WHMCS «Price Override» (nixpal: FREETRIAL 0.00, 25OffAgentNode 1.50).
        $free = LinePricer::price('20', '5', new Discount('override', '0'));
        $this->assertSame(['0.00', '0.00', '20.00', '5.00'], [$free->price, $free->setupFee, $free->priceDiscount, $free->setupFeeDiscount]);

        $agent = LinePricer::price('2', '0', new Discount('override', '1.50', 'price'));
        $this->assertSame(['1.50', '0.50'], [$agent->price, $agent->priceDiscount]);

        $cheaper = LinePricer::price('1', '0', new Discount('override', '1.50'));
        $this->assertSame(['1.00', '0.00'], [$cheaper->price, $cheaper->priceDiscount], 'never raises a price');
        $this->assertFalse($cheaper->hasDiscount());
    }
}
