<?php

namespace App\Jobs;

use App\Services\Money\GatewayBalances;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Reads what Stripe and PayPal hold once a gateway is saved (Bank totals), not at the next hourly gateways:balances.
 * Once: a gateway that does not answer keeps its error until the next read. Saved twice before it runs, read once; saved
 * while it runs, read again (its settings may be newer than those it read).
 */
class RefreshGatewayBalances implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    public $timeout = 60;

    public $uniqueFor = 120;

    public function handle(): void
    {
        GatewayBalances::read();
    }
}
