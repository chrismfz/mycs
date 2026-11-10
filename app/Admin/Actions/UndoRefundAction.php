<?php

namespace App\Admin\Actions;

use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\UndoRefund;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * «Undo refund» on a payment, from any admin table (owner 2026-11-10, sweep 7 TIGHT-INVOICES-PAYMENTS-17): one refund at
 * a time, through UndoRefund. A refund the gateway made asks for staff's word that only MyCS's books are corrected; one
 * to account credit, already spent, for the tick a deletion asks for. No bulk.
 */
class UndoRefundAction
{
    public static function make(): Action
    {
        return Action::make('undoRefund')
            ->label(__('invoices.refund_undo.action'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->modalHeading(fn (InvoiceTransaction $record): string => __('invoices.refund_undo.heading', ['id' => $record->id]))
            ->modalDescription(__('invoices.refund_undo.help'))
            ->schema([
                Select::make('refund')->label(__('invoices.refund_undo.which'))
                    ->options(fn (InvoiceTransaction $record): array => self::options($record))
                    ->default(fn (InvoiceTransaction $record): ?int => array_key_first(self::options($record)))
                    ->required(),
                Checkbox::make('allow_negative_credit')->label(__('invoices.refund_undo.negative_credit'))
                    ->visible(fn (InvoiceTransaction $record): bool => self::has($record, UndoRefund::TO_CREDIT)),
                Checkbox::make('money_left_at_gateway')->label(__('invoices.refund_undo.gateway_confirm'))
                    ->visible(fn (InvoiceTransaction $record): bool => self::has($record, UndoRefund::BY_GATEWAY)),
            ])
            ->action(function (InvoiceTransaction $record, array $data, Action $action, Component $livewire): void {
                try {
                    (new UndoRefund)->handle($record, (int) ($data['refund'] ?? 0), (bool) ($data['allow_negative_credit'] ?? false), (bool) ($data['money_left_at_gateway'] ?? false));
                } catch (DisplayException $e) {
                    Notification::make()->danger()->title(__('invoices.refund_undo.refused'))->body(e($e->getMessage()))->send();
                    $action->halt();
                }
                Notification::make()->success()->title(__('invoices.refund_undo.done'))->send();
                $livewire->dispatch(TransactionsRelationManager::PAYMENTS_CHANGED);
            })
            ->visible(fn (InvoiceTransaction $record): bool => $record->status === InvoiceTransactionStatus::Succeeded
                && (RecordPaymentService::toCents($record->refunded_amount) > 0 || RecordPaymentService::toCents($record->credited_amount) > 0)
                && (bool) Auth::user()?->can('update', $record)
                && UndoRefund::refunds($record)->isNotEmpty());
    }

    /** @return array<int, string> its standing refunds by journal event: day, amount, how it went back */
    private static function options(InvoiceTransaction $record): array
    {
        return UndoRefund::refunds($record)->mapWithKeys(fn (MoneyEvent $refund) => [$refund->id => $refund->occurred_at->toDateString()
            . ' · ' . number_format((float) $refund->amount, 2, '.', '') . ' ' . $refund->currency_code
            . ' · ' . __('invoices.refund_undo.kinds.' . UndoRefund::kind($refund))])->all();
    }

    private static function has(InvoiceTransaction $record, string $kind): bool
    {
        return UndoRefund::refunds($record)->contains(fn (MoneyEvent $refund) => UndoRefund::kind($refund) === $kind);
    }
}
