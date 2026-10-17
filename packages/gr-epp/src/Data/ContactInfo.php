<?php

namespace MyCS\GrEpp\Data;

use DateTimeImmutable;

/** A contact as the registry holds it (contact:info): one postal info per script, voice with its extension. */
final readonly class ContactInfo
{
    /**
     * @param  list<string>  $statuses
     * @param  array<string, PostalInfo>  $postalInfo  loc, int
     */
    public function __construct(
        public string $id,
        public string $roid,
        public array $statuses,
        public array $postalInfo,
        public ?string $voice,
        public ?string $voiceExtension,
        public ?string $fax,
        public ?string $email,
        public ?string $clientId,
        public ?DateTimeImmutable $created,
        public ?DateTimeImmutable $updated,
        public ?bool $disclosed,
    ) {}
}
