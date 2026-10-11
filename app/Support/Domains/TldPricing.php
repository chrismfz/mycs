<?php

namespace App\Support\Domains;

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
         * ('register' | 'renewal' | 'transfer' | 'restore') — an operation the
         * registrar didn't quote is simply absent (never guessed as 0.00).
         *
         * @var array<string, array{cost: float, currency: string}>
         */
        public readonly array $costs = [],
    ) {}
}
