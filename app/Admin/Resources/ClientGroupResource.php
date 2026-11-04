<?php

namespace App\Admin\Resources;

use App\Admin\Resources\ClientGroupResource\Pages\CreateClientGroup;
use App\Admin\Resources\ClientGroupResource\Pages\EditClientGroup;
use App\Admin\Resources\ClientGroupResource\Pages\ListClientGroups;
use App\Models\ClientGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
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
                // A hex colour only: the only form shown on its badges (ClientGroup::safeColour; review of #574).
                ColorPicker::make('colour')->label(__('Colour'))->regex(ClientGroup::COLOUR),
                TextInput::make('discount_percent')->label(__('Discount (%)'))
                    ->numeric()->minValue(0)->maxValue(100)->step('0.01')->default(0)->required()
                    ->helperText(__('Off its members\' services, after a coupon, from their next renewal. Never on a price kept by hand.')),
                // Owner 2026-11-05: a deal on domains that follows the catalog (their TLD's price less this), never one kept by hand.
                Toggle::make('applies_to_domains')->label(__('Also on domains'))
                    ->helperText(__('Off its members\' domain renewals at their TLD\'s price, from their next renewal invoice. Never on a domain\'s own renewal price, nor on grace or redemption fees.')),
                // WHMCS's group settings (sweep of myip, 2026-10-25): each holds for every member, whatever the member's own.
                Toggle::make('separate_invoices')->label(__('Separate invoices'))
                    ->helperText(__('One invoice per service renewal, instead of one per due date.')),
                Toggle::make('dunning_exempt')->label(__('No automatic suspension or termination'))
                    ->helperText(__('The cron never suspends or terminates its members\' services for non-payment; staff still can. Turned off, their overdue services are suspended on the next run, and terminated soon after if long overdue.')),
                Toggle::make('no_overdue_reminders')->label(__('No overdue reminders'))
                    ->helperText(__('No reminder after the due date (the one before it is still sent).')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ColorColumn::make('colour')->label(''),
                // On its colour, as its clients are marked (owner 2026-11-01).
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable()
                    ->formatStateUsing(fn (ClientGroup $record) => $record->badge())->html(),
                TextColumn::make('discount_percent')->label(__('Discount (%)'))->sortable(),
                // What it gives, each as WHMCS's Client Groups page lists it.
                IconColumn::make('dunning_exempt')->label(__('No suspension'))->boolean()->toggleable(),
                IconColumn::make('separate_invoices')->label(__('Separate invoices'))->boolean()->toggleable(),
                IconColumn::make('no_overdue_reminders')->label(__('No overdue reminders'))->boolean()->toggleable(),
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
