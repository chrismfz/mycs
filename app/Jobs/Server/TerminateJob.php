<?php

namespace App\Jobs\Server;

use App\Exceptions\NothingToDoOnServer;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\ModuleAction;
use App\Models\Service;
use App\Services\Service\DunningRules;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
        $this->taken();
        // Every caller ends the service before dispatching: one revived since (staff, a payment) keeps its account,
        // whether this runs late, as a retry or from Pending Module Actions (audit SVC-11).
        // Read again under the service's lock, right before the module call: what it deletes cannot be brought back.
        $stop = $this->readAgain();
        if ($stop === '') {
            return; // no longer ended (staff, a payment): its account stays, nobody to tell
        }
        if ($stop !== null) {
            $this->stop($stop);

            return;
        }
        $data = [];

        $this->keepingOptions(function () use (&$data) {
            try {
                $data = ExtensionHelper::terminateServer($this->service);
            } catch (NothingToDoOnServer) {
                // No module, or a module without this action: nothing to do (not retried).
            }
        });

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

    /** The start of last_error of a termination its job stopped: staff see it in Pending Module Actions, told once. */
    public const STOPPED = 'Stopped: ';

    /**
     * Null: go. «»: no longer ended. Else why it stops. A cancelled service ended because its customer or staff asked:
     * it goes. A terminated one is taken for the cron's end for non-payment (staff's own Terminate runs no job; the few
     * others, a status set by hand with an old row retried, are held to the same rule: the safe side, and the mail says
     * how to delete all the same): the cron's rule is asked again as of today (DunningRules::terminationStands: the
     * switch, Termination Days, a hold, an exemption, a payment on its way, money since its due date), so the cron and
     * this job never disagree, also on a Retry days later. Not its days suspended: the mark went when it ended.
     */
    private function readAgain(): ?string
    {
        return DB::transaction(function () {
            $service = Service::query()->whereKey($this->service->getKey())->lockForUpdate()->first();
            if ($service === null || !$service->isEnded()) {
                return '';
            }
            if ($service->status !== Service::STATUS_TERMINATED || DunningRules::terminationStands($service)) {
                return null;
            }

            return DunningRules::paidSinceDue($service)
                ? 'money came for it since its due date (a payment, a part payment, or one still on its way).'
                : 'it is no longer due for termination (terminations switched off, a hold, an exemption, its due date or Termination Days changed, a later period paid).';
        });
    }

    /**
     * Not deleted: an open row in Pending Module Actions says why (given up: no queue retry), and staff are mailed once,
     * when the row first says so; a Retry that stops again finds it and says nothing more.
     */
    private function stop(string $why): void
    {
        $id = $this->service->id;
        $told = ModuleAction::query()->open()->where('service_id', $id)->where('action', 'terminate')->whereNull('subject')
            ->where('last_error', 'like', self::STOPPED . '%')->exists();
        if ($told) {
            return;
        }
        ModuleAction::recordFailure($this->service, 'terminate', $this->service->provisioningServer()?->extension, new RuntimeException(self::STOPPED . $why))
            ->forceFill(['given_up_at' => now()])->save();
        $this->keepOptions();
        NotificationHelper::sendSystemEmailNotification("Termination stopped: service #{$id}", e(
            "Service #{$id} was terminated for non-payment, but before its account was deleted on the server: {$why} "
            . "The account was NOT deleted; the service is still «Terminated» here and is listed in Pending Module Actions.\n"
            . "To keep it: on the service's page, «Revive: paid after termination». It sets the service Active (with a hold of Suspend Days if it is still past due), "
            . 'gives it the period of each paid invoice not applied yet, asks the server to unsuspend the account and closes the pending row. '
            . "By hand after it: addons that ended with it (set each back to Active), and its product's stock if it is counted.\n"
            . 'To end it all the same: refund or credit what was paid, then «Module command… → Terminate server» on the service\'s page, and mark the pending row resolved.'
        ));
    }

    /** @return array<string, bool> */
    protected function jobOptions(): array
    {
        return ['send_notification' => (bool) $this->sendNotification];
    }

    protected function moduleAction(): string
    {
        return 'terminate';
    }
}
