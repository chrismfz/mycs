<?php

namespace App\Admin\Actions;

use App\Enums\AdjustmentNoteStatus;
use App\Exceptions\DisplayException;
use App\Models\AdjustmentNote;
use App\Services\Invoice\RecordPaymentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\DB;

/**
 * Void or unvoid an adjustment note staff wrote (docs/money-trail.md, MT-1c): the only change a note takes. It moves
 * the invoice's total, so it runs under the payments' locks (customer, invoice).
 */
class VoidAdjustmentNote
{
    public static function make(): Action
    {
        return Action::make('void')
            ->label(fn (AdjustmentNote $record): string => $record->status === AdjustmentNoteStatus::Voided ? __('Unvoid') : __('Void'))
            ->color(fn (AdjustmentNote $record): string => $record->status === AdjustmentNoteStatus::Voided ? 'success' : 'danger')
            ->icon(fn (AdjustmentNote $record): string => $record->status === AdjustmentNoteStatus::Voided ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-x-circle')
            ->requiresConfirmation()
            ->modalDescription(fn (AdjustmentNote $record): string => $record->status === AdjustmentNoteStatus::Voided
                ? __('Its amount counts in the invoice total again.')
                : __('Its amount no longer counts in the invoice total. The note stays, marked voided.'))
            ->action(function (AdjustmentNote $record): void {
                try {
                    DB::transaction(function () use ($record) {
                        (new RecordPaymentService)->lockInvoice($record->invoice_id);
                        $note = AdjustmentNote::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                        if (!$note->isVoidableByStaff()) {
                            throw new DisplayException(__('This note follows a refund or a cancellation: it is not voided by hand.'));
                        }
                        $note->update(['status' => $note->status === AdjustmentNoteStatus::Voided
                            ? AdjustmentNoteStatus::Active : AdjustmentNoteStatus::Voided]);
                    });
                } catch (DisplayException $e) {
                    Notification::make()->danger()->title(__('Adjustment note not changed'))->body($e->getMessage())->send();

                    throw new Halt;
                }
                $record->refresh();
                Notification::make()->success()
                    ->title($record->status === AdjustmentNoteStatus::Voided ? __('Adjustment note voided') : __('Adjustment note unvoided'))
                    ->send();
            });
    }
}
