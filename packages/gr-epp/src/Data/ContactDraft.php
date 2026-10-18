<?php

namespace MyCS\GrEpp\Data;

/**
 * A contact to create at the registry: our id («c60_…»), its address in Greek (loc) and, if given, in Latin (int), its
 * phone (+CCC.NUMBER) with extension, fax, email; hidden from WHOIS by default (disclose flag 0, every field).
 */
final readonly class ContactDraft
{
    public function __construct(
        public string $id,
        public PostalInfo $local,
        public ?PostalInfo $international,
        public string $voice,
        public string $email,
        public ?string $voiceExtension = null,
        public ?string $fax = null,
        /** null: no disclose element (the registry's default: how the current module creates them, live 2026-10-18) */
        public ?bool $hidden = true,
    ) {}
}
