<?php

namespace App\Support\Domains;

/**
 * One contact as the registrar returns it (Πυλώνας A / A2c import) — resolved
 * from a reusable handle (e.g. Openprovider AB123456-XX) into the fields our
 * domain_contacts row stores. The import upserts it per (domain, type); it is
 * the operator's manual-assign aid on unassigned domains (README §9).
 *
 * Immutable.
 */
final class RegistrarContact
{
    public function __construct(
        public readonly string $handle,
        public readonly string $name,
        public readonly ?string $org = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $address1 = null,
        public readonly ?string $address2 = null,
        public readonly ?string $city = null,
        public readonly ?string $postcode = null,
        /** ISO 3166-1 alpha-2, uppercased, or null. */
        public readonly ?string $country = null,
    ) {}
}
