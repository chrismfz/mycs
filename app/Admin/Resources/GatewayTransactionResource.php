<?php

namespace App\Admin\Resources;

use App\Admin\Clusters\InvoiceCluster;
use App\Admin\Resources\GatewayTransactionResource\Pages\ListGatewayTransactions;
use App\Models\GatewayTransaction;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * «PayPal transactions» (owner 2026-11-12; docs/payments-tracking.md): PayPal's own list as the daily comparison read
 * it (gateways:reconcile), each transaction with how it stands here, and the payments recorded here that PayPal does
 * not list. Read only, but for one thing: staff mark a difference «known / handled» with a note, so that the money
 * check stops repeating it. Ids, dates and amounts; the customer is a click away, on the invoice.
 */
class GatewayTransactionResource extends Resource
{
    protected static ?string $model = GatewayTransaction::class;

    // Its tab's place in the Invoice cluster: Invoices, Transactions, PayPal Transactions (owner 2026-11-13).
    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'PayPal Transactions';

    protected static ?string $pluralModelLabel = 'PayPal Transactions';

    protected static ?string $modelLabel = 'PayPal Transaction';

    protected static ?string $cluster = InvoiceCluster::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-scales-3-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-scales-3-fill';

    protected static ?string $recordTitleAttribute = 'reference';

    public const KINDS = [
        GatewayTransaction::MATCHED => 'Matches',
        GatewayTransaction::NOT_RECORDED => 'Not recorded here',
        GatewayTransaction::NOT_AT_GATEWAY => 'Not at the gateway',
        GatewayTransaction::MISMATCH => 'Other amount or currency',
    ];

    public const STATUSES = [GatewayTransaction::OK => 'Fine', GatewayTransaction::OPEN => 'To see to', GatewayTransaction::ACKNOWLEDGED => 'Known'];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label(__('Date'))->dateTime()->sortable(),
                TextColumn::make('gateway')->label(__('Gateway'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('what')->label(__('What'))->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('reference')->label(__('Gateway id'))->searchable()->copyable(),
                TextColumn::make('amount')->label(__('Amount'))->alignEnd()
                    ->formatStateUsing(fn (GatewayTransaction $record): string => trim($record->amount . ' ' . $record->currency_code)),
                TextColumn::make('recorded_amount')->label(__('Recorded here'))->alignEnd()->placeholder('—')
                    ->formatStateUsing(fn (GatewayTransaction $record): string => trim($record->recorded_amount . ' ' . $record->recorded_currency)),
                TextColumn::make('invoice_id')->label(__('Invoice'))->prefix('#')->placeholder('—')
                    ->url(fn (GatewayTransaction $record): ?string => $record->invoice_id !== null && auth()->user()?->hasPermission('admin.invoices.viewAny')
                        ? InvoiceResource::getUrl('edit', ['record' => $record->invoice_id])
                        : null),
                TextColumn::make('kind')->label(__('Compared'))->badge()
                    ->formatStateUsing(fn (string $state): string => __(self::KINDS[$state] ?? $state))
                    ->color(fn (string $state): string => $state === GatewayTransaction::MATCHED ? 'success' : 'warning'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __(self::STATUSES[$state] ?? $state))
                    ->color(fn (string $state): string => match ($state) {
                        GatewayTransaction::OPEN => 'danger',
                        GatewayTransaction::ACKNOWLEDGED => 'gray',
                        default => 'success',
                    }),
                // How an old-style subscription's payments are told: the subscription or billing agreement that took it.
                TextColumn::make('agreement')->label(__('Subscription / agreement'))->placeholder('—')->searchable()->copyable(),
                TextColumn::make('parent_reference')->label(__('Of payment'))->placeholder('—')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('note')->label(__('Note'))->placeholder('—')->wrap()
                    ->description(fn (GatewayTransaction $record): ?string => $record->acknowledged_at === null ? null
                        : trim(($record->acknowledgedBy->name ?? '') . ' · ' . $record->acknowledged_at->format('d/m/Y H:i'), ' ·')),
            ])
            ->filters([
                Filter::make('differences')->label(__('Differences to see to'))
                    ->query(fn (Builder $query) => $query->where('status', GatewayTransaction::OPEN)),
                SelectFilter::make('status')->label(__('Status'))->options(self::STATUSES),
                SelectFilter::make('kind')->label(__('Compared'))->options(self::KINDS),
                SelectFilter::make('what')->label(__('What'))->options(['payment' => 'Payment', 'refund' => 'Refund', 'reversal' => 'Reversal']),
            ])
            ->recordActions([
                Action::make('acknowledge')->label(__('Known / handled'))->icon('heroicon-o-check')->color('success')
                    ->visible(fn (GatewayTransaction $record): bool => $record->status === GatewayTransaction::OPEN && self::mayMark($record))
                    ->modalHeading(__('Known, not ours, or handled'))
                    ->modalDescription(__('Nothing is recorded, refunded or cancelled by this: it only stops the daily money check from repeating the difference. Say what it was.'))
                    ->schema([Textarea::make('note')->label(__('Note'))->required()->maxLength(1000)->rows(3)])
                    ->action(function (GatewayTransaction $record, array $data): void {
                        if ($record->status !== GatewayTransaction::OPEN || !self::mayMark($record)) {
                            return;
                        }
                        $record->update(['status' => GatewayTransaction::ACKNOWLEDGED, 'note' => (string) $data['note'],
                            'acknowledged_by' => auth('staff')->id(), 'acknowledged_at' => now()]);
                        Notification::make()->success()->title(__('Marked known'))->send();
                    }),
                Action::make('reopen')->label(__('Reopen'))->icon('heroicon-o-arrow-uturn-left')->color('gray')
                    ->visible(fn (GatewayTransaction $record): bool => $record->status === GatewayTransaction::ACKNOWLEDGED && self::mayMark($record))
                    ->requiresConfirmation()
                    ->action(function (GatewayTransaction $record): void {
                        if ($record->status === GatewayTransaction::ACKNOWLEDGED && self::mayMark($record)) {
                            $record->update(['status' => GatewayTransaction::OPEN, 'acknowledged_by' => null, 'acknowledged_at' => null]);
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    private static function mayMark(GatewayTransaction $record): bool
    {
        return (bool) auth()->user()?->can('update', $record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGatewayTransactions::route('/'),
        ];
    }
}
