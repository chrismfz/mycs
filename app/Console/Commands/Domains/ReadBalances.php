<?php

namespace App\Console\Commands\Domains;

use App\Services\Domains\RegistrarBalances;
use App\Support\Redact;
use Illuminate\Console\Command;
use Throwable;

/** What is left with each registrar (G8-1): read and kept for the dashboard, staff told when it is low. Reads only. */
class ReadBalances extends Command
{
    protected $signature = 'domains:balances';

    protected $description = 'Read the balance of every registrar account that tells it (the .gr registry, Openprovider)';

    public function handle(RegistrarBalances $balances): int
    {
        foreach ($balances->connections() as $connection) {
            try {
                $balances->refresh($connection);
            } catch (Throwable $e) {
                // One that fails (its mail, the database) never stops the others (review 3 of #206).
                report($e);
                $this->error("{$connection->label}: " . Redact::text($e->getMessage()));

                continue;
            }
            $balance = $connection->balance ?? [];
            $this->line("{$connection->label}: " . ($balance['amount'] ?? '—') . ' ' . ($balance['currency'] ?? '')
                . (($balance['error'] ?? null) !== null ? "  (could not read: {$balance['error']})" : '')
                . (($problem = RegistrarBalances::problemText($connection)) !== null ? "  ! {$problem}" : ''));
        }

        return self::SUCCESS;
    }
}
