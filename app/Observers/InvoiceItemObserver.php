<?php

namespace App\Observers;

use App\Events\InvoiceItem as InvoiceItemEvent;
use App\Exceptions\DisplayException;
use App\Models\Domain;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Services\Domains\DomainRenewalService;
use App\Services\Ekdosi\EkdosiRequest;
use App\Services\Invoice\LineTax;
use App\Services\Payment\ItemPaymentMethod;

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
        // Ekdosi issued its fiscal document (on credit): a line added, repriced or removed would reach no document, the
        // invoice being filed once paid (R2-1/R2-2 of #459). Ekdosi cancels it first.
        if (EkdosiDocument::activeOn($invoiceId)) {
            throw new DisplayException(__('This invoice has a fiscal document from Ekdosi: its lines stay as they are. Cancel the document there first.'));
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
        self::recordWay($invoice);
        event(new InvoiceItemEvent\Creating($invoice));
    }

    /**
     * A service's or domain's line records the item's way as billed (#541), however it is made (the cron, staff's API,
     * a form): a saved method of another gateway never charges its invoice. One given (an import) stays.
     */
    private static function recordWay(InvoiceItem $line): void
    {
        if ($line->isDirty('payment_gateway_id') || !in_array($line->reference_type, [Service::class, Domain::class], true) || $line->reference_id === null) {
            return;
        }
        $item = $line->reference_type::query()->find($line->reference_id);
        $line->payment_gateway_id = $item === null ? null : ItemPaymentMethod::live($item);
    }

    /**
     * Handle the InvoiceItem "created" event.
     */
    public function created(InvoiceItem $invoice): void
    {
        // A request to Ekdosi (E5) was for the lines as they stood: another line, staff send it again (R2-3 of #478).
        EkdosiRequest::forget((int) $invoice->invoice_id, 'lines_changed');
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
        if ($invoice->isDirty(['reference_type', 'reference_id'])) {
            self::recordWay($invoice);
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
        if ($invoice->wasChanged([...self::MONEY_FIELDS, 'description', 'invoice_id'])) {
            EkdosiRequest::forget((int) $invoice->invoice_id, 'lines_changed');
            if ($invoice->wasChanged('invoice_id')) {
                EkdosiRequest::forget((int) $invoice->getOriginal('invoice_id'), 'lines_changed');
            }
        }
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
        EkdosiRequest::forget((int) $invoice->invoice_id, 'lines_changed');
        event(new InvoiceItemEvent\Deleted($invoice));
    }
}
