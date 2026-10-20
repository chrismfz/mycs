<?php

namespace App\Admin\Resources;

use App\Admin\Resources\ClientGroupResource\Pages\CreateClientGroup;
use App\Admin\Resources\ClientGroupResource\Pages\EditClientGroup;
use App\Admin\Resources\ClientGroupResource\Pages\ListClientGroups;
use App\Models\ClientGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Customer groups (ROADMAP 5, as WHMCS's Client Groups): a name and colour for staff, a percentage off its members'
 * services (in their price, from the next renewal; a price staff set by hand is kept).
 */
class ClientGroupResource extends Resource
{
    protected static ?string $model = ClientGroup::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-group-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-group-fill';

    public static function getModelLabel(): string
    {
        return __('Client group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Client groups');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                ColorPicker::make('colour')->label(__('Colour')),
                TextInput::make('discount_percent')->label(__('Discount (%)'))
                    ->numeric()->minValue(0)->maxValue(100)->step('0.01')->default(0)->required()
                    ->helperText(__('Off its members\' services, after a coupon, from their next renewal. Never on domains. A price set by hand is kept.')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ColorColumn::make('colour')->label(''),
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('discount_percent')->label(__('Discount (%)'))->sortable(),
                TextColumn::make('clients_count')->label(__('Clients'))->counts('clients')->sortable(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClientGroups::route('/'),
            'create' => CreateClientGroup::route('/create'),
            'edit' => EditClientGroup::route('/{record}/edit'),
        ];
    }
}
