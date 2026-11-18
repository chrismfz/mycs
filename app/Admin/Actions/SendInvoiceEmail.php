<?php

namespace App\Admin\Actions;

use App\Helpers\NotificationHelper;
use App\Models\Invoice;
use App\Services\Invoice\PaymentReminders;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * WHMCS's «Send Email» on an invoice (audit sweep Σ4): staff mail the customer one of the invoice templates, e.g.
 * «Invoice Modified» after changing its lines, or a reminder by hand.
 */
class SendInvoiceEmail extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'sendEmail';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Send Email')
            ->icon('heroicon-o-envelope')
            ->authorize(fn () => Gate::allows('has-permission', 'admin.invoices.update'))
            ->visible(fn (Invoice $record): bool => $record->status !== Invoice::STATUS_DRAFT && $record->client !== null)
            ->schema([
                Select::make('template')->label('Template')->required()->options(fn (Invoice $record) => array_filter([
                    'invoice_modified' => 'Invoice Modified',
                    'new_invoice_created' => 'Invoice Created',
                    'invoice_payment_reminder' => 'Invoice Payment Reminder',
                    // Only once it is overdue (review R7): «0 days overdue» says nothing.
                    'invoice_overdue' => self::daysOverdue($record) > 0 ? 'Invoice Overdue Notice' : null,
                    'invoice_paid' => 'Invoice Payment Confirmation',
                ]))->default('invoice_modified'),
            ])
            ->action(function (Invoice $record, array $data) {
                self::send($record, $data['template']);
                // The account's address (its primary contact's, Client::mailAddress); its contacts get theirs by kind.
                Notification::make()->success()->title('Email queued to ' . $record->client?->mailAddress())->send();
            });
    }

    /**
     * WHMCS's «Send Reminder» on the invoice lists (owner 2026-11-18): each chosen unpaid invoice gets its payment
     * reminder, or the overdue notice once past its due date, as «Send Email» sends it. The others are skipped and counted.
     */
    public static function reminders(): BulkAction
    {
        return BulkAction::make('sendReminder')
            ->label(__('Send reminder'))
            ->icon('heroicon-o-bell-alert')
            ->authorize(fn () => Gate::allows('has-permission', 'admin.invoices.update'))
            ->requiresConfirmation()
            ->modalDescription(__('Each unpaid invoice chosen gets its payment reminder, or the overdue notice once past its due date. Paid, cancelled and draft invoices are skipped.'))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $sent = 0;
                foreach ($records as $invoice) {
                    /** @var Invoice $invoice */
                    if ($invoice->status !== Invoice::STATUS_UNPAID || $invoice->client === null) {
                        continue;
                    }
                    self::send($invoice, self::daysOverdue($invoice) > 0 ? 'invoice_overdue' : 'invoice_payment_reminder');
                    $sent++;
                }
                $skipped = $records->count() - $sent;
                Notification::make()->{$sent > 0 ? 'success' : 'warning'}()
                    ->title(__('Reminders queued: :count', ['count' => $sent]))
                    ->body($skipped > 0 ? __('Skipped, not unpaid: :count', ['count' => $skipped]) : null)
                    ->send();
            });
    }

    /** Mail it with one of the invoice templates, by staff; a reminder sent counts as that day's (review A9). */
    public static function send(Invoice $invoice, string $template): void
    {
        $days = self::daysOverdue($invoice);
        NotificationHelper::invoiceNotification($invoice->client, $invoice, $template, ['overdue' => ['days' => $days, 'last' => false, 'suspend_on' => null]], byStaff: true);
        // Counted as the reminder of the day (review A9): the cron does not send it again.
        if ($template === 'invoice_payment_reminder') {
            PaymentReminders::recordSent($invoice, 'due', 0);
        } elseif ($template === 'invoice_overdue') {
            foreach (PaymentReminders::overdueDays() as $day) {
                if ($day <= $days) {
                    PaymentReminders::recordSent($invoice, 'overdue', $day);
                }
            }
        }
    }

    /** Whole days past its due date (0 on the due day or before). */
    private static function daysOverdue(Invoice $invoice): int
    {
        // Days past its due date, the same rule as the list's «Overdue» (Invoice::daysOverdue), whatever its status here.
        $due = $invoice->due_at?->copy()->startOfDay();

        return $due === null || !$due->lt(today()) ? 0 : (int) $due->diffInDays(today());
    }
}
