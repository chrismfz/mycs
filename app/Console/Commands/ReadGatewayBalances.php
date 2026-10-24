<?php

namespace App\Console\Commands;

use App\Services\Money\GatewayBalances;
use Illuminate\Console\Command;

class ReadGatewayBalances extends Command
{
    protected $signature = 'gateways:balances';

    protected $description = 'Read what Stripe and PayPal hold (the dashboard\'s Bank totals)';

    public function handle(): int
    {
        $failed = false;
        foreach (GatewayBalances::read() as $gateway => $balance) {
            if ($balance['error'] !== null) {
                $this->error("{$gateway}: {$balance['error']}");
                $failed = true;

                continue;
            }
            $this->line($gateway . ': ' . (collect($balance['rows'])->map(fn ($r) => "{$r['available']} {$r['currency']}")->implode(', ') ?: 'nothing'));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
