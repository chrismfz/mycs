<?php

namespace App\Jobs\Server;

use App\Exceptions\NothingToDoOnServer;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

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
        $verdict = $this->readAgain();
        if ($verdict === 'revived') {
            return;
        }
        if ($verdict === 'paid') {
            // Terminated for non-payment, and its payment landed since the cron decided (in the moment between its
            // decision and its cancelling the invoice, or on an invoice kept as a debt while this job waited for the
            // server): the account stays, staff decide (docs/termination.md).
            NotificationHelper::sendSystemEmailNotification("Termination stopped: service #{$this->service->id} was paid", e(
                "Service #{$this->service->id} was terminated for non-payment, and a payment for it arrived before its account was deleted on the server. "
                . 'The account was NOT deleted. Bring the service back (set it Active and its next due date) or, if it is to end, refund or credit the payment and terminate it from the service page.'
            ));

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

    /**
     * «revived»: no longer ended (staff, a payment). «paid»: the cron ended it for non-payment (terminated: every other
     * end is cancelled, or staff's own Terminate, which runs no job) and a paid invoice bills it a period from its due
     * date on, which DunningRules::terminable never lets through: it was paid after that decision. Else «go».
     */
    private function readAgain(): string
    {
        return DB::transaction(function () {
            $service = Service::query()->whereKey($this->service->getKey())->lockForUpdate()->first();
            if ($service === null || !$service->isEnded()) {
                return 'revived';
            }
            $paid = $service->status === Service::STATUS_TERMINATED && $service->expires_at !== null
                && $service->invoiceItems()->where('period_start', '>=', $service->expires_at)
                    ->whereHas('invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_PAID))->exists();

            return $paid ? 'paid' : 'go';
        });
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
