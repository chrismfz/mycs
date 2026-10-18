<?php

namespace MyCS\GrEpp\Data;

use DateTimeImmutable;

/** A nameserver as the registry holds it (host:info): addresses only for a host under .gr / .ελ (glue). */
final readonly class HostInfo
{
    /**
     * @param  list<string>  $statuses
     * @param  list<array{ip: string, version: string}>  $addresses
     */
    public function __construct(
        public string $name,
        public string $roid,
        public array $statuses,
        public array $addresses,
        public ?string $clientId,
        public ?DateTimeImmutable $created,
    ) {}
}
