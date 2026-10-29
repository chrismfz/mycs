<?php

namespace App\Admin\Actions;

use App\Exceptions\DisplayException;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\PayInvoices;
use App\Services\Invoice\RecordPaymentService;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Support\RawJs;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * «Add payment to invoices» on the invoice list (PR F1, decision 7): one bank transfer or payment that covers several
 * unpaid invoices of a customer. Recorded once, it pays them oldest due first, each its whole remaining; the rest becomes
 * account credit (PayInvoices).
 */
class PayInvoicesAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('payInvoices')
            ->label(__('Add payment to invoices'))
            ->icon('heroicon-o-banknotes')
            ->authorize(function (): bool {
                $user = auth()->user();

                return $user !== null && $user->can('update', Invoice::class) && $user->can('create', InvoiceTransaction::class);
            })
            ->deselectRecordsAfterCompletion()
            ->modalDescription(__('One payment for the chosen unpaid invoices of one customer. It pays them oldest due first, each in full while it lasts; what is left becomes the customer\'s account credit.'))
            ->fillForm(fn (Collection $records): array => [
                'amount' => number_format($records->sum(fn (Invoice $invoice) => max(0, RecordPaymentService::toCents($invoice->remaining))) / 100, 2, '.', ''),
                'paid_at' => now()->toDateString(),
            ])
            ->schema([
                TextInput::make('amount')
                    ->label(__('Amount'))
                    ->required()
                    ->mask(RawJs::make(
                        <<<'JS'
                        $money($input, '.', '', 2)
                        JS
                    ))
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
            ])
            ->action(function (Collection $records, array $data): void {
                // Each invoice, as staff may change it (immutable invoices, adversarial E-ADV-4 of PR E).
                if ($records->contains(fn (Invoice $invoice) => !auth()->user()?->can('update', $invoice))) {
                    Notification::make()->danger()->title(__('Payment not recorded'))->body(__('You may not change one of these invoices.'))->send();

                    throw new Halt;
                }
                $gateway = filled($data['gateway_id'] ?? null) ? Gateway::query()->find($data['gateway_id']) : null;
                try {
                    [, $others] = (new PayInvoices)->recordByStaff($records->pluck('id')->map(fn ($id) => (int) $id)->all(), $gateway,
                        $data['amount'], $data['transaction_id'] ?? null, Carbon::parse($data['paid_at'])->setTimeFrom(now()));
                } catch (DisplayException $e) {
                    Notification::make()->danger()->title(__('Payment not recorded'))->body(e($e->getMessage()))->send();

                    throw new Halt;
                }
                Notification::make()->success()->title(__('Payment recorded on :count invoices', ['count' => 1 + count($others)]))->send();
            });
    }
}
