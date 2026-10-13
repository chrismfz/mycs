<?php

namespace App\Admin\Resources;

use App\Admin\Resources\PaymentGatewayEventResource\Pages\ListPaymentGatewayEvents;
use App\Models\PaymentGatewayEvent;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * «Gateway log» (docs/money-trail.md §4, MT-2a): every request to a gateway's routes and what came of it. Read only.
 */
class PaymentGatewayEventResource extends Resource
{
    protected static ?string $model = PaymentGatewayEvent::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-exchange-funds-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-exchange-funds-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Other';

    public static function getNavigationLabel(): string
    {
        return __('Gateway log');
    }

    public static function getModelLabel(): string
    {
        return __('Gateway log');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Date'))->dateTime()->sortable(),
                TextColumn::make('gateway')->label(__('Gateway'))->searchable(),
                TextColumn::make('endpoint')->label(__('Endpoint')),
                TextColumn::make('event_type')->label(__('Event'))->placeholder('—')->searchable(),
                IconColumn::make('signature_verified')->label(__('Signature'))->boolean(),
                TextColumn::make('outcome')->label(__('Outcome'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('money.outcomes.' . $state))
                    ->color(fn (string $state): string => match ($state) {
                        PaymentGatewayEvent::OUTCOME_RECORDED => 'success',
                        PaymentGatewayEvent::OUTCOME_IGNORED => 'gray',
                        PaymentGatewayEvent::OUTCOME_REJECTED => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('reason')->label(__('Reason'))->placeholder('—'),
                TextColumn::make('amount')->label(__('Amount'))
                    ->formatStateUsing(fn (PaymentGatewayEvent $record): string => $record->amount . ' ' . $record->currency_code)
                    ->placeholder('—'),
                TextColumn::make('external_id')->label(__('Gateway id'))->searchable()->placeholder('—')->limit(30),
                TextColumn::make('invoice_id')->label(__('Invoice'))->prefix('#')->placeholder('—')
                    ->url(fn (PaymentGatewayEvent $record): ?string => $record->invoice_id !== null && auth()->user()?->hasPermission('admin.invoices.view')
                        ? InvoiceResource::getUrl(config('settings.immutable_invoices_enabled', false) ? 'view' : 'edit', ['record' => $record->invoice_id])
                        : null),
                TextColumn::make('invoice_transaction_id')->label(__('Transaction'))->prefix('#')->placeholder('—'),
                TextColumn::make('http_status')->label('HTTP'),
                TextColumn::make('fields')->label(__('Fields'))->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state): string => is_array($state) ? implode(', ', $state) : (string) $state)->wrap(),
            ])
            ->filters([
                SelectFilter::make('outcome')->label(__('Outcome'))->options(fn (): array => collect([
                    PaymentGatewayEvent::OUTCOME_RECORDED, PaymentGatewayEvent::OUTCOME_IGNORED, PaymentGatewayEvent::OUTCOME_REJECTED,
                    PaymentGatewayEvent::OUTCOME_ERROR, PaymentGatewayEvent::OUTCOME_RECEIVED,
                ])->mapWithKeys(fn (string $outcome) => [$outcome => __('money.outcomes.' . $outcome)])->all()),
                SelectFilter::make('gateway')->label(__('Gateway'))
                    ->options(fn () => PaymentGatewayEvent::query()->distinct()->orderBy('gateway')->pluck('gateway', 'gateway')),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentGatewayEvents::route('/'),
        ];
    }
}
