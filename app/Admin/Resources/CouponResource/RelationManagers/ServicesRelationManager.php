<?php

namespace App\Admin\Resources\CouponResource\RelationManagers;

use App\Admin\Resources\ServiceResource;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'services';

    public function table(Table $table): Table
    {
        return $table
            // The account's name is its owner's (until the contacts, PR 3): loaded with the rows, not per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order.client.primaryContact', 'order.client.ownerGrant.user']))
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('id'),
                TextColumn::make('order.client.name')->label('Client'),
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
