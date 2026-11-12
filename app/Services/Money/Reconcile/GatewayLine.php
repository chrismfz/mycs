<?php

namespace App\Services\Money\Reconcile;

use Carbon\CarbonImmutable;

/**
 * One transaction as a gateway's own list has it (docs/payments-tracking.md, «Συμφωνία gateway»): its id there, when,
 * how much. Ids, dates and amounts only: never who paid.
 */
final readonly class GatewayLine
{
    /** Money that came in. */
    public const PAYMENT = 'payment';

    /** Money we gave back. */
    public const REFUND = 'refund';

    /** Money the gateway took back (a reversal, a chargeback). */
    public const REVERSAL = 'reversal';

    /**
     * @param  string  $amount  its size, a positive decimal string («12.50»)
     * @param  bool  $completed  false: pending, denied: found at the gateway, never judged
     * @param  string|null  $parent  the payment a refund or reversal is of
     * @param  string|null  $agreement  the subscription or billing agreement that took it, when one did
     */
    public function __construct(
        public string $what,
        public string $id,
        public CarbonImmutable $at,
        public string $amount,
        public string $currency,
        public bool $completed = true,
        public ?string $parent = null,
        public ?string $agreement = null,
    ) {}
}
