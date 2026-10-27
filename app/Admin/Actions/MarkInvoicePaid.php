<?php

namespace App\Admin\Actions;

use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Enums\AdjustmentNoteStatus;
use App\Exceptions\DisplayException;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\RecordPaymentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\RawJs;
use Illuminate\Support\Carbon;

/**
 * «Mark Paid», as in WHMCS (docs/money-trail.md MT-1c): staff record the payment (what is left, or a part of it) with
 * its date, method and transaction id, through RecordPaymentService. The invoice becomes paid because the payment
 * covers it, never by its status alone. On a cancelled invoice staff confirm reopening it, which renews its services.
 */
class MarkInvoicePaid
{
    public static function make(): Action
    {
        return Action::make('markPaid')
            ->label(__('Mark Paid'))
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (Invoice $record): bool => in_array($record->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_CANCELLED], true)
                && (!config('settings.immutable_invoices_enabled') || $record->status === Invoice::STATUS_UNPAID))
            // It records a payment: the permission «Add Payment» needs too, not only the invoice's.
            ->authorize(function (Invoice $record): bool {
                $user = auth()->user();

                return $user !== null && $user->can('update', $record) && $user->can('create', InvoiceTransaction::class);
            })
            ->fillForm(fn (Invoice $record): array => [
                'amount' => number_format(max(0, RecordPaymentService::toCents($record->remaining)
                    + ($record->status === Invoice::STATUS_CANCELLED ? self::cancellationCreditCents($record) : 0)) / 100, 2, '.', ''),
                'paid_at' => now()->toDateString(),
            ])
            ->schema([
                TextInput::make('amount')
                    ->label(__('Amount'))
                    ->required()
                    ->prefix(fn (Invoice $record): ?string => $record->currency?->prefix)
                    ->suffix(fn (Invoice $record): ?string => $record->currency?->suffix)
                    ->mask(RawJs::make(
                        <<<'JS'
                        $money($input, '.', '', 2)
                        JS
                    ))
                    ->helperText(__('Less than what is left records a part of it: the invoice stays unpaid. More is refused: an overpayment is recorded with Add Payment.'))
                    ->rules([fn (): \Closure => function (string $attribute, $value, \Closure $fail) {
                        if (RecordPaymentService::toCents($value) <= 0) {
                            $fail(__('The amount must be positive.'));
                        }
                    }]),
                DatePicker::make('paid_at')
                    ->label(__('Payment date'))
                    ->required()
                    ->maxDate(now()),
                Select::make('gateway_id')
                    ->label(__('Payment method'))
                    ->options(fn () => Gateway::query()->pluck('name', 'id'))
                    ->placeholder(__('Manual (no gateway)')),
                TextInput::make('transaction_id')
                    ->label(__('Transaction ID'))
                    ->maxLength(255),
                Checkbox::make('reopen')
                    ->label(__('This invoice is cancelled: reopen it and record the payment. Its services are renewed.'))
                    ->visible(fn (Invoice $record): bool => $record->status === Invoice::STATUS_CANCELLED)
                    ->accepted(),
            ])
            ->action(function (Invoice $record, array $data, $livewire): void {
                $gateway = filled($data['gateway_id'] ?? null) ? Gateway::query()->find($data['gateway_id']) : null;
                try {
                    (new RecordPaymentService)->recordByStaff(
                        $record, $gateway, $data['amount'], $data['transaction_id'] ?? null,
                        paidAt: Carbon::parse($data['paid_at'])->setTimeFrom(now()),
                        reopen: (bool) ($data['reopen'] ?? false),
                        upToRemaining: true,
                    );
                } catch (DisplayException $e) {
                    Notification::make()->danger()->title(__('Payment not recorded'))->body($e->getMessage())->send();

                    throw new Halt;
                }
                $record->refresh();
                // The edit page's form still holds the old status: a later Save would write it back over the payment's.
                if ($livewire instanceof EditRecord) {
                    $livewire->refreshFormData(['status']);
                }
                // The tables below (transactions, movements) are their own components: they show it at once.
                $livewire->dispatch(TransactionsRelationManager::PAYMENTS_CHANGED);
                Notification::make()->success()
                    ->title($record->status === Invoice::STATUS_PAID ? __('Invoice paid') : __('Payment recorded'))
                    ->send();
            });
    }

    /** What the cancellation's credit note takes off the total: reopening voids it, so it is to pay again. */
    private static function cancellationCreditCents(Invoice $invoice): int
    {
        return abs(RecordPaymentService::toCents($invoice->adjustmentNotes
            ->where('is_cancellation', true)->where('status', AdjustmentNoteStatus::Active->value)->sum('amount')));
    }
}
