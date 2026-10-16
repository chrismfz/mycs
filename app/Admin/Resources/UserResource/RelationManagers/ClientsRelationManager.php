<?php

namespace App\Admin\Resources\UserResource\RelationManagers;

use App\Admin\Resources\ClientResource;
use App\Models\Client;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The accounts this login sees (`client_user`): read only in 2a. */
class ClientsRelationManager extends RelationManager
{
    protected static string $relationship = 'clients';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('id')->label('ID'),
                TextColumn::make('name')->label(__('Name')),
                IconColumn::make('owner')->label(__('Owner'))->boolean()
                    ->state(fn (Client $record) => (bool) $record->getRelationValue('pivot')?->owner),
            ])
            ->recordActions([
                Action::make('open')->label(__('Open'))->url(fn (Client $record) => ClientResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
