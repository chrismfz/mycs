<?php

namespace App\Jobs\Server;

use App\Helpers\NotificationHelper;
use App\Models\ModuleAction;
use Throwable;

/**
 * A server job that fails is tried again 3 times (after 1 minute, 10 minutes, 1 hour; Σ1). After the last, its open
 * module action is staff's: it is marked given up, listed in Pending Module Actions, and staff get one email.
 */
trait RetriesModuleAction
{
    public $tries = 4;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 600, 3600];
    }

    abstract protected function moduleAction(): string;

    public function failed(?Throwable $exception): void
    {
        if (!ModuleAction::giveUp($this->service, $this->moduleAction())) {
            return;
        }
        $error = $exception?->getMessage() ?? 'unknown error';
        NotificationHelper::sendSystemEmailNotification("Module action failed: {$this->moduleAction()} of service #{$this->service->id}", e(
            "The server could not {$this->moduleAction()} service #{$this->service->id} after 4 attempts: {$error}. "
            . 'It is listed in Pending Module Actions: retry it there once the cause is fixed, or mark it resolved.'
        ));
    }
}
