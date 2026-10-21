<?php

namespace App\Services\Service;

use App\Jobs\Server\CreateJob;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Jobs\Server\UpgradeJob;
use App\Models\ModuleAction;
use App\Services\Domains\ContactPush;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\Ordering\DomainOrderFulfilment;

/**
 * Staff's «Retry» on a Pending Module Action (Σ1): the same job, run now, with its own checks (a create only while the
 * service is pending, a suspend only while it is suspended…) and its mail. Throws what the server says.
 */
final class RetryModuleAction
{
    /** @return bool false when the job had nothing to do: the service moved on (staff mark it resolved) */
    public static function run(ModuleAction $action): bool
    {
        // A domain's renewal (D5b-2): its paid line, at its registrar, now.
        if ($action->action === ModuleAction::DOMAIN_RENEW) {
            $attempts = $action->attempts;
            $outcome = $action->invoice_item_id !== null ? app(DomainRenewalService::class)->renewLine($action->invoice_item_id, retry: true) : DomainRenewalService::NOTHING;
            $action->refresh();

            return $outcome !== DomainRenewalService::NOTHING && ($action->status !== ModuleAction::STATUS_OPEN || $action->attempts > $attempts);
        }
        // A domain ordered (D8b-2): sent to its registrar now, unless a charge is still unconfirmed.
        if (in_array($action->action, [ModuleAction::DOMAIN_REGISTER, ModuleAction::DOMAIN_TRANSFER], true)) {
            $attempts = $action->attempts;
            $outcome = app(DomainOrderFulfilment::class)->fulfil((int) $action->domain_id);
            $action->refresh();

            return $outcome === DomainOrderFulfilment::SENT || ($outcome === DomainOrderFulfilment::STAFF && $action->attempts > $attempts);
        }
        // A contact push (P1b-2): queued again, as the admin's Retry; the row closes when it succeeds.
        if ($action->action === ModuleAction::DOMAIN_CONTACT) {
            return ContactPush::retryDomain((int) $action->domain_id) > 0;
        }
        $service = $action->service;
        $job = match ($action->action) {
            'create' => new CreateJob($service),
            'suspend' => new SuspendJob($service),
            'unsuspend' => new UnsuspendJob($service),
            'terminate' => new TerminateJob($service),
            'upgrade' => new UpgradeJob($service),
            default => throw new \InvalidArgumentException("Unknown module action {$action->action}"),
        };
        $attempts = $action->attempts;
        $job->handle();
        $action->refresh();

        return $action->status !== ModuleAction::STATUS_OPEN || $action->attempts > $attempts;
    }
}
