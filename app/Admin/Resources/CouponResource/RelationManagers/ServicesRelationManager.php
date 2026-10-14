<?php

namespace App\Admin\Resources\CouponResource\RelationManagers;

use App\Admin\Resources\ServiceResource;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'services';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('id'),
                TextColumn::make('order.user.name')->label('User'),
                TextColumn::make('product.name')->label('Product'),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->url(fn ($record) => ServiceResource::getUrl('edit', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Takes the coupon off the services (AUTH-9): it deleted the services themselves.
                    BulkAction::make('removeCoupon')
                        ->label('Remove coupon')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each(fn ($service) => $service->update(['coupon_id' => null])))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
