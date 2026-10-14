<?php

namespace App\Listeners;

use App\Enums\InvoiceTransactionStatus;
use App\Events\Invoice\Finalized as InvoiceFinalized;
use App\Events\Invoice\Paid as InvoicePaid;
use App\Events\InvoiceTransaction\Created as InvoiceTransactionCreated;
use App\Events\InvoiceTransaction\Updated as InvoiceTransactionUpdated;
use App\Events\Order\Finalized as OrderFinalized;
use App\Events\ServiceCancellation\Created as CancellationCreated;
use App\Events\User\Created as UserCreated;
use App\Helpers\NotificationHelper;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The customer's mails for what happened to their invoices, orders, cancellations and account (audit sweep Σ4).
 *
 * Queued, after the commit (EVT-1/EVT-2): the PDF and the template are made in the worker, so a slow or failing one
 * neither holds the request nor stops what follows the payment; retried with backoff; the job carries the models'
 * ids (SerializesModels), read again when it runs. Rolled back, nothing is queued.
 */
class SendMailListener implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 5;

    /** @var list<int> seconds */
    public array $backoff = [60, 300, 900, 3600];

    /** Its invoice or order deleted before the worker ran: nothing to tell. */
    public bool $deleteWhenMissingModels = true;

    /** Decided on the event as it fired: the worker sees the models as they are by then. */
    public function shouldQueue(InvoiceFinalized|OrderFinalized|UserCreated|CancellationCreated|InvoicePaid|InvoiceTransactionCreated|InvoiceTransactionUpdated $event): bool
    {
        if ($event instanceof InvoiceFinalized || $event instanceof OrderFinalized) {
            return $event->sendEmail !== false;
        }
        if ($event instanceof InvoiceTransactionCreated || $event instanceof InvoiceTransactionUpdated) {
            // Once, when it becomes failed (EVT-14): not again on every later save of a failed transaction.
            $transaction = $event->invoiceTransaction;

            return $transaction->status === InvoiceTransactionStatus::Failed
                && ($event instanceof InvoiceTransactionCreated || $transaction->wasChanged('status'));
        }

        return true;
    }

    public function handle(InvoiceFinalized|OrderFinalized|UserCreated|CancellationCreated|InvoicePaid|InvoiceTransactionCreated|InvoiceTransactionUpdated $event): void
    {
        if ($event instanceof InvoiceFinalized) {
            if ($event->invoice->client !== null) {
                NotificationHelper::invoiceCreatedNotification($event->invoice->client, $event->invoice);
            }
        } elseif ($event instanceof InvoicePaid) {
            // An invoice left without a customer (deleted before money-trail §8) still records its money; no one to mail.
            if ($event->invoice->client !== null) {
                NotificationHelper::invoicePaidNotification($event->invoice->client, $event->invoice);
            }
        } elseif ($event instanceof InvoiceTransactionCreated || $event instanceof InvoiceTransactionUpdated) {
            $invoice = $event->invoiceTransaction->invoice;
            if ($invoice?->client !== null) {
                NotificationHelper::invoicePaymentFailedNotification($invoice->client, $invoice);
            }
        } elseif ($event instanceof UserCreated) {
            NotificationHelper::emailVerificationNotification($event->user);
        } elseif ($event instanceof OrderFinalized) {
            if ($event->order->client !== null) {
                NotificationHelper::orderCreatedNotification($event->order->client, $event->order);
            }
        } elseif ($event instanceof CancellationCreated) {
            $cancellation = $event->cancellation;
            if ($cancellation->service?->client !== null) {
                NotificationHelper::serviceCancellationReceivedNotification($cancellation->service->client, $cancellation);
            }
        }
    }
}
