<?php

namespace App\Admin\Resources;

use App\Admin\Resources\OfferResource\Pages\CreateOffer;
use App\Admin\Resources\OfferResource\Pages\EditOffer;
use App\Admin\Resources\OfferResource\Pages\ListOffers;
use App\Models\Offer;
use App\Models\Plan;
use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Offers (ROADMAP 6α, owner 2026-10-21): a link that puts products in the cart and applies a coupon, set up here
 * (nixpal's «Zonecloud Kit»: Controller, Agent and Node with FREETRIAL; WHMCS's Bundles). The coupon keeps its own rules.
 */
class OfferResource extends Resource
{
    protected static ?string $model = Offer::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-gift-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-gift-fill';

    public static function getModelLabel(): string
    {
        return __('Offer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Offers');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255)->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, Get $get, ?string $state) => $get('slug') ? null : $set('slug', Str::slug((string) $state))),
            TextInput::make('slug')->label(__('Link'))->required()->maxLength(100)->alphaDash()->unique(ignoreRecord: true)
                ->prefix(fn () => url('offer') . '/')
                ->helperText(__('The offer\'s address: share it, or put it on a button of your site.')),
            Textarea::make('description')->label(__('Description'))->rows(2)->helperText(__('For staff.')),
            Select::make('coupon_id')->label(__('Coupon'))->relationship('coupon', 'code')->searchable()->preload()
                ->helperText(__('Applied with the products; its own rules (new customers, once per customer…) still apply at checkout.')),
            Toggle::make('active')->label(__('Active'))->default(true),
            DateTimePicker::make('starts_at')->label(__('Starts at')),
            DateTimePicker::make('expires_at')->label(__('Expires at'))->after('starts_at'),
            Repeater::make('items')->label(__('Products'))->relationship()->orderColumn('sort')->minItems(1)->maxItems(15)->defaultItems(1)
                ->schema([
                    Select::make('product_id')->label(__('Product'))->required()->live()
                        ->afterStateUpdated(fn (Set $set) => $set('plan_id', null)) // a cycle of the product chosen, never the previous one's
                        ->options(fn () => Product::query()->orderBy('name')->pluck('name', 'id'))->searchable(),
                    Select::make('plan_id')->label(__('Billing cycle'))
                        ->options(fn (Get $get) => $get('product_id') ? Plan::query()->where('priceable_type', Product::class)
                            ->where('priceable_id', $get('product_id'))->pluck('name', 'id') : [])
                        ->placeholder(__('Its first')),
                    TextInput::make('quantity')->label(__('Quantity'))->integer()->minValue(1)->default(1)->required()
                        ->helperText(__('More than 1 only for a product whose quantity the customer chooses.')),
                ])->columns(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('Name'))->searchable(),
            TextColumn::make('slug')->label(__('Link'))->formatStateUsing(fn (Offer $record) => $record->url())->copyable(),
            TextColumn::make('coupon.code')->label(__('Coupon')),
            TextColumn::make('items_count')->label(__('Products'))->counts('items'),
            IconColumn::make('active')->label(__('Active'))->boolean(),
        ])->recordActions([EditAction::make()])->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOffers::route('/'),
            'create' => CreateOffer::route('/create'),
            'edit' => EditOffer::route('/{record}/edit'),
        ];
    }
}
