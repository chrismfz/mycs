<?php

namespace App\Admin\Resources\InvoiceResource\Pages;

use App\Admin\Resources\InvoiceResource;
use App\Services\Invoice\RecordPaymentService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    /**
     * The record, its lines (or services) and afterCreate in one transaction (review R-1 of Σ4): Finalized fires once
     * it is committed, so its mail, PDF and Discord message have the lines; and a failure leaves nothing half made.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordCreation(array $data): Model
    {
        $invoice = new (static::getModel());
        $invoice->fill($data);
        $invoice->send_create_email = $data['send_email'] ?? true;
        $invoice->save();

        return $invoice;
    }

    /** Written with nothing to pay (a free item): paid now, as WHMCS marks a zero invoice paid (2b). Lines saved first. */
    protected function afterCreate(): void
    {
        (new RecordPaymentService)->settle($this->record->getKey());
    }
}
