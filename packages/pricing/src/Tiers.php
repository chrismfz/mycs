<?php

namespace MyCS\Pricing;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * A monthly price per unit by quantity (MyCS docs/zonecloud-bulk-license.md §6.5): «1.80 each, 1.50 each from the 51st».
 * Graduated: each unit at the price of its own step (50 × 1.80 + 10 × 1.50 for 60). Volume: every unit at the price of the
 * step the quantity reaches (60 × 1.50), cheaper with one more unit at a step (50 → 90.00, 51 → 76.50).
 */
final class Tiers
{
    public const GRADUATED = 'graduated';

    public const VOLUME = 'volume';

    /** @param  list<array{from: int, price: BigDecimal}>  $steps */
    private function __construct(private readonly array $steps, public readonly string $mode) {}

    /**
     * @param  array<array-key, array<string, mixed>>  $steps  [from, price] each, the first from 1, each from higher than the
     *                                                         last (as staff saved them: checked here, not trusted)
     */
    public static function of(array $steps, string $mode = self::GRADUATED): self
    {
        if (!in_array($mode, [self::GRADUATED, self::VOLUME], true)) {
            throw new InvalidArgumentException("Unknown tier mode «{$mode}».");
        }
        if ($steps === []) {
            throw new InvalidArgumentException('Tiers need at least one step.');
        }
        $parsed = [];
        $last = 0;
        foreach (array_values($steps) as $i => $step) {
            $from = filter_var($step['from'] ?? null, FILTER_VALIDATE_INT);
            if ($from === false || ($i === 0 && $from !== 1) || $from <= $last) {
                throw new InvalidArgumentException('Tier steps start at 1 and each starts higher than the last.');
            }
            $price = BigDecimal::of((string) ($step['price'] ?? ''));
            if ($price->isNegative() || $price->getScale() > 2) {
                throw new InvalidArgumentException('A tier price is a non-negative amount in cents.');
            }
            $parsed[] = ['from' => $from, 'price' => $price->toScale(2)];
            $last = $from;
        }

        return new self($parsed, $mode);
    }

    /** What $quantity units cost for a whole month, exact (cents: prices are in cents, quantities whole). */
    public function cost(int $quantity): BigDecimal
    {
        if ($quantity < 0) {
            throw new InvalidArgumentException('A quantity cannot be negative.');
        }
        $total = BigDecimal::zero();
        foreach ($this->breakdown($quantity) as [$units, $price]) {
            $total = $total->plus($price->multipliedBy($units));
        }

        return $total;
    }

    /**
     * How $quantity units are priced, for an invoice line: [units, unit price] per step that has any.
     *
     * @return list<array{0: int, 1: BigDecimal}>
     */
    public function breakdown(int $quantity): array
    {
        if ($quantity < 1) {
            return [];
        }
        if ($this->mode === self::VOLUME) {
            $reached = $this->steps[0];
            foreach ($this->steps as $step) {
                if ($step['from'] <= $quantity) {
                    $reached = $step;
                }
            }

            return [[$quantity, $reached['price']]];
        }

        $rows = [];
        foreach ($this->steps as $i => $step) {
            if ($step['from'] > $quantity) {
                break;
            }
            $upTo = isset($this->steps[$i + 1]) ? min($quantity, $this->steps[$i + 1]['from'] - 1) : $quantity;
            $rows[] = [$upTo - $step['from'] + 1, $step['price']];
        }

        return $rows;
    }
}
