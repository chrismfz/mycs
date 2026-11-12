<?php

namespace App\Services\Money\Reconcile;

use Carbon\CarbonImmutable;

/**
 * A gateway whose own list of transactions can be read, to be compared with what was recorded here
 * (GatewayReconcile). Read-only: an implementation only ever GETs. Its settings carry `reconcile_from` (a date; empty:
 * not compared) and `reconcile_days`.
 */
interface ListsTransactions
{
    /**
     * Every payment that came in, refund and reversal between the two moments, by the gateway's id.
     *
     * @throws \Throwable when the list cannot be read whole: a part of it is never an answer
     */
    public function transactions(CarbonImmutable $from, CarbonImmutable $to): GatewayStatement;
}
