<?php

namespace App\Support\Domains;

/** A child nameserver at the registrar: its name, its glue addresses, whether a domain uses it. Immutable. */
final readonly class ChildHost
{
    /**
     * @param  list<string>  $addresses
     * @param  bool  $read  false: listed, not read (past the read limit, or its read refused): taken as in use, never deleted
     */
    public function __construct(
        public string $name,
        public array $addresses,
        public bool $linked,
        public bool $read = true,
    ) {}

    /** @return array{name: string, addresses: list<string>, linked: bool, read: bool} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'addresses' => $this->addresses, 'linked' => $this->linked, 'read' => $this->read];
    }
}
