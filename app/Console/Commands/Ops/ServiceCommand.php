<?php

namespace App\Console\Commands\Ops;

use App\Models\Service;
use App\Services\Ops\Dossier;
use Illuminate\Console\Command;

/** Everything about a service, read-only (docs/ops.md). */
class ServiceCommand extends Command
{
    use PrintsReport;

    protected $signature = 'mycs:service {id : Service id, or its domain / license key} {--json : JSON output}';

    protected $description = 'Read-only dossier of a service: dates, invoices, module actions, license, mails, audit';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        $service = ctype_digit($id) ? Service::query()->find((int) $id) : Service::query()->where('domain', $id)->first();
        if ($service === null) {
            $this->error("No service {$id}");

            return self::FAILURE;
        }
        $this->report(Dossier::service($service));

        return self::SUCCESS;
    }
}
