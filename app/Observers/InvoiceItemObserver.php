<?php

namespace App\Observers;

use App\Events\InvoiceItem as InvoiceItemEvent;
use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\InvoiceItem;

/**
 * A paid invoice keeps what it charged (docs/money-trail.md, MT-1c): no line is added, removed or repriced while it is
 * paid, else it stays paid with a balance (and a price lowered to zero just to mark it paid would be raised again).
 * Staff set it back to pending first. Descriptions, periods and applied_at still change.
 */
class InvoiceItemObserver
{
    private const MONEY_FIELDS = ['price', 'quantity', 'reference_type', 'reference_id', 'invoice_id'];

    private static function refuseOnPaidInvoice(mixed $invoiceId): void
    {
        if ($invoiceId !== null && Invoice::query()->whereKey($invoiceId)->value('status') === Invoice::STATUS_PAID) {
            throw new DisplayException(__('A paid invoice keeps its lines: set it back to pending (unpaid) first.'));
        }
    }

    /**
     * Handle the InvoiceItem "creating" event.
     */
    public function creating(InvoiceItem $invoice): void
    {
        self::refuseOnPaidInvoice($invoice->invoice_id);
        event(new InvoiceItemEvent\Creating($invoice));
    }

    /**
     * Handle the InvoiceItem "created" event.
     */
    public function created(InvoiceItem $invoice): void
    {
        event(new InvoiceItemEvent\Created($invoice));
    }

    /**
     * Handle the InvoiceItem "updating" event.
     */
    public function updating(InvoiceItem $invoice): void
    {
        if ($invoice->isDirty(self::MONEY_FIELDS)) {
            self::refuseOnPaidInvoice($invoice->getOriginal('invoice_id'));
            self::refuseOnPaidInvoice($invoice->invoice_id);
        }
        event(new InvoiceItemEvent\Updating($invoice));
    }

    /**
     * Handle the InvoiceItem "updated" event.
     */
    public function updated(InvoiceItem $invoice): void
    {
        event(new InvoiceItemEvent\Updated($invoice));
    }

    public function deleting(InvoiceItem $invoice): void
    {
        self::refuseOnPaidInvoice($invoice->invoice_id);
    }

    /**
     * Handle the InvoiceItem "deleted" event.
     */
    public function deleted(InvoiceItem $invoice): void
    {
        event(new InvoiceItemEvent\Deleted($invoice));
    }
}
