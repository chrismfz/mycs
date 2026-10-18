<?php

namespace MyCS\GrEpp\Data;

use DateTimeImmutable;

/**
 * What a write answered: done (1000) or done with actions pending (1001: a registration until it is assigned, an owner
 * change awaiting EETT), the registry's protocol (a registration's, for its recall), dates (UTC instants, and the days
 * as written: the ones to keep), its comments, a transfer's status.
 */
final readonly class Result
{
    /** @param  list<string>  $comments */
    public function __construct(
        public int $code,
        public ?string $name = null,
        public ?string $protocol = null,
        public ?DateTimeImmutable $created = null,
        public ?DateTimeImmutable $expires = null,
        public ?string $expiresOn = null,
        public ?string $transferStatus = null,
        public array $comments = [],
        public ?string $svTRID = null,
    ) {}

    public function pending(): bool
    {
        return $this->code === 1001;
    }
}
