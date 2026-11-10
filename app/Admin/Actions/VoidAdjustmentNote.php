<?php

namespace App\Admin\Actions;

use App\Enums\AdjustmentNoteStatus;
use App\Exceptions\DisplayException;
use App\Models\AdjustmentNote;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\StaffLineEdit;
use App\Services\Invoice\UndoRefund;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\DB;

/**
 * Void or unvoid an adjustment note staff wrote (docs/money-trail.md, MT-1c), and since 2026-11-10 edit or delete one
 * (correct()). Each moves the invoice's total, so it runs under the payments' locks (customer, invoice).
 */
class VoidAdjustmentNote
{
    /**
     * Who may void or unvoid this note, from any admin page: staff with the notes' update permission, on a note they
     * wrote while its invoice is not refunded (a refunded invoice keeps its total), or a refund's note back to active
     * (one voided before MT-1c by mistake).
     */
    public static function allowed(AdjustmentNote $note): bool
    {
        return self::voidable($note) && (bool) auth()->user()?->can('update', $note);
    }

    /** The rule of allowed() alone: whoever asks is checked by the caller (the panel's policy, the Admin API key's list). */
    public static function voidable(AdjustmentNote $note): bool
    {
        $status = $note->invoice?->status;

        return ($note->isVoidableByStaff() && $status !== Invoice::STATUS_REFUNDED)
            || ($note->is_refund && $note->status === AdjustmentNoteStatus::Voided && !UndoRefund::undone($note));
    }

    /**
     * Who may edit or delete this note (owner 2026-11-10): staff with the permission, on a note they wrote, while its
     * invoice is not refunded and has no fiscal document from Ekdosi (AdjustmentNoteObserver refuses the rest).
     */
    public static function correctable(AdjustmentNote $note, string $ability): bool
    {
        return self::editable($note) && (bool) auth()->user()?->can($ability, $note);
    }

    /** The rule of correctable() alone, as voidable(). */
    public static function editable(AdjustmentNote $note): bool
    {
        return $note->isVoidableByStaff() && $note->invoice?->status !== Invoice::STATUS_REFUNDED
            && !EkdosiDocument::activeOn($note->invoice_id) && !EkdosiDocument::issuedBeforeCutOver($note->invoice_id);
    }

    /**
     * An edit or a deletion of a note, as a void: under the payments' locks (customer, invoice), the note read again
     * there, the model's refusals told as a notice.
     *
     * @param  \Closure(AdjustmentNote): mixed  $change
     */
    public static function correct(AdjustmentNote $record, string $ability, \Closure $change): void
    {
        try {
            self::change($record, $change, fn (AdjustmentNote $note): bool => self::correctable($note, $ability));
        } catch (DisplayException $e) {
            Notification::make()->danger()->title(__('Adjustment note not changed'))->body(e($e->getMessage()))->send();

            throw new Halt;
        }
        self::tellBalance($record->invoice_id);
    }

    /**
     * The edit or deletion itself, for the panel (correct) and the Admin API: one transaction, one rule.
     *
     * @param  \Closure(AdjustmentNote): mixed  $change
     * @param  \Closure(AdjustmentNote): bool  $may  asked of the note read under the locks
     *
     * @throws DisplayException nothing changed
     */
    public static function change(AdjustmentNote $record, \Closure $change, \Closure $may): void
    {
        DB::transaction(function () use ($record, $change, $may) {
            (new RecordPaymentService)->lockInvoice($record->invoice_id);
            $note = AdjustmentNote::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if (!$may($note)) {
                throw new DisplayException(__('This note follows a refund or a cancellation, or its invoice is refunded or has a fiscal document from Ekdosi: it stays as it is.'));
            }
            $change($note);
        });
    }

    /**
     * Void an active note or unvoid a voided one, for the panel's action and the Admin API ($to: only towards that
     * status; a note already there stays as it is).
     *
     * @param  \Closure(AdjustmentNote): bool  $may  asked of the note read under the locks
     *
     * @throws DisplayException nothing changed
     */
    public static function toggle(AdjustmentNote $record, \Closure $may, ?AdjustmentNoteStatus $to = null): void
    {
        DB::transaction(function () use ($record, $may, $to) {
            (new RecordPaymentService)->lockInvoice($record->invoice_id);
            $note = AdjustmentNote::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($note->status === $to) {
                return;
            }
            if (!$may($note)) {
                throw new DisplayException(__('This note follows a refund or a cancellation, or its invoice is finalized: it is not voided by hand.'));
            }
            $note->update(['status' => $note->status === AdjustmentNoteStatus::Voided
                ? AdjustmentNoteStatus::Active : AdjustmentNoteStatus::Voided]);
        });
    }

    /** After a note changed what a paid invoice bills: it owes on while paid, or is paid over (owner 2026-11-10). */
    public static function tellBalance(mixed $invoiceId): void
    {
        if (($notice = StaffLineEdit::balanceNotice($invoiceId)) !== null) {
            Notification::make()->warning()->title(__('Check its balance'))->body(e($notice))->persistent()->send();
        }
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
                    self::toggle($record, self::allowed(...));
                } catch (DisplayException $e) {
                    Notification::make()->danger()->title(__('Adjustment note not changed'))->body(e($e->getMessage()))->send();

                    throw new Halt;
                }
                $record->refresh();
                self::tellBalance($record->invoice_id);
                Notification::make()->success()
                    ->title($record->status === AdjustmentNoteStatus::Voided ? __('Adjustment note voided') : __('Adjustment note unvoided'))
                    ->send();
            });
    }
}
