<?php

namespace App\Admin\Resources\OrderResource\Pages;

use App\Admin\Resources\OrderResource;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Invoice\RecordPaymentService;
use Filament\Resources\Pages\CreateRecord;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    /**
     * The record, its lines (or services) and afterCreate in one transaction (review R-1 of Σ4): Finalized fires once
     * it is committed, so its mail, PDF and Discord message have the lines; and a failure leaves nothing half made.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function afterCreate(): void
    {
        /** @var Order $order */
        $order = $this->record;
        $invoice = new Invoice([
            'client_id' => $order->client_id,
            'currency_code' => $order->currency_code,
            'due_at' => now()->addDays(7),
            'status' => config('settings.immutable_invoices_enabled', false) ? Invoice::STATUS_DRAFT : Invoice::STATUS_UNPAID,
        ]);
        $invoice->save();

        foreach ($this->record->services as $service) {
            $invoice->items()->create([
                'description' => $service->description,
                'price' => $service->price,
                'quantity' => $service->quantity,
                'reference_id' => $service->id,
                'reference_type' => get_class($service),
            ]);
        }

        // Nothing to pay (a free service, a .gr transfer): paid now, as WHMCS does, so the service goes ahead (2b).
        (new RecordPaymentService)->settle($invoice);
    }
}
