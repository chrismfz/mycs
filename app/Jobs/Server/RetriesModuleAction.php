<?php

namespace App\Jobs\Server;

use App\Exceptions\ReconcileFailed;
use App\Helpers\NotificationHelper;
use App\Models\ModuleAction;
use RuntimeException;
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

    /**
     * Taken by the queue at last (re-sent by a replay, or by staff's Retry): its «not queued» row is closed, whatever the
     * job then finds to do (nothing, a service moved on), so no later replay sends it again (round 2 of #582). A failure
     * from here on is a row of its own.
     */
    protected function taken(): void
    {
        $now = now();
        ModuleAction::query()->open()->where('service_id', $this->service->id)->where('action', $this->moduleAction())->whereNull('subject')
            ->where('last_error', 'like', ModuleAction::NOT_QUEUED . '%')
            ->update(['status' => ModuleAction::STATUS_RESOLVED, 'resolved_at' => $now, 'resolution' => 'queued and run', 'updated_at' => $now]);
    }

    /**
     * Its push to the queue failed after the commit that decided it (AfterCommit::job, on a queue other than the database
     * one): an open row in Pending Module Actions, for staff to Retry and for its payment's replay to ask for again.
     */
    public function notQueued(Throwable $error): void
    {
        // Given up at once: no queue retries a job it never took, so staff's Retry is offered (round 2 of #582).
        ModuleAction::recordFailure($this->service, $this->moduleAction(), $this->service->provisioningServer()?->extension,
            new RuntimeException(ModuleAction::NOT_QUEUED . $error->getMessage()))->forceFill(['given_up_at' => now()])->save();
        NotificationHelper::sendSystemEmailNotification("Module action not queued: {$this->moduleAction()} of service #{$this->service->id}", e(
            "The {$this->moduleAction()} of service #{$this->service->id} could not be queued: {$error->getMessage()}. "
            . 'It is listed in Pending Module Actions: retry it there; the replay of its paid invoice asks for it again.'
        ));
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof ReconcileFailed) {
            return; // given up as the action it was (SerializesLifecycle::reconcile), never as this job's
        }
        if (!ModuleAction::giveUp($this->service, $this->moduleAction(), $exception)) {
            return;
        }
        $error = $exception?->getMessage() ?? 'unknown error';
        NotificationHelper::sendSystemEmailNotification("Module action failed: {$this->moduleAction()} of service #{$this->service->id}", e(
            "The server could not {$this->moduleAction()} service #{$this->service->id} after 4 attempts: {$error}. "
            . 'It is listed in Pending Module Actions: retry it there once the cause is fixed, or mark it resolved.'
        ));
    }
}
