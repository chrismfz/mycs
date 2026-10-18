<?php

namespace App\Console\Commands\Domains;

use App\Services\Domains\RegistrarBalances;
use Illuminate\Console\Command;

/** What is left with each registrar (G8-1): read and kept for the dashboard, staff told when it is low. Reads only. */
class ReadBalances extends Command
{
    protected $signature = 'domains:balances';

    protected $description = 'Read the balance of every registrar account that tells it (the .gr registry, Openprovider)';

    public function handle(RegistrarBalances $balances): int
    {
        foreach ($balances->connections() as $connection) {
            $balances->refresh($connection);
            $balance = $connection->balance ?? [];
            $this->line("{$connection->label}: " . ($balance['amount'] ?? '—') . ' ' . ($balance['currency'] ?? '')
                . (($balance['error'] ?? null) !== null ? "  (could not read: {$balance['error']})" : '')
                . (($problem = RegistrarBalances::problem($connection)) !== null ? "  ! {$problem}" : ''));
        }

        return self::SUCCESS;
    }
}
