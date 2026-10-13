<?php

namespace App\Listeners;

use App\Events\ServiceCancellation\Created;
use App\Helpers\NotificationHelper;
use App\Jobs\Server\TerminateJob;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\ServiceUpgrade\CancelPendingUpgrades;
use Illuminate\Contracts\Queue\ShouldQueue;

class CancellationCreatedListener implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(Created $event): void
    {
        $service = $event->cancellation->service;
        // A service already ended (an earlier request, the cron, staff) is left as it is: no second stock give-back.
        if (!in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED, Service::STATUS_PENDING], true)) {
            return;
        }

        // Its open invoices, or its lines on an invoice that also bills other services (CancelOpenInvoices). One
        // holding gateway money stays open for staff: the reason is mailed.
        // Queued: the worker keeps the audit on (AppServiceProvider), so these changes are in it too.
        $open = $service->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get();

        if ($event->cancellation->type == 'immediate') {
            if (in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) {
                TerminateJob::dispatch($service);
            }

            $service->update([
                'status' => 'cancelled',
            ]);

            // Its pending upgrades end with it: paid later, an upgrade would change a cancelled service (and give
            // stock back a second time). Their lines go with the service's (an invoice that bills another service too
            // keeps the rest).
            self::report(CancelPendingUpgrades::forService($service, __('invoices.cancellation_reasons.cancellation_request')));

            self::report(CancelOpenInvoices::handle($open, __('invoices.cancellation_reasons.cancellation_request'), $service));

            if ($service->product?->stock !== null) {
                $service->product->increment('stock', $service->quantity);
            }

            return;
        }

        // At the end of the billing period: the cron ends the service on its due date (cancellations_processed). Its
        // open invoices are cancelled now, as WHMCS does with «cancel unpaid invoices on cancellation request» (on at
        // both companies, docs/settings-map.md): nothing left to pay that would renew it by mistake.
        self::report(CancelOpenInvoices::handle($open, __('invoices.cancellation_reasons.cancellation_request'), $service));
    }

    /** @param  array<string, \Throwable>  $left  invoices left open or failed: logged, and mailed to staff */
    private static function report(array $left): void
    {
        foreach ($left as $reason) {
            report($reason);
        }
        if ($left !== []) {
            $lines = e(implode("\n", array_map(fn ($key, $reason) => "{$key}: {$reason->getMessage()}", array_keys($left), $left)));
            NotificationHelper::sendSystemEmailNotification('Cancellation request: needs staff attention', <<<HTML
                A cancellation request left these for staff:<br>
                <pre>{$lines}</pre>
                HTML);
        }
    }
}
