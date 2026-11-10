<?php

namespace App\Admin\Actions;

use App\Exceptions\DisplayException;
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
    /** The confirmation a single deletion asks for when the credit this payment gave is already spent (MT-1c). */
    public static function negativeCreditField(): Checkbox
    {
        return Checkbox::make('allow_negative_credit')
            ->label(__('If the customer already used the credit this payment gave, delete it anyway: their credit goes negative (they owe it).'));
    }

    public static function run(InvoiceTransaction $record, bool $allowNegativeCredit = false): bool
    {
        try {
            (new RecordPaymentService)->deleteTransaction($record, $allowNegativeCredit);
        } catch (DisplayException $e) {
            Notification::make()->danger()->title(__('Transaction not deleted'))->body(e($e->getMessage()))->send();

            throw new Halt;
        }

        return true;
    }
}
