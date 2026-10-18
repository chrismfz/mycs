<?php

namespace MyCS\GrEpp\Data;

/**
 * Changes to one of our contacts: its address (in each script given), phone, fax, email. Never its name or company:
 * a registrant's are changed only by the chargeable owner name change or owner change, per domain (owner 2026-10-18,
 * grepp.md §0); the registry also never takes an authInfo here (2308).
 */
final readonly class ContactChanges
{
    /**
     * @param  array{street: list<string>, city: string, province?: ?string, postcode?: ?string, country: string}|null  $localAddress
     * @param  array{street: list<string>, city: string, province?: ?string, postcode?: ?string, country: string}|null  $internationalAddress
     */
    public function __construct(
        public ?array $localAddress = null,
        public ?array $internationalAddress = null,
        public ?string $voice = null,
        public ?string $voiceExtension = null,
        public ?string $fax = null,
        public ?string $email = null,
    ) {}
}
