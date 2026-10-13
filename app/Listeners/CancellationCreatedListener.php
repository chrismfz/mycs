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
        if ($event->cancellation->type == 'immediate') {
            if (in_array($event->cancellation->service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])) {
                TerminateJob::dispatch($event->cancellation->service);
            }

            $event->cancellation->service->update([
                'status' => 'cancelled',
            ]);

            CancelOpenInvoices::handle($event->cancellation->service->invoices()->where('invoices.status', Invoice::STATUS_PENDING)->get());

            if ($event->cancellation->service->product?->stock !== null) {
                $event->cancellation->service->product->increment('stock', $event->cancellation->service->quantity);
            }

            return;
        }

        // At the end of the billing period: the cron ends the service on its due date (cancellations_processed). Its
        // open invoices are cancelled now, as WHMCS does: nothing left to pay that would renew it by mistake.
        CancelOpenInvoices::handle($event->cancellation->service->invoices()->where('invoices.status', Invoice::STATUS_PENDING)->get());
    }
}
