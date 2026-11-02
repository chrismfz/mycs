<?php

namespace App\Jobs\Server;

use App\Exceptions\NothingToDoOnServer;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Service;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;

/**
 * A suspension or unsuspension job runs under its service's lifecycle lock (ExtensionHelper::lifecycleLock, predeploy
 * R2), and once its call is answered it settles the account with the service's status read again: a payment (or the
 * cron) that changed it meanwhile is done now, under the same lock (reconcile). On a worker the job waits for the lock
 * (another call of the service still running), and if it is still held, it is tried again later; in this process (the
 * sync queue, a direct call: the one holding it is this very process, further up) it leaves it to the holder.
 */
trait SerializesLifecycle
{
    protected function serialized(Closure $action): void
    {
        $lock = ExtensionHelper::lifecycleLock($this->service);
        $worker = $this->job !== null && !$this->job instanceof SyncJob;
        try {
            $held = $worker ? $lock->block(150) : $lock->get();
        } catch (LockTimeoutException) {
            $held = false;
        }
        if (!$held) {
            if ($worker) {
                $this->release(30);
            }

            return;
        }
        try {
            $action();
            self::reconcile($this->service);
        } finally {
            $lock->release();
        }
    }

    /**
     * The account as the service's status says, if what MyCS last did on its server says otherwise: paid while being
     * suspended → unsuspended (and told); suspended again while being unsuspended → suspended (and told). Twice at most.
     */
    public static function reconcile(Service $service): void
    {
        for ($round = 0; $round < 2; $round++) {
            $now = Service::query()->find($service->id);
            $on = Cache::get(ExtensionHelper::onServerKey($service->id));
            try {
                if ($now?->status === Service::STATUS_ACTIVE && $on === 'suspended') {
                    $data = ExtensionHelper::unsuspendServer($now);
                    NotificationHelper::serverUnsuspendedNotification($now->client, $now, is_array($data) ? $data : []);
                } elseif ($now?->status === Service::STATUS_SUSPENDED && $on === 'active') {
                    $data = ExtensionHelper::suspendServer($now);
                    NotificationHelper::serverSuspendedNotification($now->client, $now, is_array($data) ? $data : []);
                } else {
                    return;
                }
            } catch (NothingToDoOnServer) {
                return;
            }
        }
    }
}
