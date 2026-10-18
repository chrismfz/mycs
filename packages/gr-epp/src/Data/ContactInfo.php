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
        /** The disclose element's flag (1: these fields shown, 0: hidden); null: none given (the registry's policy). */
        public ?bool $disclosed,
        /** @var list<string> the fields it names: name:loc, org:int, addr:loc, voice, fax, email (review 5 of #185) */
        public array $disclosedFields = [],
    ) {}
}
