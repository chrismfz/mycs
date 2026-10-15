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

        if (!$this->sendNotification) {
            return;
        }
        // Ended because its customer asked: an immediate request was already told it ends now (its confirmation), the
        // end of the period is told so; any other end is «terminated» (sweep modules §21).
        // Only when it ended as cancelled (the request's paths): a terminated one ended for another reason (non-payment,
        // staff), whatever request a revived service still has on file (review 2).
        $requested = $this->service->fresh()->status === Service::STATUS_CANCELLED ? $this->service->cancellation()->latest('id')->value('type') : null;
        if ($requested === 'immediate') {
            return;
        }
        $requested === 'end_of_period'
            ? NotificationHelper::serviceCancelledNotification($this->service->client, $this->service, is_array($data) ? $data : [])
            : NotificationHelper::serverTerminatedNotification($this->service->client, $this->service, is_array($data) ? $data : []);
    }

    protected function moduleAction(): string
    {
        return 'terminate';
    }
}
