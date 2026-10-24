<?php

namespace MyCS\BankAlerts;

use DateTimeImmutable;

/** One movement of a bank account, as its alert email tells it. Amounts are decimal strings («1234.56»), never floats. */
final class BankAlert
{
    public const CREDIT = 'credit';

    public const DEBIT = 'debit';

    public function __construct(
        public readonly string $bank,
        public readonly string $direction,
        /** Always positive: the direction says which way. */
        public readonly string $amount,
        /** The account's balance after it (the available one), when the alert says. */
        public readonly ?string $balance,
        /** The transfer's reference, as the payer wrote it (where the invoice's number is looked for). */
        public readonly string $reason,
        public readonly ?DateTimeImmutable $executedAt,
        public readonly ?string $messageId,
        /** Our mail server vouches the bank sent it (see Authenticity). */
        public readonly bool $authentic,
        public readonly string $authenticity,
    ) {}

    public function isCredit(): bool
    {
        return $this->direction === self::CREDIT;
    }
}
