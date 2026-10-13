<?php

namespace App\Admin\Actions;

use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\RecordPaymentService;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/**
 * Staff deleting a transaction, from any admin table: through RecordPaymentService (what became credit is taken
 * back, or the deletion is refused), never a plain or mass delete (docs/payments.md). A single deletion may leave the
 * credit negative when staff confirm it; a bulk one never does.
 */
class DeleteInvoiceTransaction
{
    /** Immutable invoices: transactions change only while the invoice is pending. */
    public static function allowed(?Invoice $invoice): bool
    {
        return !config('settings.immutable_invoices_enabled') || $invoice?->status === Invoice::STATUS_UNPAID;
    }

    /** The confirmation a single deletion asks for when the credit this payment gave is already spent (MT-1c). */
    public static function negativeCreditField(): Checkbox
    {
        return Checkbox::make('allow_negative_credit')
            ->label(__('If the customer already used the credit this payment gave, delete it anyway: their credit goes negative (they owe it).'));
    }

    public static function run(InvoiceTransaction $record, bool $allowNegativeCredit = false): bool
    {
        try {
            if (!self::allowed($record->invoice)) {
                throw new DisplayException(__('Transactions of a finalized invoice cannot be deleted.'));
            }
            (new RecordPaymentService)->deleteTransaction($record, $allowNegativeCredit);
        } catch (DisplayException $e) {
            Notification::make()->danger()->title(__('Transaction not deleted'))->body($e->getMessage())->send();

            throw new Halt;
        }

        return true;
    }
}
