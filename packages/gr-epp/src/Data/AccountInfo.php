<?php

namespace MyCS\GrEpp\Data;

use DateTimeImmutable;

/**
 * The registrar's account (account-1.1): its balance (may be negative: decimal string, never float), whether chargeable
 * actions are allowed, when they will be (or were) suspended.
 */
final readonly class AccountInfo
{
    public function __construct(
        public string $roid,
        public string $balance,
        public string $currency,
        public bool $chargeableActionsAllowed,
        public ?string $paymentCode,
        public ?DateTimeImmutable $toBeSuspendedOn,
        public ?DateTimeImmutable $suspendedOn,
    ) {}
}
