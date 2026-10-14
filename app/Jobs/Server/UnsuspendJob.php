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
