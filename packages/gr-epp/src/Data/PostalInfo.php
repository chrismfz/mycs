<?php

namespace MyCS\GrEpp\Data;

/** A contact's address in one script: «loc» (Greek) or «int» (Latin). */
final readonly class PostalInfo
{
    /** @param  list<string>  $street */
    public function __construct(
        public string $type,
        public string $name,
        public ?string $org,
        public array $street,
        public ?string $city,
        public ?string $province,
        public ?string $postcode,
        public ?string $country,
    ) {}
}
