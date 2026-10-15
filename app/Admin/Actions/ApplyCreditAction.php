<?php

namespace App\Admin\Actions;

use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\CreditLedger;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\RawJs;

/**
 * «Apply credit» (PR F1, sweep Π3.2): staff pay an unpaid invoice from the customer's account credit, as in WHMCS. Needed
 * where credit is not applied on its own (myip: «Auto-use credit» off) and for the invoices the automatic pass leaves out
 * (a subscription renewal, a cancellation request): staff decide, so no exclusion applies. Up to what is left and what
 * the credit holds, read under the payment locks (RecordPaymentService::payWithCredit).
 */
class ApplyCreditAction
{
    public static function make(): Action
    {
        return Action::make('applyCredit')
            ->label(__('Apply credit'))
            ->icon('heroicon-o-wallet')
            ->color('success')
            // Not a credit purchase: credit would buy credit, and the invoice would read paid with no money in (F1 adversarial).
            ->visible(fn (Invoice $record): bool => $record->status === Invoice::STATUS_UNPAID && self::creditCents($record) > 0
                && RecordPaymentService::toCents($record->remaining) > 0
                && !$record->items()->where('reference_type', Credit::class)->exists())
            // It records a payment: the permission «Add Payment» needs too, not only the invoice's.
            ->authorize(function (Invoice $record): bool {
                $user = auth()->user();

                return $user !== null && $user->can('update', $record) && $user->can('create', InvoiceTransaction::class);
            })
            ->modalDescription(fn (Invoice $record): string => __('The customer has :amount :currency of account credit.', [
                'amount' => number_format(self::creditCents($record) / 100, 2, '.', ''), 'currency' => $record->currency_code,
            ]))
            ->fillForm(fn (Invoice $record): array => [
                'amount' => number_format(min(self::creditCents($record), max(0, RecordPaymentService::toCents($record->remaining))) / 100, 2, '.', ''),
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
                    ->rules([fn (): \Closure => function (string $attribute, $value, \Closure $fail) {
                        if (RecordPaymentService::toCents($value) <= 0) {
                            $fail(__('The amount must be positive.'));
                        }
                    }]),
            ])
            ->action(function (Invoice $record, array $data, $livewire): void {
                $transaction = (new RecordPaymentService)->payWithCredit($record, upToCents: RecordPaymentService::toCents($data['amount']));
                if ($transaction === null) {
                    Notification::make()->danger()->title(__('No credit applied'))
                        ->body(__('Nothing is left to pay on it, or the customer has no credit in its currency: reload it.'))->send();

                    throw new Halt;
                }
                (new CreditAutoApply)->notify($transaction);
                $record->refresh();
                // The edit page's form still holds the old status: a later Save would write it back over the payment's.
                if ($livewire instanceof EditRecord) {
                    $livewire->refreshFormData(['status']);
                }
                Notification::make()->success()->title(__('Credit applied: :amount', ['amount' => number_format((float) $transaction->amount, 2, '.', '')]))->send();
            });
    }

    private static function creditCents(Invoice $invoice): int
    {
        return $invoice->client_id === null ? 0 : (new CreditLedger)->balanceCents((int) $invoice->client_id, (string) $invoice->currency_code);
    }
}
