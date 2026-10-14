<?php

namespace App\Admin\Resources\ClientResource\RelationManagers;

use App\Admin\Resources\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The logins that see the account (`client_user`, WHMCS «Users» of a client): read only in 2a; inviting, permissions
 * and revoking come with 2b.
 */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                TextColumn::make('name')->label(__('Name')),
                TextColumn::make('email')->label(__('Email')),
                IconColumn::make('owner')->label(__('Owner'))->boolean()
                    ->state(fn (User $record) => (bool) $record->getRelationValue('pivot')?->owner),
                TextColumn::make('last_login_at')->label(__('Last login'))->dateTime()
                    ->state(fn (User $record) => $record->getRelationValue('pivot')?->last_login_at),
            ])
            ->recordActions([
                Action::make('open')->label(__('Open'))->url(fn (User $record) => UserResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
