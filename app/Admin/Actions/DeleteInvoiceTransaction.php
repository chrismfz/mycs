<?php

namespace App\Admin\Actions;

use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\RecordPaymentService;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/**
 * Staff deleting a transaction, from any admin table: through RecordPaymentService (what became credit is taken
 * back, or the deletion is refused), never a plain or mass delete (docs/payments.md).
 */
class DeleteInvoiceTransaction
{
    /** Immutable invoices: transactions change only while the invoice is pending. */
    public static function allowed(?Invoice $invoice): bool
    {
        return !config('settings.immutable_invoices_enabled') || $invoice?->status === Invoice::STATUS_PENDING;
    }

    public static function run(InvoiceTransaction $record): bool
    {
        try {
            if (!self::allowed($record->invoice)) {
                throw new DisplayException(__('Transactions of a finalized invoice cannot be deleted.'));
            }
            (new RecordPaymentService)->deleteTransaction($record);
        } catch (DisplayException $e) {
            Notification::make()->danger()->title(__('Transaction not deleted'))->body($e->getMessage())->send();

            throw new Halt;
        }

        return true;
    }
}
