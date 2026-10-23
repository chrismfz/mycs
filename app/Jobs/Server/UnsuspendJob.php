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

class UnsuspendJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesModuleAction, SerializesModels;

    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(public Service $service) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // A queued action must not undo a newer payment or staff hold.
        if ($this->service->fresh()?->status !== Service::STATUS_ACTIVE) {
            return;
        }
        // Paid before the worker reached its suspension (SuspendJob skipped it): nothing on the server to undo, and no
        // «unsuspended» mail for a suspension the customer never got (review of #411, finding 11).
        if (Cache::pull(SuspendJob::neverSuspendedKey($this->service->id)) === true) {
            return;
        }
        $data = [];
        try {
            $data = ExtensionHelper::unsuspendServer($this->service);
        } catch (NothingToDoOnServer) {
            // No module, or a module without this action: nothing to do (not retried).
        }

        // WHMCS's «Service Unsuspension Notification» (sent at both companies).
        NotificationHelper::serverUnsuspendedNotification($this->service->client, $this->service, is_array($data) ? $data : []);
    }

    protected function moduleAction(): string
    {
        return 'unsuspend';
    }
}
