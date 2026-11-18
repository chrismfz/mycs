<?php

namespace App\Admin\Actions;

use App\Models\Invoice;
use App\Services\Invoice\PaymentReminders;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * WHMCS's «Send Reminder» on the invoice lists (owner 2026-11-18): each chosen invoice gets its reminder by the cron's
 * rules (PaymentReminders::sendNow). The rest are skipped and counted.
 */
class SendRemindersAction
{
    /** Each one renders its PDF in the request: a page's worth, not a whole list. */
    public const MAX = 50;

    public static function make(): BulkAction
    {
        return BulkAction::make('sendReminder')
            ->label(__('Send reminder'))
            ->icon('heroicon-o-bell-alert')
            ->authorize(fn () => Gate::allows('has-permission', 'admin.invoices.update'))
            ->requiresConfirmation()
            ->modalDescription(__('Each invoice chosen that is owed gets its reminder as the cron sends it: the payment reminder, or the overdue notice once past its due date. Skipped: not owed, a payment on its way, a closed account, overdue notices off, domains with their own notices, or mailed today already.'))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                if ($records->count() > self::MAX) {
                    Notification::make()->danger()->title(__('Choose at most :max invoices', ['max' => self::MAX]))->send();

                    throw new Halt;
                }
                if (config('settings.mail_disable')) {
                    Notification::make()->danger()->title(__('Mail is off in Settings: nothing sent'))->send();

                    throw new Halt;
                }
                $reminders = new PaymentReminders;
                $sent = $records->filter(fn (Invoice $invoice) => $reminders->sendNow($invoice))->count();
                $skipped = $records->count() - $sent;
                Notification::make()->{$sent > 0 ? 'success' : 'warning'}()
                    ->title(__('Reminders queued: :count', ['count' => $sent]))
                    ->body($skipped > 0 ? __('Skipped: :count', ['count' => $skipped]) : null)
                    ->send();
            });
    }
}
