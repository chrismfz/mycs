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

class TerminateJob implements ShouldQueue
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
        // Every caller ends the service before dispatching: one revived since (staff, a payment) keeps its account,
        // whether this runs late, as a retry or from Pending Module Actions (audit SVC-11).
        if (!$this->service->fresh()?->isEnded()) {
            return;
        }
        $data = [];

        try {
            $data = ExtensionHelper::terminateServer($this->service);
        } catch (NothingToDoOnServer) {
            // No module, or a module without this action: nothing to do (not retried).
        }

        if ($this->sendNotification) {
            NotificationHelper::serverTerminatedNotification($this->service->client, $this->service, is_array($data) ? $data : []);
        }
    }

    protected function moduleAction(): string
    {
        return 'terminate';
    }
}
