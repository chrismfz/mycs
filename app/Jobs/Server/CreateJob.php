<?php

namespace App\Jobs\Server;

use App\Exceptions\CreateSkipped;
use App\Exceptions\NothingToDoOnServer;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Creates the account on the service's server (Σ1): the service turns active, and the welcome mail goes, only once the
 * account exists. Retried on failure (RetriesModuleAction); one at a time per service (a second payment, a staff click:
 * the create's own lock in ExtensionHelper::createServer), and only while the service is still pending, read again under
 * that lock: one started by staff or a retry meanwhile, or ended, is left alone. Not unique on the queue: a second job is
 * harmless, and without a unique lock it is written in the payment's own transaction on the database queue, never lost
 * between its commit and the queue (AfterCommit::job, predeploy S4).
 */
class CreateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesModuleAction, SerializesModels;

    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    /** $byStaff: staff's «Accept Order» with «Send welcome email»: sent whatever the contact takes (ADV-545-3). */
    public function __construct(public Service $service, public $sendNotification = true, public bool $byStaff = false) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->taken();
        $service = $this->service->fresh();
        if ($service?->status !== Service::STATUS_PENDING) {
            return;
        }
        $data = [];
        // $data is the data that will be used to send the email, data is coming from the extension itself
        try {
            $data = ExtensionHelper::createServer($service, whilePending: true);
        } catch (NothingToDoOnServer) {
            // No module to make an account on (its server detached since the payment): nothing to wait for.
            ExtensionHelper::startCreated($service);
        } catch (CreateSkipped) {
            return; // another create of it is running, or it moved on: that one sends what is due
        }

        // Ended while the account was being made (an immediate cancellation saw it pending and queued no terminate):
        // the new account goes, and no welcome mail with its password (adversarial A4).
        $service = $service->fresh() ?? $service;
        if ($service->isEnded()) {
            TerminateJob::dispatch($service, false);

            return;
        }

        if ($this->sendNotification) {
            NotificationHelper::serverCreatedNotification($service->client, $service, is_array($data) ? $data : [], byStaff: $this->byStaff);
        }
    }

    protected function moduleAction(): string
    {
        return 'create';
    }
}
