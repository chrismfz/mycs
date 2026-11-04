<?php

namespace App\Admin\Resources\CategoryResource\RelationManagers;

use App\Admin\Resources\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $relatedResource = ProductResource::class;

    public function table(Table $table): Table
    {
        return $table
            // Addons are on Setup → Product Addons (their edit page is theirs, docs/addons.md).
            ->modifyQueryUsing(fn (Builder $query) => $query->where('is_addon', false))
            ->reorderable('sort')
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
