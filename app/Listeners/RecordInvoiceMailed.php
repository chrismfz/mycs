<?php

namespace App\Listeners;

use App\Events\Invoice\Finalized;
use App\Services\Invoice\PaymentReminders;

/**
 * An invoice announced with its mail (made, published, sent again) is mailed today: no «due soon» reminder the same day,
 * whatever date it carries (adversarial R2-1/R2-2 of #367). Recorded as the event fires, after the commit; the mail
 * itself goes from the queue (SendMailListener).
 */
class RecordInvoiceMailed
{
    public function handle(Finalized $event): void
    {
        if ($event->sendEmail !== false && $event->invoice->client_id !== null) {
            PaymentReminders::mailedToday($event->invoice);
        }
    }
}
