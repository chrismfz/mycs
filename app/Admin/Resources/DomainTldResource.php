<?php

namespace App\Admin\Resources;

use App\Admin\Resources\DomainTldResource\Pages\CreateDomainTld;
use App\Admin\Resources\DomainTldResource\Pages\EditDomainTld;
use App\Admin\Resources\DomainTldResource\Pages\ImportTlds;
use App\Admin\Resources\DomainTldResource\Pages\ListDomainTlds;
use App\Admin\Resources\DomainTldResource\RelationManagers\PricesRelationManager;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Services\Domains\Pricing\TldCostSync;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use InvalidArgumentException;
use Throwable;

/**
 * WHMCS's «Domain Pricing» (docs/domains.md D-7): the TLDs MyCS sells, each with its registrar for automatic
 * registration, EPP code, addons, grace and redemption days, and its prices per operation, years and currency. «Sync
 * costs» reads the registrar's cost and never touches a price; «Import» is WHMCS's «TLD Import & Pricing Sync».
 */
class DomainTldResource extends Resource
{
    protected static ?string $model = DomainTld::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-price-tag-3-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Domain Pricing';

    protected static ?string $modelLabel = 'TLD';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('TLD')->columns(3)->schema([
                TextInput::make('tld')->label('TLD')->required()->maxLength(63)->prefix('.')
                    ->helperText(__('Without the dot: com, gr, com.gr.'))
                    // Checked in the form it is stored in (review F4): «.COM» is «com», «ελ» is «xn--qxam».
                    ->rule(fn (?DomainTld $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record) {
                        try {
                            $tld = Domain::normalise(ltrim((string) $value, '. '));
                        } catch (InvalidArgumentException) {
                            $fail(__('Not a TLD.'));

                            return;
                        }
                        if (DomainTld::query()->where('tld', $tld)->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists()) {
                            $fail(__('The TLD .:tld exists already.', ['tld' => $tld]));
                        }
                    }),
                Select::make('registrar_connection_id')->label(__('Automatic registration'))
                    ->relationship('registrarConnection', 'label')->placeholder(__('None (by hand)'))
                    ->helperText(__('The registrar its domains are registered and renewed at, unless a domain says otherwise.')),
                Toggle::make('is_active')->label(__('Active'))->default(true),
            ]),
            Section::make(__('Options'))->columns(3)->schema([
                Toggle::make('epp_code_required')->label(__('EPP code for transfers'))->default(true),
                Toggle::make('dns_management')->label(__('DNS management')),
                Toggle::make('email_forwarding')->label(__('Email forwarding')),
                Toggle::make('id_protection')->label(__('ID protection')),
                Toggle::make('allow_idn')->label(__('IDN (non-Latin names)')),
                TextInput::make('sort')->label(__('Order'))->numeric()->minValue(0)->default(0),
            ]),
            Section::make(__('Grace and redemption'))->columns(2)
                ->description(__('Days after expiry. Empty: the registrar\'s, or not offered. Their fees are prices (operations grace and redemption).'))
                ->schema([
                    TextInput::make('grace_days')->label(__('Grace period (days)'))->integer()->minValue(0)->maxValue(365),
                    TextInput::make('redemption_days')->label(__('Redemption period (days)'))->integer()->minValue(0)->maxValue(365),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('tld')->label('TLD')->formatStateUsing(fn (string $state) => '.' . $state)->searchable()->sortable(),
                TextColumn::make('registrarConnection.label')->label(__('Automatic registration'))->placeholder('—'),
                IconColumn::make('epp_code_required')->label('EPP')->boolean(),
                TextColumn::make('grace_days')->label('Grace')->placeholder('—'),
                TextColumn::make('redemption_days')->label('Redemption')->placeholder('—'),
                TextColumn::make('prices_count')->label(__('Prices'))->counts('prices'),
                TextColumn::make('domains_count')->label(__('domains.domains'))->counts('domains'),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->recordActions([self::syncCostsAction(), EditAction::make()]);
    }

    /** The registrar's cost onto this TLD's prices; never a price itself (D-6). */
    public static function syncCostsAction(): Action
    {
        return Action::make('syncCosts')->label(__('Sync costs'))->icon('heroicon-o-arrow-path')
            ->visible(fn (DomainTld $record) => static::canEdit($record) && app(TldCostSync::class)->connection($record) !== null)
            ->action(function (DomainTld $record) {
                try {
                    $changed = app(TldCostSync::class)->sync($record);
                    Notification::make()->success()->title(__(':count cost(s) updated; no price changed.', ['count' => $changed]))->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('The costs could not be read.'))->body(e($e->getMessage()))->send();
                }
            });
    }

    public static function getRelations(): array
    {
        return [PricesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomainTlds::route('/'),
            'create' => CreateDomainTld::route('/create'),
            'import' => ImportTlds::route('/import'),
            'edit' => EditDomainTld::route('/{record}/edit'),
        ];
    }
}
