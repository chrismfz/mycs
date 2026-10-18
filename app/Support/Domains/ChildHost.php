<?php

namespace App\Support\Domains;

/** A child nameserver at the registrar: its name, its glue addresses, whether a domain uses it. Immutable. */
final readonly class ChildHost
{
    /** @param  list<string>  $addresses */
    public function __construct(
        public string $name,
        public array $addresses,
        public bool $linked,
    ) {}

    /** @return array{name: string, addresses: list<string>, linked: bool} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'addresses' => $this->addresses, 'linked' => $this->linked];
    }
}
