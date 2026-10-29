<?php

namespace App\Admin\Actions;

use App\Enums\AdjustmentNoteStatus;
use App\Exceptions\DisplayException;
use App\Models\AdjustmentNote;
use App\Models\Invoice;
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
    /**
     * Who may void or unvoid this note, from any admin page: staff with the notes' update permission, on a note they
     * wrote while its invoice is not paid (a paid invoice keeps its total), or a refund's note back to active (one
     * voided before MT-1c by mistake); with immutable invoices only while the invoice is pending.
     */
    public static function allowed(AdjustmentNote $note): bool
    {
        $status = $note->invoice?->status;
        $byStaff = ($note->isVoidableByStaff() && !in_array($status, Invoice::FROZEN_STATUSES, true))
            || ($note->is_refund && $note->status === AdjustmentNoteStatus::Voided);

        return $byStaff
            && (bool) auth()->user()?->can('update', $note)
            && (!config('settings.immutable_invoices_enabled') || $status === Invoice::STATUS_UNPAID);
    }

    public static function make(): Action
    {
        return Action::make('void')
            ->visible(fn (AdjustmentNote $record): bool => self::allowed($record))
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
                        if (!self::allowed($note)) {
                            throw new DisplayException(__('This note follows a refund or a cancellation, or its invoice is finalized: it is not voided by hand.'));
                        }
                        $note->update(['status' => $note->status === AdjustmentNoteStatus::Voided
                            ? AdjustmentNoteStatus::Active : AdjustmentNoteStatus::Voided]);
                    });
                } catch (DisplayException $e) {
                    Notification::make()->danger()->title(__('Adjustment note not changed'))->body(e($e->getMessage()))->send();

                    throw new Halt;
                }
                $record->refresh();
                Notification::make()->success()
                    ->title($record->status === AdjustmentNoteStatus::Voided ? __('Adjustment note voided') : __('Adjustment note unvoided'))
                    ->send();
            });
    }
}
