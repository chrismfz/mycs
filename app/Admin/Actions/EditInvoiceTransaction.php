<?php

namespace App\Admin\Actions;

use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\EditPayment;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * «Edit» on a recorded payment, from any admin table (owner 2026-11-10, sweep 7 TIGHT-INVOICES-PAYMENTS-15): through
 * EditPayment, which changes what moves no money in place and does the rest as «delete + record again». For the
 * payments staff may delete, with the same permission; its amount and invoice also need the permission to record
 * payments. No bulk.
 */
class EditInvoiceTransaction
{
    public static function make(): Action
    {
        return Action::make('editPayment')
            ->label(__('invoices.payment_edit.action'))
            ->icon('heroicon-o-pencil-square')
            ->modalHeading(fn (InvoiceTransaction $record): string => __('invoices.payment_edit.heading', ['id' => $record->id]))
            ->modalDescription(fn (InvoiceTransaction $record): string => __(EditPayment::recordedByGateway($record) ? 'invoices.payment_edit.gateway_help' : 'invoices.payment_edit.help'))
            ->fillForm(fn (InvoiceTransaction $record): array => [
                'invoice_id' => $record->invoice_id, 'amount' => $record->amount, 'paid_at' => $record->created_at?->toDateString(),
                'gateway_id' => $record->gateway_id, 'transaction_id' => $record->transaction_id, 'fee' => $record->fee,
            ])
            ->schema([
                // A disabled field is not submitted: what the gateway recorded stays as it is, and money is moved only by
                // staff who may record payments (EditPayment checks both again).
                Select::make('invoice_id')->label(__('invoices.payment_edit.invoice'))
                    ->options(fn (InvoiceTransaction $record): array => self::invoices($record))->required()
                    ->disabled(fn (InvoiceTransaction $record): bool => !self::mayMove($record)),
                TextInput::make('amount')->label(__('invoices.amount'))->numeric()->required()
                    ->disabled(fn (InvoiceTransaction $record): bool => !self::mayMove($record)),
                DatePicker::make('paid_at')->label(__('invoices.payment_edit.date'))->maxDate(now())->required(),
                Select::make('gateway_id')->label('Gateway')->options(fn (): array => Gateway::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->disabled(fn (InvoiceTransaction $record): bool => EditPayment::recordedByGateway($record)),
                TextInput::make('transaction_id')->label('Transaction ID')
                    ->disabled(fn (InvoiceTransaction $record): bool => EditPayment::recordedByGateway($record)),
                TextInput::make('fee')->label('Fee')->numeric(),
                DeleteInvoiceTransaction::negativeCreditField()->label(__('invoices.payment_edit.negative_credit')),
            ])
            ->action(function (InvoiceTransaction $record, array $data, Action $action, Component $livewire): void {
                $changes = array_intersect_key($data, array_flip(['invoice_id', 'amount', 'gateway_id', 'transaction_id', 'fee']));
                // Another day only: the time of day it had stays (today, never later than now).
                if (filled($data['paid_at'] ?? null) && Carbon::parse($data['paid_at'])->toDateString() !== $record->created_at?->toDateString()) {
                    $changes['paid_at'] = Carbon::parse($data['paid_at'])->setTimeFrom($record->created_at ?? now())->min(now());
                }
                try {
                    (new EditPayment)->handle($record, $changes, (bool) ($data['allow_negative_credit'] ?? false));
                } catch (DisplayException $e) {
                    Notification::make()->danger()->title(__('invoices.payment_edit.refused'))->body(e($e->getMessage()))->send();
                    $action->halt();
                }
                Notification::make()->success()->title(__('invoices.payment_edit.saved'))->send();
                $livewire->dispatch(TransactionsRelationManager::PAYMENTS_CHANGED);
            })
            ->visible(fn (InvoiceTransaction $record): bool => $record->status === InvoiceTransactionStatus::Succeeded
                && !$record->is_credit_transaction && $record->source_transaction_id === null
                && (bool) Auth::user()?->can('delete', $record));
    }

    /** Its amount and invoice: not a payment its gateway recorded, and only with the permission to record payments. */
    private static function mayMove(InvoiceTransaction $record): bool
    {
        return !EditPayment::recordedByGateway($record) && (bool) Auth::user()?->can('create', InvoiceTransaction::class);
    }

    /** @return array<int, string> the customer's invoices in the payment's currency a payment can be on, newest first */
    private static function invoices(InvoiceTransaction $record): array
    {
        $invoice = $record->invoice;
        if ($invoice === null || $invoice->client_id === null) {
            return [$record->invoice_id => '#' . $record->invoice_id];
        }

        return Invoice::query()->where('client_id', $invoice->client_id)->where('currency_code', $invoice->currency_code)
            ->where(fn ($query) => $query->whereKey($invoice->id)->orWhereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_DRAFT, Invoice::STATUS_PAID]))
            ->orderByDesc('id')->limit(200)->get(['id', 'number', 'status'])
            ->mapWithKeys(fn (Invoice $option) => [$option->id => '#' . $option->id . ($option->number ? ' · ' . $option->number : '') . ' · ' . __('money.statuses.' . $option->status)])
            ->all();
    }
}
