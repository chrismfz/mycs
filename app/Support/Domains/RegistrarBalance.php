<?php

namespace App\Support\Domains;

use Illuminate\Support\Carbon;

/**
 * What is left in our account with a registrar (G8-1): an amount as a decimal string (it may be negative: the .gr
 * registry), its currency when the registrar says it; what it holds back for orders under way (Openprovider); whether
 * chargeable actions are allowed and when they will be, or were, suspended (the .gr registry).
 */
final readonly class RegistrarBalance
{
    public function __construct(
        public string $amount,
        public ?string $currency = null,
        public ?string $reserved = null,
        public ?bool $chargesAllowed = null,
        public ?Carbon $suspendsOn = null,
        public ?Carbon $suspendedOn = null,
    ) {}

    /** @return array{amount: string, currency: ?string, reserved: ?string, charges_allowed: ?bool, suspends_on: ?string, suspended_on: ?string} */
    public function toArray(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency, 'reserved' => $this->reserved, 'charges_allowed' => $this->chargesAllowed,
            'suspends_on' => $this->suspendsOn?->toIso8601String(), 'suspended_on' => $this->suspendedOn?->toIso8601String()];
    }
}
