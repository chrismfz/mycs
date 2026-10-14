<?php

namespace App\Admin\Actions;

use App\Helpers\NotificationHelper;
use App\Models\Invoice;
use App\Services\Invoice\PaymentReminders;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

/**
 * WHMCS's «Send Email» on an invoice (audit sweep Σ4): staff mail the customer one of the invoice templates, e.g.
 * «Invoice Modified» after changing its lines, or a reminder by hand. On the edit and the view page (immutable
 * invoices open on the view page).
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
            // The permission, not the policy's update (review A8): with immutable invoices update is drafts only.
            ->authorize(fn () => Gate::allows('has-permission', 'admin.invoices.update'))
            ->visible(fn (Invoice $record): bool => $record->status !== Invoice::STATUS_DRAFT && $record->user !== null)
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
                $days = self::daysOverdue($record);
                NotificationHelper::invoiceNotification($record->user, $record, $data['template'], ['overdue' => ['days' => $days, 'last' => false, 'suspend_on' => null]]);
                // Counted as the reminder of the day (review A9): the cron does not send it again.
                if ($data['template'] === 'invoice_payment_reminder') {
                    PaymentReminders::recordSent($record, 'due', 0);
                } elseif ($data['template'] === 'invoice_overdue') {
                    foreach (PaymentReminders::overdueDays() as $day) {
                        if ($day <= $days) {
                            PaymentReminders::recordSent($record, 'overdue', $day);
                        }
                    }
                }
                Notification::make()->success()->title('Email queued to ' . $record->user->email)->send();
            });
    }

    /** Whole days past its due date (0 on the due day or before). */
    private static function daysOverdue(Invoice $invoice): int
    {
        $due = $invoice->due_at?->copy()->startOfDay();

        return $due === null || !$due->lt(today()) ? 0 : (int) $due->diffInDays(today());
    }
}
