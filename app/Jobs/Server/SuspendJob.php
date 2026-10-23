<?php

namespace App\Jobs\Server;

use App\Exceptions\NothingToDoOnServer;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
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
        // A queued action must not undo a newer payment or staff hold. Never done on the server: the unsuspension queued
        // after it (the payment) has nothing to undo and nothing to tell the customer, who never heard of a suspension.
        if ($this->service->fresh()?->status !== Service::STATUS_SUSPENDED) {
            Cache::put(self::neverSuspendedKey($this->service->id), true, now()->addDay());

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
