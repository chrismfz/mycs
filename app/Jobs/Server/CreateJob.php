<?php

namespace App\Jobs\Server;

use App\Exceptions\CreateSkipped;
use App\Exceptions\NothingToDoOnServer;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Creates the account on the service's server (Σ1): the service turns active, and the welcome mail goes, only once the
 * account exists. Retried on failure (RetriesModuleAction); one at a time per service (a second payment, a staff click),
 * and only while the service is still pending: one started by staff or a retry meanwhile, or ended, is left alone.
 */
class CreateJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesModuleAction, SerializesModels;

    public $timeout = 120;

    /** Held across the retries (the last is an hour after the third). */
    public $uniqueFor = 7200;

    /**
     * Create a new job instance.
     */
    public function __construct(public Service $service, public $sendNotification = true) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
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
            NotificationHelper::serverCreatedNotification($service->client, $service, is_array($data) ? $data : []);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->service->id;
    }

    protected function moduleAction(): string
    {
        return 'create';
    }
}
