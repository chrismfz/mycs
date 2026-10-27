<?php

namespace App\Admin\Actions;

use App\Models\Credit;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * «Send to Ekdosi» (E5, docs/ekdosi-bridge.md), as the WHMCS plugin's button above the invoices: staff ask Ekdosi for a
 * document of an invoice its feed leaves out: an account with «ignore invoicing», or one not paid yet (the customer
 * wants the document before paying; Ekdosi issues it on credit and its payment comes back with `add_payment`). The feed's
 * `requested` lists it until Ekdosi issues a document; Ekdosi never issues it unattended. Withdraw takes it off again.
 *
 * Only an unpaid or paid invoice of an account, without an active document: a draft is not final, a cancelled or
 * refunded one owes nothing.
 */
class SendToEkdosi
{
    public static function eligible(Invoice $invoice): bool
    {
        return $invoice->client_id !== null
            && in_array($invoice->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID], true)
            && !EkdosiDocument::activeOn($invoice->id)
            // An unpaid credit purchase owes nothing: no document on credit for it, with a discount line too (R2-2 of #478).
            && !($invoice->status === Invoice::STATUS_UNPAID && $invoice->items()->where('reference_type', Credit::class)->exists());
    }

    public static function make(): Action
    {
        return Action::make('sendToEkdosi')
            ->label(__('Send to Ekdosi'))
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->visible(fn (Invoice $record): bool => (bool) config('settings.ekdosi_enabled') && $record->ekdosi_requested_at === null
                && self::eligible($record))
            ->authorize(fn () => Gate::allows('has-permission', 'admin.invoices.update'))
            ->requiresConfirmation()
            ->modalDescription(fn (Invoice $record): string => self::description($record))
            ->action(function (Invoice $record) {
                self::notify(self::request([$record], true), true);
                $record->refresh();
            });
    }

    public static function withdraw(): Action
    {
        return Action::make('withdrawFromEkdosi')
            ->label(__('Withdraw from Ekdosi'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (Invoice $record): bool => $record->ekdosi_requested_at !== null)
            ->authorize(fn () => Gate::allows('has-permission', 'admin.invoices.update'))
            ->requiresConfirmation()
            ->modalDescription(__('Ekdosi stops listing it. One it has read already stays in its inbox: archive it there.'))
            ->action(function (Invoice $record) {
                self::notify(self::request([$record], false), false);
                $record->refresh();
            });
    }

    public static function bulk(): BulkAction
    {
        return BulkAction::make('sendToEkdosi')
            ->label(__('Send to Ekdosi'))
            ->icon('heroicon-o-paper-airplane')
            ->visible(fn (): bool => (bool) config('settings.ekdosi_enabled'))
            ->authorize(fn () => Gate::allows('has-permission', 'admin.invoices.update'))
            ->requiresConfirmation()
            ->modalDescription(__('Ekdosi lists them for a document, also those of accounts with «ignore invoicing»; an unpaid one is issued on credit. Drafts, cancelled and refunded invoices and those with a document already are left out.'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => self::notify(self::request($records, true), true, $records->count()));
    }

    /**
     * Sets (or clears) the request on each eligible invoice, once: not through save() (an immutable invoice keeps its other
     * fields; this is no part of what it bills), each change in the invoice's audit with the staff who made it.
     *
     * @param  iterable<Invoice>  $invoices
     * @return int how many changed
     */
    public static function request(iterable $invoices, bool $send): int
    {
        $staffId = Auth::guard('staff')->id();
        $changed = 0;
        foreach ($invoices as $invoice) {
            // Withdrawn whatever became of it (cancelled, refunded meanwhile: review of #478); sent only when eligible.
            if ($send && !self::eligible($invoice)) {
                continue;
            }
            $query = Invoice::query()->whereKey($invoice->id);
            $done = $send
                // As it is now, not as the model loaded it: cancelled or moved meanwhile, nothing (adversarial ADV-3 of #478).
                ? $query->whereNull('ekdosi_requested_at')->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID])
                    ->where('client_id', $invoice->client_id)
                    ->update(['ekdosi_requested_at' => now(), 'ekdosi_requested_by' => $staffId])
                : $query->whereNotNull('ekdosi_requested_at')->update(['ekdosi_requested_at' => null, 'ekdosi_requested_by' => null]);
            if ($done === 0) {
                continue;
            }
            $changed++;
            $invoice->auditEvent = $send ? 'ekdosi_requested' : 'ekdosi_request_withdrawn';
            $invoice->isCustomEvent = true;
            $invoice->auditCustomOld = ['ekdosi_requested' => !$send];
            $invoice->auditCustomNew = ['ekdosi_requested' => $send, 'staff_id' => $staffId];
            event(new AuditCustom($invoice));
        }

        return $changed;
    }

    private static function description(Invoice $invoice): string
    {
        $parts = [__('Ekdosi lists it for a document at its next read, and staff there issue it.')];
        if ($invoice->status === Invoice::STATUS_UNPAID) {
            $parts[] = __('It is not paid: Ekdosi issues it on credit, and its payment closes it later.');
        }
        if ($invoice->client?->ignore_invoicing) {
            $parts[] = __('Its account has «ignore invoicing»: this invoice goes all the same.');
        }

        return implode(' ', $parts);
    }

    private static function notify(int $changed, bool $send, int $selected = 1): void
    {
        if ($changed === 0) {
            Notification::make()->info()->title(__('Nothing to change'))
                ->body($send ? __('Already asked for, or not one Ekdosi issues (a draft, cancelled, refunded, or with a document already).') : null)->send();

            return;
        }
        Notification::make()->success()
            ->title($send ? __('Sent to Ekdosi') : __('Withdrawn from Ekdosi'))
            ->body($selected > 1 ? __(':count of :selected invoices', ['count' => $changed, 'selected' => $selected]) : null)
            ->send();
    }
}
