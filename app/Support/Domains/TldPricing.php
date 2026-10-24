<?php

namespace App\Support\Domains;

use App\Services\Domains\Pricing\TldPriceMath;

/**
 * One registrar's COST answer for one TLD (Πυλώνας A / A2c) — what the
 * registrar charges OUR account per operation, for the TLD's minimum
 * registrable term. Feeds `domains:sync-pricing`, which writes ONLY the
 * `cost` column of domain_tld_prices — the sell `price` and `is_enabled`
 * stay operator decisions (margins are manual per TLD, README §7.1).
 *
 * Immutable.
 */
final class TldPricing
{
    public function __construct(
        /** Normalized TLD, no leading dot (e.g. 'gr', 'co.uk'). */
        public readonly string $tld,
        /**
         * Per-operation cost, keyed by OUR DomainTldPrice operation name
         * ('register' | 'renew' | 'transfer' | 'redemption') — an operation the
         * registrar didn't quote is simply absent (never guessed as 0.00).
         *
         * @var array<string, array{cost: string, currency: string}>
         */
        public readonly array $costs = [],
        /**
         * Days of the stages after expiry: the soft quarantine (our grace; its fee is `grace`, the restore fee where the
         * TLD has no hard quarantine) and the hard quarantine (our redemption, `redemption`); 0 = that stage does not
         * exist, null = not reported (D5a).
         */
        public readonly ?int $graceDays = null,
        public readonly ?int $redemptionDays = null,
        /**
         * The shortest registration the registrar sells, in years (.gr, .com.gr: 2): register and renew costs are for
         * that many years, never for one (owner 2026-10-24).
         */
        public readonly int $minYears = 1,
    ) {}

    /** What an operation costs for $years: register and renew in proportion to minYears, the others as quoted. */
    public function costFor(string $operation, int $years): ?string
    {
        $entry = $this->costs[$operation] ?? null;
        if ($entry === null) {
            return null;
        }

        return in_array($operation, ['register', 'renew'], true)
            ? TldPriceMath::forYears($entry['cost'], $years, $this->minYears)
            : TldPriceMath::cents($entry['cost']);
    }
}
