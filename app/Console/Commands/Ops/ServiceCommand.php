<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\Dossier;
use App\Services\Ops\Lookup;
use Illuminate\Console\Command;
use RuntimeException;

/** Everything about a service, read-only (docs/ops.md). */
class ServiceCommand extends Command
{
    use PrintsReport;

    protected $signature = 'mycs:service {id : Service id, or its domain / license key} {--json : JSON output}';

    protected $description = 'Read-only dossier of a service: dates, invoices, module actions, license, mails, audit';

    public function handle(): int
    {
        try {
            $found = Lookup::service((string) $this->argument('id'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->report(Dossier::service($found));

        return self::SUCCESS;
    }
}
