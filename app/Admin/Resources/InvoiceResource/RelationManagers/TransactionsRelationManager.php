<?php

namespace App\Admin\Resources\InvoiceResource\RelationManagers;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\RecordPaymentService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected function canModifyTransactions(): bool
    {
        return !config('settings.immutable_invoices_enabled') || $this->getOwnerRecord()->status === Invoice::STATUS_PENDING;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('gateway.name')
                    ->label('Gateway')
                    ->relationship('gateway', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Select the gateway'),
                TextInput::make('transaction_id')
                    ->label('Transaction ID'),
                TextInput::make('amount')
                    ->label('Amount')
                    ->numeric()
                    ->mask(RawJs::make(
                        <<<'JS'
                            $money($input, '.', '', 2)
                        JS
                    ))
                    ->required(),
                TextInput::make('fee')
                    ->numeric()
                    ->mask(RawJs::make(
                        <<<'JS'
                            $money($input, '.', '', 2)
                        JS
                    ))
                    ->label('Fee'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('transaction_id')
            ->columns([
                TextColumn::make('gateway.name')->label('Gateway'),
                TextColumn::make('transaction_id'),
                TextColumn::make('formattedAmount')->label('Amount'),
                TextColumn::make('formattedRefundedAmount')->label(__('invoices.refunded_amount')),
                TextColumn::make('formattedFee')->label('Fee'),
                TextColumn::make('created_at'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->canModifyTransactions())
                    // Staff-recorded money follows the same rules as a gateway's (docs/payments.md): what the
                    // invoice cannot take becomes account credit; a replayed transaction id changes nothing.
                    ->using(function (array $data): InvoiceTransaction {
                        $gateway = isset($data['gateway_id']) ? Gateway::query()->find($data['gateway_id']) : null;
                        $transaction = (new RecordPaymentService)->handle(
                            $this->getOwnerRecord(), $gateway, $data['amount'] ?? null, $data['fee'] ?? null, $data['transaction_id'] ?? null,
                        );
                        if ($transaction === null) {
                            Notification::make()->danger()->title(__('Payment not recorded'))
                                ->body(__('The amount must be positive (see the log for details).'))->send();

                            throw new Halt;
                        }

                        return $transaction;
                    }),
            ])
            ->recordActions([
                Action::make('refund')
                    ->label(__('invoices.refund'))
                    ->icon('heroicon-o-backward')
                    ->color('warning')
                    ->modalHeading(fn (InvoiceTransaction $record): string => __('invoices.refund_transaction', ['id' => $record->transaction_id ?? $record->id]))
                    ->modalDescription(fn (InvoiceTransaction $record): string => __('invoices.refundable_amount', ['amount' => $record->formattedRefundableAmount]))
                    ->form([
                        TextInput::make('amount')
                            ->label(__('invoices.amount'))
                            ->numeric()
                            ->prefix(fn (InvoiceTransaction $record): ?string => $record->invoice?->currency->prefix)
                            ->suffix(fn (InvoiceTransaction $record): ?string => $record->invoice?->currency->suffix)
                            ->mask(RawJs::make(
                                <<<'JS'
                                    $money($input, '.', '', 2)
                                JS
                            ))
                            ->required()
                            ->rules([
                                fn (InvoiceTransaction $record): \Closure => function (string $attribute, $value, \Closure $fail) use ($record) {
                                    if ((float) $value <= 0) {
                                        $fail(__('invoices.refund_amount_positive'));
                                    }
                                    if ((float) $value > $record->refundable_amount) {
                                        $fail(__('invoices.refund_amount_exceeds_refundable', ['max' => $record->formattedRefundableAmount]));
                                    }
                                },
                            ]),
                        Toggle::make('refund_via_gateway')
                            ->label(__('invoices.refund_via_gateway'))
                            ->default(false)
                            ->visible(fn (InvoiceTransaction $record): bool => $record->gateway && $record->gateway->extension && ExtensionHelper::hasFunction($record->gateway, 'supportsRefunds') && ExtensionHelper::hasFunction($record->gateway, 'refund')),
                    ])
                    ->action(function (InvoiceTransaction $record, array $data, Action $action): void {
                        try {
                            if (!empty($data['refund_via_gateway']) && $record->gateway) {
                                ExtensionHelper::refund($record, (float) $data['amount']);
                            } else {
                                ExtensionHelper::refundLocally($record, (float) $data['amount']);
                            }

                            $this->notifyRefundResult(true);

                            $action->success();
                        } catch (\Exception $e) {
                            $this->notifyRefundResult(false, $e->getMessage());

                            $action->halt();
                        }
                    })
                    ->visible(
                        fn (InvoiceTransaction $record): bool => !empty($record->transaction_id) &&
                            $record->refundable_amount > 0 &&
                            Auth::user()->can('update', $record)
                    )
                    ->modalSubmitAction(fn (Action $action) => $action->label(__('invoices.refund'))),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->canModifyTransactions()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => $this->canModifyTransactions()),
                ]),
            ]);
    }

    private function notifyRefundResult(bool $success, ?string $errorMessage = null): void
    {
        Notification::make()
            ->title(__($success ? 'invoices.refund_success' : 'invoices.refund_failed'))
            ->when(!$success, fn (Notification $notification) => $notification->body($errorMessage))
            ->{$success ? 'success' : 'danger'}()
            ->send();
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
