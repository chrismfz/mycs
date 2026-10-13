<?php

namespace App\Admin\Resources\InvoiceResource\RelationManagers;

use App\Admin\Actions\DeleteInvoiceTransaction;
use App\Enums\InvoiceTransactionStatus;
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
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
                // The day the money arrived (a bank transfer recorded later): the transaction's date (MT-1c).
                DatePicker::make('paid_at')
                    ->label(__('Payment date'))
                    ->default(fn () => now()->toDateString())
                    ->maxDate(now()),
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
                        // The select is bound to the relationship: its state is gateway.name (the gateway id).
                        $gatewayId = data_get($data, 'gateway.name') ?? ($data['gateway_id'] ?? null);
                        $gateway = $gatewayId !== null ? Gateway::query()->find($gatewayId) : null;
                        /** @var Invoice $invoice */
                        $invoice = $this->getOwnerRecord();
                        $transaction = (new RecordPaymentService)->handle(
                            $invoice, $gateway, $data['amount'] ?? null, $data['fee'] ?? null, $data['transaction_id'] ?? null,
                            enteredByStaff: true,
                            paidAt: filled($data['paid_at'] ?? null) ? Carbon::parse($data['paid_at'])->setTimeFrom(now()) : null,
                        );
                        if ($transaction === null) {
                            Notification::make()->danger()->title(__('Payment not recorded'))
                                ->body(__('The amount must be positive (see the log for details).'))->send();

                            throw new Halt;
                        }
                        if (!$transaction->wasRecentlyCreated && !$transaction->wasChanged('status')) {
                            Notification::make()->warning()->title(__('Payment already recorded'))
                                ->body(__('This gateway transaction is already recorded (invoice #:invoice): nothing was added.', ['invoice' => $transaction->invoice_id]))->send();

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
                                    if (RecordPaymentService::toCents($value) <= 0) {
                                        $fail(__('invoices.refund_amount_positive'));
                                    }
                                    if (RecordPaymentService::toCents($value) > RecordPaymentService::toCents($record->refundable_amount)) {
                                        $fail(__('invoices.refund_amount_exceeds_refundable', ['max' => $record->formattedRefundableAmount]));
                                    }
                                },
                            ]),
                        // As WHMCS: back through the gateway, back by hand (a bank transfer: recorded only), or to
                        // the customer's account credit (PR 2c). A payment made with credit goes back to credit only.
                        Radio::make('method')
                            ->label(__('invoices.refund_method'))
                            ->options(fn (InvoiceTransaction $record): array => self::refundMethods($record))
                            ->default(fn (InvoiceTransaction $record): string => array_key_first(self::refundMethods($record)))
                            ->required(),
                    ])
                    ->action(function (InvoiceTransaction $record, array $data, Action $action): void {
                        try {
                            match ($data['method'] ?? null) {
                                'gateway' => ExtensionHelper::refund($record, (float) $data['amount']),
                                'manual' => ExtensionHelper::refundLocally($record, (float) $data['amount']),
                                'credit' => ExtensionHelper::refundToCredit($record, (float) $data['amount']),
                                default => throw new \InvalidArgumentException('Choose how the money goes back.'),
                            };

                            $this->notifyRefundResult(true);

                            $action->success();
                        } catch (\Exception $e) {
                            $this->notifyRefundResult(false, $e->getMessage());

                            $action->halt();
                        }
                    })
                    ->visible(
                        // Any payment that arrived, with or without a provider id (a transfer staff recorded, a credit payment).
                        fn (InvoiceTransaction $record): bool => $record->status === InvoiceTransactionStatus::Succeeded &&
                            $record->refundable_amount > 0 &&
                            Auth::user()->can('update', $record)
                    )
                    ->modalSubmitAction(fn (Action $action) => $action->label(__('invoices.refund'))),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->canModifyTransactions())
                    ->schema([DeleteInvoiceTransaction::negativeCreditField()])
                    ->using(fn (InvoiceTransaction $record, array $data): bool => DeleteInvoiceTransaction::run($record, (bool) ($data['allow_negative_credit'] ?? false))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => $this->canModifyTransactions())
                        // One by one through the service, never a mass query delete (credit, money trail).
                        ->using(fn (Collection $records) => $records->each(fn (InvoiceTransaction $record) => DeleteInvoiceTransaction::run($record))),
                ]),
            ]);
    }

    /** @return array<string, string> the ways this payment can go back, the default first */
    private static function refundMethods(InvoiceTransaction $record): array
    {
        if ($record->is_credit_transaction) {
            return ['credit' => __('invoices.refund_method_credit')];
        }
        $methods = [];
        if ($record->gateway && $record->gateway->extension && ExtensionHelper::hasFunction($record->gateway, 'supportsRefunds')
            && ExtensionHelper::hasFunction($record->gateway, 'refund')) {
            $methods['gateway'] = __('invoices.refund_method_gateway');
        }

        return $methods + [
            'manual' => __('invoices.refund_method_manual'),
            'credit' => __('invoices.refund_method_credit'),
        ];
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
