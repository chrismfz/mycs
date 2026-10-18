<?php

namespace MyCS\GrEpp\Data;

use DateTimeImmutable;

/** The registry's last check of the domain's DS records (extSecDNS-1.0, read only): pending ones keep it serverUpdateProhibited. */
final readonly class DsValidation
{
    public function __construct(
        public bool $successful,
        public ?string $outcome,
        public ?DateTimeImmutable $date,
    ) {}
}
