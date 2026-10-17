<?php

namespace App\Observers;

use App\Events\InvoiceItem as InvoiceItemEvent;
use App\Exceptions\DisplayException;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Domains\DomainRenewalService;
use App\Services\Invoice\LineTax;

/**
 * A paid invoice keeps what it charged (docs/money-trail.md, MT-1c): no line is added, removed or repriced while it is
 * paid, else it stays paid with a balance (and a price lowered to zero just to mark it paid would be raised again).
 * Staff set it back to unpaid first. Descriptions, periods and applied_at still change.
 */
class InvoiceItemObserver
{
    private const MONEY_FIELDS = ['price', 'quantity', 'reference_type', 'reference_id', 'invoice_id', 'taxed', 'tax_rate', 'tax_reason', 'tax_amount', 'line_total'];

    private static function refuseOnPaidInvoice(mixed $invoiceId): void
    {
        if ($invoiceId !== null && in_array(Invoice::query()->whereKey($invoiceId)->value('status'), Invoice::FROZEN_STATUSES, true)) {
            throw new DisplayException(__('A paid invoice keeps its lines: set it back to unpaid first.'));
        }
    }

    /**
     * Handle the InvoiceItem "creating" event.
     */
    public function creating(InvoiceItem $invoice): void
    {
        self::refuseOnPaidInvoice($invoice->invoice_id);
        // A domain line: a renewal with its period, or a charge (review R6 of #172, F1 of #174).
        DomainRenewalService::prepareLine($invoice);
        // Its tax (Δ1): the rate in force now, unless given (an import keeps the one it was issued with).
        if (!$invoice->isDirty('tax_rate')) {
            $invoice->forceFill(LineTax::decide($invoice));
        }
        LineTax::apply($invoice);
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
        // Taxed or not changed: the rate is decided again; else the line keeps its rate and only its amounts follow.
        // What a line is decides what its payment does (a renewal at the registrar, F2 of #174): set when made. Only a
        // stage fee becomes the next stage's; a domain line keeps its domain (F4: its period is that domain's).
        if ($invoice->isDirty('kind') && !(in_array($invoice->getOriginal('kind'), InvoiceItem::STAGE_FEES, true)
            && in_array($invoice->kind, InvoiceItem::STAGE_FEES, true))) {
            throw new DisplayException(__('A line keeps what it is (a renewal, a fee, a charge): make a new line.'));
        }
        if ($invoice->isDirty(['reference_type', 'reference_id'])
            && ($invoice->getOriginal('reference_type') === Domain::class || $invoice->reference_type === Domain::class)) {
            throw new DisplayException(__('A domain line keeps its domain: make a new line.'));
        }
        if ($invoice->isDirty('taxed') && !$invoice->isDirty('tax_rate')) {
            $invoice->forceFill(LineTax::decide($invoice));
        }
        if ($invoice->isDirty(['price', 'quantity', 'taxed', 'tax_rate'])) {
            LineTax::apply($invoice);
        }
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
