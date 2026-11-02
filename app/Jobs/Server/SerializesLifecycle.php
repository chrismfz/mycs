<?php

namespace App\Jobs\Server;

use App\Exceptions\DisplayException;
use App\Exceptions\NothingToDoOnServer;
use App\Exceptions\ReconcileFailed;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\ModuleAction;
use App\Models\Service;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A suspension or unsuspension job runs under its service's lifecycle lock (ExtensionHelper::lifecycleLock, predeploy
 * R2). Who cannot have it:
 * - a worker waits for it (less than its own timeout), and is tried again later if it is still held: once it has it, it
 *   reads the service again and asks the server, as ever (never trusting what was done before: «a mark is no proof»);
 * - a job of the sync queue, run inside the very call that holds it (a payment recorded while a suspension is on its
 *   way), leaves a note and returns: the holder, once its call is answered, settles the account with the service's
 *   status (reconcile);
 * - a job run in a request (staff's Retry) is refused out loud: try again in a minute.
 */
trait SerializesLifecycle
{
    /** How long a worker waits for the lock: under the jobs' 120 s timeout, or the worker kills it waiting. */
    public static int $lockWait = 90;

    protected function serialized(Closure $action): void
    {
        $lock = ExtensionHelper::lifecycleLock($this->service);
        $worker = $this->job !== null && !$this->job instanceof SyncJob;
        try {
            $held = $worker ? $lock->block(self::$lockWait) : $lock->get();
        } catch (LockTimeoutException) {
            $held = false;
        }
        if (!$held) {
            if ($worker) {
                $this->release(30);

                return;
            }
            if ($this->job === null) {
                throw new DisplayException(__('A suspension or unsuspension of this service is running on its server: try again in a minute.'));
            }
            Cache::put(self::setAsideKey($this->service->id), true, 600);

            return;
        }
        try {
            $action();
            // A job of this process set aside while this one's call was on its way: done now, as the status says.
            if (Cache::pull(self::setAsideKey($this->service->id)) === true) {
                self::reconcile($this->service, $this->moduleAction());
            }
        } finally {
            $lock->release();
        }
    }

    private static function setAsideKey(int $serviceId): string
    {
        return "service-lifecycle-set-aside:{$serviceId}";
    }

    /**
     * After $done (suspend or unsuspend), the account as the service's status now says: paid meanwhile → unsuspended
     * (and told); suspended again meanwhile → suspended (and told). A refusal is the failure of that action, given up
     * for staff's Retry (never of $done: adversarial ADV-585-4), and thrown as ReconcileFailed.
     */
    public static function reconcile(Service $service, string $done): void
    {
        $now = Service::query()->find($service->id);
        $action = match (true) {
            $done === 'suspend' && $now?->status === Service::STATUS_ACTIVE => 'unsuspend',
            $done === 'unsuspend' && $now?->status === Service::STATUS_SUSPENDED => 'suspend',
            default => null,
        };
        if ($action === null) {
            return;
        }
        try {
            $data = $action === 'unsuspend' ? ExtensionHelper::unsuspendServer($now) : ExtensionHelper::suspendServer($now);
        } catch (NothingToDoOnServer) {
            return;
        } catch (Throwable $e) {
            ModuleAction::giveUp($now, $action, $e);
            NotificationHelper::sendSystemEmailNotification("Module action failed: {$action} of service #{$now->id}", e(
                "The server could not {$action} service #{$now->id} (its status changed while another call of it ran): {$e->getMessage()}. "
                . 'It is listed in Pending Module Actions: retry it there once the cause is fixed, or mark it resolved.'
            ));

            throw new ReconcileFailed($e->getMessage(), 0, $e);
        }
        $action === 'unsuspend'
            ? NotificationHelper::serverUnsuspendedNotification($now->client, $now, is_array($data) ? $data : [])
            : NotificationHelper::serverSuspendedNotification($now->client, $now, is_array($data) ? $data : []);
    }
}
