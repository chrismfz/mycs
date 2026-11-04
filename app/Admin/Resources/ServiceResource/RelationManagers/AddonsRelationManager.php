<?php

namespace App\Admin\Resources\ServiceResource\RelationManagers;

use App\Admin\Resources\ServiceResource;
use App\Models\Service;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Its addons (docs/addons.md): each a service of its own, with its price, cycle and due date, opened on its own page.
 * Shown on a service that has any (an addon has none).
 */
class AddonsRelationManager extends RelationManager
{
    protected static string $relationship = 'addons';

    protected static ?string $title = 'Addons';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Service && $ownerRecord->addons()->exists() && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'plan']))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('product.name')->label('Addon')->wrap(),
                TextColumn::make('quantity')->label('Qty'),
                TextColumn::make('price')->label('Price')
                    ->state(fn (Service $record) => (string) $record->formattedPrice),
                TextColumn::make('plan.name')->label('Cycle'),
                TextColumn::make('expires_at')->label('Next Due')->date(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (?string $state) => Service::statusLabel($state))
                    ->color(fn (?string $state) => Service::statusColor($state)),
            ])
            ->recordUrl(fn (Service $record) => ServiceResource::getUrl('edit', ['record' => $record]))
            ->paginated(false);
    }
}
