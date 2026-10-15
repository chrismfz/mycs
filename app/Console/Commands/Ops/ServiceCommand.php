<?php

namespace App\Console\Commands\Ops;

use App\Models\License;
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
        // An id, a license key, or a domain: of the services with that domain, a live one before an ended one (review 6).
        $service = ctype_digit($id) ? Service::query()->find((int) $id)
            : (Service::query()->find(License::query()->where('license_key', $id)->value('service_id'))
                ?? Service::query()->where('domain', $id)->orderByRaw('CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END', [Service::STATUS_CANCELLED, Service::STATUS_TERMINATED])
                    ->latest('id')->first());
        if ($service === null) {
            $this->error("No service {$id}");

            return self::FAILURE;
        }
        $this->report(Dossier::service($service));

        return self::SUCCESS;
    }
}
