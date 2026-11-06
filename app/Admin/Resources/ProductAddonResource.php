<?php

namespace App\Admin\Resources;

use App\Admin\Resources\ProductAddonResource\Pages\CreateProductAddon;
use App\Admin\Resources\ProductAddonResource\Pages\EditProductAddon;
use App\Admin\Resources\ProductAddonResource\Pages\ListProductAddons;
use App\Models\Product;
use App\Models\Service;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Setup → Product Addons (docs/addons.md, owner 2026-11-04, as WHMCS's): the products fastened to another service (an
 * extra IP, more disk or RAM), what each applies to, and the services that have it. The same products as Products, with
 * their plans and prices; no module, no shop page.
 */
class ProductAddonResource extends ProductResource
{
    protected static bool $addons = true;

    protected static ?string $slug = 'product-addons';

    protected static ?string $navigationLabel = 'Product Addons';

    protected static ?string $modelLabel = 'Product Addon';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-puzzle-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-puzzle-fill';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['plans'])
                ->withCount(['services as running_count' => fn (Builder $services) => $services->whereIn('status', Service::PAYING_STATUSES)]))
            ->columns([
                TextColumn::make('name')->searchable(query: fn (Builder $query, string $search): Builder => $query->where('products.name', 'like', "%{$search}%")),
                TextColumn::make('applies_to')->label('Applies to')->wrap()
                    ->state(fn (Product $record) => self::addonTargetsLabel($record)),
                TextColumn::make('pay_type')->label('Pay Type')
                    ->state(fn (Product $record) => self::payType($record)),
                // The services that have it (running), to their list.
                TextColumn::make('running_count')->label('Services')->numeric()
                    ->url(fn (Product $record) => ServiceResource::getUrl('index', ['filters' => ['product' => ['value' => $record->id]]])),
                TextColumn::make('category.name')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('visibility')->label('Shown')->badge()
                    ->state(fn (Product $record) => $record->retired ? __('Retired') : __('In use'))
                    ->color(fn (Product $record) => $record->retired ? 'gray' : 'success'),
            ])
            ->filters([
                SelectFilter::make('category')->relationship('category', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('sort')->orderBy('name'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductAddons::route('/'),
            'create' => CreateProductAddon::route('/create'),
            'edit' => EditProductAddon::route('/{record}/edit'),
        ];
    }
}
