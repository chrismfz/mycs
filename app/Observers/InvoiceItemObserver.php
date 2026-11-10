<?php

namespace App\Observers;

use App\Events\InvoiceItem as InvoiceItemEvent;
use App\Exceptions\DisplayException;
use App\Models\Credit;
use App\Models\Domain;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Domains\DomainRenewalService;
use App\Services\Ekdosi\EkdosiRequest;
use App\Services\Invoice\LineTax;
use App\Services\Payment\ItemPaymentMethod;

/**
 * A refunded invoice keeps what it charged (PR 2c: final), and so does one Ekdosi issued its document for (issued is
 * final). A paid one no longer does (owner 2026-11-10, docs/staff-freedom.md): staff add, remove or reprice its lines as
 * in WHMCS; it stays paid and its page shows what it then owes or holds over (StaffLineEdit::balanceNotice). Nothing
 * follows on its own: the period a line already gave stays given. Descriptions, periods and applied_at always change.
 */
class InvoiceItemObserver
{
    private const MONEY_FIELDS = ['price', 'quantity', 'reference_type', 'reference_id', 'invoice_id', 'taxed', 'tax_rate', 'tax_reason', 'tax_amount', 'line_total'];

    private static function refuseOnFinalInvoice(mixed $invoiceId): void
    {
        if ($invoiceId !== null && Invoice::query()->whereKey($invoiceId)->value('status') === Invoice::STATUS_REFUNDED) {
            throw new DisplayException(__('A refunded invoice keeps its lines: bill the customer on a new invoice.'));
        }
        // Ekdosi issued its fiscal document (on credit): a line added, repriced or removed would reach no document, the
        // invoice being filed once paid (R2-1/R2-2 of #459). Ekdosi cancels it first.
        if (EkdosiDocument::activeOn($invoiceId)) {
            throw new DisplayException(__('This invoice has a fiscal document from Ekdosi: its lines stay as they are. Cancel the document there first.'));
        }
        // Nor one paid before the cut-over: its document was issued through WHMCS (REV-780-7).
        if (EkdosiDocument::issuedBeforeCutOver($invoiceId)) {
            throw new DisplayException(EkdosiDocument::cutOverRefusal());
        }
        // A credit purchase that gave its credit keeps its lines (REV-780-2): the credit given and what a refund takes
        // back are both read from them. Lowered, a refund would leave credit behind; raised, money paid on it would buy none.
        if (self::gaveCredit($invoiceId)) {
            throw new DisplayException(self::creditGivenRefusal());
        }
    }

    /** It bought account credit and the credit was given (a line of credit applied). */
    public static function gaveCredit(mixed $invoiceId): bool
    {
        return $invoiceId !== null && InvoiceItem::query()->where('invoice_id', $invoiceId)->where('reference_type', Credit::class)->whereNotNull('applied_at')->exists();
    }

    public static function creditGivenRefusal(): string
    {
        return __('This invoice buys account credit and gave it already: its lines keep their amounts. Refund its payment (the credit is taken back), then make a new one with the right amount.');
    }

    /**
     * A credit purchase («Add funds») takes no discount (S6-MONEY-LEDGER-1; as AdjustmentNoteObserver for a note): a
     * negative line next to the credit it buys, or credit bought on an invoice that has one. Its payment would never
     * cover the credit, so it could not become paid. Staff change the amount of its line, or cancel it and make a new one. Only
     * while the credit is still to be given (applied_at empty).
     */
    private static function refuseDiscountOnCreditPurchase(InvoiceItem $line): void
    {
        $others = InvoiceItem::query()->where('invoice_id', $line->invoice_id)->when($line->exists, fn ($query) => $query->whereKeyNot($line->getKey()));
        $discount = bccomp((string) ($line->price ?? 0), '0', 4) < 0;
        $givesCredit = $line->reference_type === Credit::class && $line->applied_at === null;
        if (($discount && ($givesCredit || (clone $others)->where('reference_type', Credit::class)->whereNull('applied_at')->exists()))
            || ($givesCredit && (clone $others)->where('price', '<', 0)->exists())) {
            throw new DisplayException(__('An invoice that buys account credit takes no adjustment note or discount: change the amount of its line instead (or cancel it and make a new one).'));
        }
    }

    /**
     * Handle the InvoiceItem "creating" event.
     */
    public function creating(InvoiceItem $invoice): void
    {
        self::refuseOnFinalInvoice($invoice->invoice_id);
        self::refuseDiscountOnCreditPurchase($invoice);
        // A domain line: a renewal with its period, or a charge (review R6 of #172, F1 of #174).
        DomainRenewalService::prepareLine($invoice);
        // Kept (owner 2026-11-10: what loses money stays): a domain renewal added to a PAID invoice would be renewed at the
        // registry by the cron (DomainRenewalService::waitingLines) with no payment for it. A charge of the domain is fine.
        if ($invoice->reference_type === Domain::class && $invoice->kind === null && $invoice->applied_at === null
            && Invoice::query()->whereKey($invoice->invoice_id)->value('status') === Invoice::STATUS_PAID) {
            throw new DisplayException(__('A domain renewal is not added to a paid invoice: it would renew the domain at the registry with no payment. Bill it on a new invoice.'));
        }
        // Its tax (Δ1): the rate in force now, unless given (an import keeps the one it was issued with).
        if (!$invoice->isDirty('tax_rate')) {
            $invoice->forceFill(LineTax::decide($invoice));
        }
        LineTax::apply($invoice);
        Invoice::refuseOwingWithoutWord($invoice->invoice_id, $invoice->totalCents());
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
        EkdosiRequest::changedInInbox((int) $invoice->invoice_id, 'lines_changed');
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
        // A line repriced (staff waiving its setup fee, a period rebuilt without it) no longer holds the setup fee it
        // recorded: what is in it is not known, so all of it counts as paid for its days, as before the column
        // (PaidForPeriod; review REV2-PC-1). One given with the price stays.
        if ($invoice->isDirty('price') && !$invoice->isDirty('setup_fee') && $invoice->setup_fee !== null) {
            $invoice->setup_fee = null;
        }
        if ($invoice->isDirty(['price', 'quantity', 'taxed', 'tax_rate'])) {
            LineTax::apply($invoice);
        }
        if ($invoice->isDirty(self::MONEY_FIELDS)) {
            self::refuseOnFinalInvoice($invoice->getOriginal('invoice_id'));
            self::refuseOnFinalInvoice($invoice->invoice_id);
            self::refuseDiscountOnCreditPurchase($invoice);
            Invoice::refuseOwingWithoutWord($invoice->invoice_id, $invoice->totalCents() - (int) round((float) $invoice->getOriginal('line_total') * 100));
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
        if ($invoice->wasChanged(['price', 'quantity', 'taxed', 'line_total'])) {
            EkdosiRequest::changedInInbox((int) $invoice->invoice_id, 'lines_changed');
        }
        event(new InvoiceItemEvent\Updated($invoice));
    }

    public function deleting(InvoiceItem $invoice): void
    {
        self::refuseOnFinalInvoice($invoice->invoice_id);
        // Kept (REV-780-1c): the line that gave a service its period is that period's record of what was paid for it. Gone
        // from a paid invoice, the period would read as one paid before MyCS («imported without its lines») and its days
        // left would come back as credit on a downgrade or a cycle change, on top of what «Close as paid» gave back.
        // Staff set its price to 0.00 instead: the same money comes back, and the period stays given, paid for nothing.
        if ($invoice->reference_type === Service::class && $invoice->applied_at !== null && $invoice->period_start !== null && $invoice->period_end !== null
            && Invoice::query()->whereKey($invoice->invoice_id)->value('status') === Invoice::STATUS_PAID) {
            throw new DisplayException(__('This line gave service #:service its period (to :until), which stays given: it is not removed from a paid invoice. Set its price to 0.00 instead.',
                ['service' => $invoice->reference_id, 'until' => $invoice->period_end->format('d/m/Y')]));
        }
    }

    /**
     * Handle the InvoiceItem "deleted" event.
     */
    public function deleted(InvoiceItem $invoice): void
    {
        // Staff took the discount off an upgrade's invoice, its charge left there: the upgrade no longer has one (ADV-685-3).
        // Not when the upgrade's lines go with it (a cancellation keeps what was given, for «Package changes»).
        if ($invoice->kind === InvoiceItem::KIND_DISCOUNT && $invoice->reference_type === ServiceUpgrade::class
            && InvoiceItem::query()->where('invoice_id', $invoice->invoice_id)->where('reference_type', ServiceUpgrade::class)
                ->where('reference_id', $invoice->reference_id)->where(fn ($line) => $line->whereNull('kind')->orWhere('kind', '!=', InvoiceItem::KIND_DISCOUNT))->exists()) {
            ServiceUpgrade::query()->whereKey($invoice->reference_id)->first()?->forceFill(['discount' => null, 'discount_percent' => null])->save();
        }
        EkdosiRequest::forget((int) $invoice->invoice_id, 'lines_changed');
        EkdosiRequest::changedInInbox((int) $invoice->invoice_id, 'lines_changed');
        event(new InvoiceItemEvent\Deleted($invoice));
    }
}
