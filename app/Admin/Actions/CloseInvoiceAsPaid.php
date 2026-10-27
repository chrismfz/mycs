<?php

namespace App\Admin\Actions;

use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\RecordPaymentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;

/**
 * «Close as paid» (PR 2b, docs/payments.md): an open invoice its payments already cover, after staff took a line off
 * or wrote a discount. Staff close it when they are done editing (an edit never closes it on its own, as in WHMCS); the
 * part of its payments over its total becomes the customer's account credit. A zero invoice the app makes is closed
 * on its own (RecordPaymentService::settle).
 */
class CloseInvoiceAsPaid
{
    public static function make(): Action
    {
        return Action::make('closeAsPaid')
            ->label(__('Close as paid'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            // Only when it would close: covered, and nothing that leaves it to staff (RecordPaymentService::settleRefusal).
            ->visible(function (Invoice $record): bool {
                $fresh = $record->fresh(['items', 'transactions', 'adjustmentNotes']);

                return $fresh !== null && $fresh->status === Invoice::STATUS_UNPAID && RecordPaymentService::settleRefusal($fresh) === null;
            })
            // It can move money to credit: the permission «Add Payment» needs too, not only the invoice's.
            ->authorize(function (Invoice $record): bool {
                $user = auth()->user();

                return $user !== null && $user->can('update', $record) && $user->can('create', InvoiceTransaction::class);
            })
            ->requiresConfirmation()
            ->modalDescription(function (Invoice $record): string {
                $over = -RecordPaymentService::toCents($record->remaining);

                return $over > 0
                    ? __('Its payments cover it. It becomes paid, its services are renewed, and :amount :currency paid over its total becomes the customer\'s account credit.', [
                        'amount' => number_format($over / 100, 2, '.', ''), 'currency' => $record->currency_code,
                    ])
                    : __('Nothing is left to pay on it. It becomes paid and its services are renewed.');
            })
            ->action(function (Invoice $record, $livewire): void {
                try {
                    $closed = (new RecordPaymentService)->settle($record);
                } catch (DisplayException $e) {
                    Notification::make()->danger()->title(__('Invoice not closed'))->body($e->getMessage())->send();

                    throw new Halt;
                }
                if (!$closed) {
                    $fresh = $record->fresh(['items', 'transactions', 'adjustmentNotes']);
                    Notification::make()->danger()->title(__('Invoice not closed'))
                        ->body($fresh === null || $fresh->status !== Invoice::STATUS_UNPAID ? __('Its status changed meanwhile: reload it.')
                            : (RecordPaymentService::settleRefusal($fresh) ?? __('Its status changed meanwhile: reload it.')))->send();

                    throw new Halt;
                }
                $record->refresh();
                // The edit page's form still holds the old status: a later Save would write it back.
                if ($livewire instanceof EditRecord) {
                    $livewire->refreshFormData(['status']);
                }
                // The tables below (transactions, movements) are their own components: they show it at once.
                $livewire->dispatch(TransactionsRelationManager::PAYMENTS_CHANGED);
                Notification::make()->success()->title(__('Invoice paid'))->send();
            });
    }
}
