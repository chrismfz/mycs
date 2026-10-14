<?php

namespace App\Admin\Resources\ClientResource\Pages;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\InvoiceResource;
use App\Models\Currency;
use App\Models\MoneyEvent;
use App\Services\Money\MoneyHistory;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Ιστορικό πίστωσης» (docs/money-trail.md §2, §3, MT-1d), like the WHMCS «Credit Log»: every movement of the
 * customer's credit from the journal, with the balance after it, who did it and the staff reason. Read only: credit is
 * changed on the Credits page.
 */
class ShowCreditHistory extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'moneyEvents';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-history-line';

    public static function getNavigationLabel(): string
    {
        return __('Credit history');
    }

    /** The same permission as the Credits page next to it: balances, who and the staff reasons. */
    public static function canAccess(array $parameters = []): bool
    {
        return (bool) auth()->user()?->hasPermission('admin.credits.viewAny') && parent::canAccess($parameters);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('credit_balance_after')->with(['reverses', 'note', 'actor']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label(__('Date'))->dateTime(),
                TextColumn::make('kind')->label(__('Movement'))->wrap()
                    ->formatStateUsing(fn (MoneyEvent $record): string => MoneyHistory::staffLabel($record)),
                TextColumn::make('amount')->label(__('Amount'))
                    ->formatStateUsing(fn (MoneyEvent $record): string => MoneyHistory::signedCredit($record))
                    ->color(fn (MoneyEvent $record): string => $record->creditEffectCents() < 0 ? 'danger' : 'success'),
                TextColumn::make('credit_balance_after')->label(__('Balance after'))
                    ->formatStateUsing(fn (MoneyEvent $record): string => MoneyHistory::money($record->credit_balance_after, $record->currency_code)),
                TextColumn::make('invoice_id')->label(__('Invoice'))->prefix('#')
                    ->url(fn (MoneyEvent $record): ?string => $record->invoice_id !== null && auth()->user()?->hasPermission('admin.invoices.viewAny')
                        ? InvoiceResource::getUrl(config('settings.immutable_invoices_enabled', false) ? 'view' : 'edit', ['record' => $record->invoice_id])
                        : null),
                TextColumn::make('source')->label(__('Source')),
                TextColumn::make('by')->label(__('By'))
                    ->state(fn (MoneyEvent $record): string => MoneyHistory::actorName($record->actor, $record->actor_type, $record->actor_id)),
                TextColumn::make('note')->label(__('Reason'))->wrap()
                    ->state(fn (MoneyEvent $record): ?string => MoneyHistory::staffNote($record)),
            ])
            ->filters([
                SelectFilter::make('currency_code')->label(__('Currency'))
                    ->options(fn () => Currency::query()->pluck('code', 'code')),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
