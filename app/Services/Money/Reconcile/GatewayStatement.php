<?php

namespace App\Services\Money\Reconcile;

use Carbon\CarbonImmutable;

/** What a gateway listed for a span of time, and up to when its list is complete (a gateway's reports lag behind). */
final readonly class GatewayStatement
{
    /**
     * @param  list<GatewayLine>  $lines
     * @param  CarbonImmutable|null  $completeUntil  null: the gateway did not say
     */
    public function __construct(public array $lines, public ?CarbonImmutable $completeUntil = null) {}
}
