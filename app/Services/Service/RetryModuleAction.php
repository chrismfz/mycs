<?php

namespace App\Services\Service;

use App\Jobs\Server\CreateJob;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Jobs\Server\UpgradeJob;
use App\Models\ModuleAction;

/**
 * Staff's «Retry» on a Pending Module Action (Σ1): the same job, run now, with its own checks (a create only while the
 * service is pending, a suspend only while it is suspended…) and its mail. Throws what the server says.
 */
final class RetryModuleAction
{
    /** @return bool false when the job had nothing to do: the service moved on (staff mark it resolved) */
    public static function run(ModuleAction $action): bool
    {
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
