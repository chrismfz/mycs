<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\Dossier;
use App\Services\Ops\Lookup;
use Illuminate\Console\Command;
use RuntimeException;

/** Everything about an account, read-only (docs/ops.md). */
class ClientCommand extends Command
{
    use PrintsReport;

    protected $signature = 'mycs:client {id : Account id, or a contact\'s email} {--json : JSON output}';

    protected $description = 'Read-only dossier of an account: services, invoices, credit, mails, audit';

    public function handle(): int
    {
        try {
            $found = Lookup::client((string) $this->argument('id'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->report(Dossier::client($found));

        return self::SUCCESS;
    }
}
