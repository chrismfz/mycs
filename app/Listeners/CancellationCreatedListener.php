<?php

namespace App\Listeners;

use App\Events\ServiceCancellation\Created;
use App\Jobs\Server\TerminateJob;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Invoice\CancelOpenInvoices;
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

        // Invoices that bill only this service; one also billing something else, or holding gateway money, stays
        // open for staff (CancelOpenInvoices: the reason is in the log).
        $open = $service->invoices()->where('invoices.status', Invoice::STATUS_PENDING)->get();

        if ($event->cancellation->type == 'immediate') {
            if (in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) {
                TerminateJob::dispatch($service);
            }

            $service->update([
                'status' => 'cancelled',
            ]);

            self::report(CancelOpenInvoices::handle($open, onlyFor: $service));

            if ($service->product?->stock !== null) {
                $service->product->increment('stock', $service->quantity);
            }

            return;
        }

        // At the end of the billing period: the cron ends the service on its due date (cancellations_processed). Its
        // open invoices are cancelled now, as WHMCS does with «cancel unpaid invoices on cancellation request» (on at
        // both companies, docs/settings-map.md): nothing left to pay that would renew it by mistake.
        self::report(CancelOpenInvoices::handle($open, onlyFor: $service));
    }

    /** @param  array<string, \Throwable>  $left  invoices left open or failed: logged for staff */
    private static function report(array $left): void
    {
        foreach ($left as $reason) {
            report($reason);
        }
    }
}
