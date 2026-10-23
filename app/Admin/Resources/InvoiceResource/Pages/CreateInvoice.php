<?php

namespace App\Admin\Resources\InvoiceResource\Pages;

use App\Admin\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\Invoice\RecordPaymentService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
        $invoice = new Invoice;
        $invoice->fill($data);
        $invoice->send_create_email = $data['send_email'] ?? true;
        // Its issue date as staff chose it (INV-17), never after today; today's keeps the time it is made.
        $issued = EditInvoice::issuedAt($data['created_at'] ?? null);
        if ($issued !== null) {
            $invoice->created_at = $issued;
        }
        $invoice->save();
        // Dated before today but mailed today: its «due soon» reminder, which the cron would send today as well, counts
        // as sent with it (adversarial ADV-2; PaymentReminders skips an invoice issued today for that reason).
        $before = (int) config('settings.cronjob_payment_reminder', 6);
        if ($issued !== null && $issued->isBefore(today()) && $invoice->send_create_email && $invoice->status === Invoice::STATUS_UNPAID
            && $before > 0 && $invoice->due_at !== null && $invoice->due_at->isAfter(today()) && $invoice->due_at->lte(today()->addDays($before))) {
            DB::table('invoice_reminders')->insertOrIgnore(['invoice_id' => $invoice->id, 'kind' => 'due', 'days' => 0,
                'due_on' => $invoice->due_at->toDateString(), 'sent_at' => now()]);
        }

        return $invoice;
    }

    /** Written with nothing to pay (a free item): paid now, as WHMCS marks a zero invoice paid (2b). Lines saved first. */
    protected function afterCreate(): void
    {
        (new RecordPaymentService)->settle($this->record->getKey());
    }
}
