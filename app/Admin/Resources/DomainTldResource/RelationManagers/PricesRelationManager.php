<?php

namespace App\Admin\Resources\DomainTldResource\RelationManagers;

use App\Models\Currency;
use App\Models\DomainTldPrice;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * A TLD's prices (docs/domains.md D-5, D-6, D-8): per operation, years and currency of MyCS. An empty price is not
 * offered; 0.00 is free and offered (a .gr transfer). The cost is the registrar's, written by the sync or the import.
 */
class PricesRelationManager extends RelationManager
{
    protected static string $relationship = 'prices';

    protected static ?string $title = 'Prices';

    public function isReadOnly(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    public static function operations(): array
    {
        return [
            'register' => __('Register'),
            'renew' => __('Renew'),
            'transfer' => __('Transfer'),
            'grace' => __('Grace fee'),
            'redemption' => __('Redemption fee'),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('operation')->label(__('Operation'))->options(self::operations())->required()->live(),
            Select::make('years')->label(__('Years'))->required()->default(1)
                ->options(fn (Get $get) => in_array($get('operation'), ['register', 'renew'], true) ? array_combine(range(1, 10), range(1, 10)) : [1 => 1])
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                    ->where('domain_tld_id', $this->getOwnerRecord()->getKey())
                    ->where('operation', $get('operation'))->where('currency_code', $get('currency_code'))),
            Select::make('currency_code')->label(__('Currency'))->required()
                ->options(fn () => Currency::query()->orderBy('code')->pluck('code', 'code')),
            TextInput::make('price')->label(__('Price'))->numeric()->minValue(0)->maxValue(99999)
                ->helperText(__('Empty: not offered. 0.00: free and offered.')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->orderByRaw("case operation when 'register' then 1 when 'renew' then 2 when 'transfer' then 3 when 'grace' then 4 else 5 end")
                ->orderBy('years')->orderBy('currency_code'))
            ->columns([
                TextColumn::make('operation')->label(__('Operation'))->formatStateUsing(fn (string $state) => self::operations()[$state] ?? $state),
                TextColumn::make('years')->label(__('Years')),
                TextColumn::make('currency_code')->label(__('Currency')),
                TextColumn::make('price')->label(__('Price'))->placeholder(__('Not offered')),
                TextColumn::make('cost')->label(__('Cost'))
                    ->formatStateUsing(fn (DomainTldPrice $record) => $record->cost === null ? '—' : $record->cost . ' ' . $record->cost_currency),
            ])
            ->filters([
                SelectFilter::make('currency_code')->label(__('Currency'))->options(fn () => Currency::query()->orderBy('code')->pluck('code', 'code')),
                SelectFilter::make('operation')->label(__('Operation'))->options(self::operations()),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
