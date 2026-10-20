<?php

namespace App\Admin\Resources;

use App\Admin\Resources\CouponResource\Pages\CreateCoupon;
use App\Admin\Resources\CouponResource\Pages\EditCoupon;
use App\Admin\Resources\CouponResource\Pages\ListCoupons;
use App\Admin\Resources\CouponResource\RelationManagers\ServicesRelationManager;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-coupon-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-coupon-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Code')
                    ->required()
                    ->maxLength(255)
                    ->unique(static::getModel(), 'code', ignoreRecord: true)
                    ->placeholder('Enter the code of the coupon'),

                TextInput::make('value')
                    ->label('Value')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(fn (Get $get) => $get('type') === 'percentage' ? 100 : null)
                    ->mask(RawJs::make(
                        <<<'JS'
                            $money($input, '.', '', 2)
                        JS
                    ))
                    ->suffix(fn (Get $get) => $get('type') === 'percentage' ? '%' : config('settings.default_currency'))
                    ->helperText(fn (Get $get) => $get('type') === 'override' ? __('The price it applies to becomes this amount (0 = free).') : null)
                    ->placeholder('Enter the value of the coupon'),

                Select::make('type')
                    ->label('Type')
                    ->required()
                    ->default('percentage')
                    ->live()
                    ->options([
                        'percentage' => 'Percentage',
                        'fixed' => 'Fixed amount',
                        // WHMCS «Price Override» (nixpal FREETRIAL 0.00, 25OffAgentNode 1.50).
                        'override' => 'Price override',
                    ])
                    ->placeholder('Select the type of the coupon'),
                Select::make('applies_to')
                    ->label('Applies To')
                    ->required()
                    ->default('all')
                    ->options([
                        'all' => 'Price and Setup Fee',
                        'price' => 'Price only',
                        'setup_fee' => 'Setup Fee only',
                    ]),

                TextInput::make('recurring')
                    ->label('Recurring')
                    ->numeric()
                    ->nullable()
                    ->minValue(0)
                    ->hidden(fn (Get $get) => $get('applies_to') === 'free_setup')
                    ->placeholder('How many billing cycles the discount will be applied')
                    ->helperText('Enter 0 to apply it to all billing cycles, 1 (or leave empty) to apply it only to the first billing cycle, etc.'),

                TextInput::make('max_uses')
                    ->label('Max Uses')
                    ->numeric()
                    ->minValue(0)
                    ->placeholder('Enter the maximum number of total uses of the coupon'),

                TextInput::make('max_uses_per_user')
                    ->label('Max Uses Per User')
                    ->numeric()
                    ->minValue(0)
                    ->placeholder('Enter the maximum number of uses per user'),

                DatePicker::make('starts_at')
                    ->label('Starts At'),

                DatePicker::make('expires_at')
                    ->label('Expires At'),

                Select::make('products')
                    ->label('Products')
                    ->relationship(
                        name: 'products',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query->with('category')
                    )
                    ->getOptionLabelFromRecordUsing(fn (Product $record) => "{$record->name} - {$record->category->name} (#{$record->id})")
                    ->multiple()
                    ->preload()
                    ->placeholder('Select the products that this coupon applies to')
                    ->hint('Leave empty to apply the coupon to all products'),

                // WHMCS's promotion rules (ROADMAP 5): checked in the cart, and again under the coupon's lock at checkout.
                Select::make('billing_cycles')
                    ->label(__('Billing cycles'))
                    ->multiple()
                    ->options(fn () => Plan::query()->where('type', 'recurring')->get(['billing_unit', 'billing_period', 'type'])
                        ->unique(fn (Plan $plan) => Coupon::cycleKey($plan))
                        ->mapWithKeys(fn (Plan $plan) => [Coupon::cycleKey($plan) => ucfirst($plan->cycle())])
                        ->put('one_time', __('product.one_time'))->all())
                    ->hint(__('Leave empty for every billing cycle')),
                Select::make('customers')
                    ->label(__('Customers'))
                    ->required()
                    ->default('all')
                    ->options(['all' => __('Everyone'), 'new' => __('New customers only'), 'existing' => __('Existing customers only (with an active service)')]),
                Toggle::make('once_per_client')
                    ->label(__('Once per customer'))
                    ->helperText(__('A customer who used it once, in any order, cannot use it again.')),
                Select::make('requiredProducts')
                    ->label(__('Requires'))
                    ->relationship(name: 'requiredProducts', titleAttribute: 'name')
                    ->multiple()
                    ->preload()
                    ->hint(__('One of these in the order')),
                Toggle::make('requires_existing')
                    ->label(__('Or held already'))
                    ->helperText(__('A customer with one of the required products active may use it without ordering one.')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('value')->searchable()->formatStateUsing(fn ($record) => ($record->type === 'override' ? '= ' : '') . $record->value . ($record->type === 'percentage' ? '%' : config('settings.default_currency'))),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ServicesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCoupons::route('/'),
            'create' => CreateCoupon::route('/create'),
            'edit' => EditCoupon::route('/{record}/edit'),
        ];
    }
}
