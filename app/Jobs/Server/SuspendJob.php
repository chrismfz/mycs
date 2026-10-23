<?php

namespace App\Jobs\Server;

use App\Exceptions\NothingToDoOnServer;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\ModuleAction;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class SuspendJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesModuleAction, SerializesModels;

    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(public Service $service, public $sendNotification = true) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // A queued action must not undo a newer payment or staff hold. Never done on the server (its first attempt, no
        // failure of an earlier one open: an earlier attempt may have suspended with its answer lost, review of #418):
        // the customer never heard of a suspension, so the payment's unsuspension tells them nothing either.
        if ($this->service->fresh()?->status !== Service::STATUS_SUSPENDED) {
            if ($this->attempts() <= 1 && !ModuleAction::query()->open()->where('service_id', $this->service->id)->where('action', 'suspend')->exists()) {
                Cache::put(self::neverSuspendedKey($this->service->id), true, now()->addDay());
            }

            return;
        }
        $data = [];

        try {
            $data = ExtensionHelper::suspendServer($this->service);
        } catch (NothingToDoOnServer) {
            // No module, or a module without this action: nothing to do (not retried).
        }

        if ($this->sendNotification) {
            NotificationHelper::serverSuspendedNotification($this->service->client, $this->service, is_array($data) ? $data : []);
        }
    }

    /** A suspension skipped by its job because the service was active again (UnsuspendJob reads it). */
    public static function neverSuspendedKey(int $serviceId): string
    {
        return "service-suspension-skipped:{$serviceId}";
    }

    protected function moduleAction(): string
    {
        return 'suspend';
    }
}
