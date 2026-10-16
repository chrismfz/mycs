<?php

namespace App\Support\Domains;

/**
 * Result of a DomainRegistrar::checkAvailability() call — uniform across
 * registrars (docs/domains/README.md §4.1). `reason` carries the registry's
 * human message when taken/invalid (the .gr registry answers in Greek — shown
 * to the operator as-is).
 *
 * Immutable.
 */
final class AvailabilityResult
{
    public function __construct(
        public readonly string $fqdn,
        public readonly bool $available,
        public readonly ?string $reason = null,
        /** Premium/priced name — a register must NOT proceed on the standard price assumption. */
        public readonly bool $premium = false,
    ) {}
}
