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
        // Paid before the worker reached its suspension (SuspendJob skipped it): no «unsuspended» mail for a suspension the
        // customer never got (review of #411, finding 11). The server is asked all the same: a mark is no proof, and an
        // account left suspended while MyCS says active is far worse than a needless call (review of #418).
        // Read now, cleared once the server has answered: a retry after a failure still knows (combined review).
        $neverSuspended = Cache::get(SuspendJob::neverSuspendedKey($this->service->id)) === true;
        $data = [];
        try {
            $data = ExtensionHelper::unsuspendServer($this->service);
        } catch (NothingToDoOnServer) {
            // No module, or a module without this action: nothing to do (not retried).
        }

        Cache::forget(SuspendJob::neverSuspendedKey($this->service->id));

        // WHMCS's «Service Unsuspension Notification» (sent at both companies).
        if (!$neverSuspended) {
            NotificationHelper::serverUnsuspendedNotification($this->service->client, $this->service, is_array($data) ? $data : []);
        }
    }

    protected function moduleAction(): string
    {
        return 'unsuspend';
    }
}
