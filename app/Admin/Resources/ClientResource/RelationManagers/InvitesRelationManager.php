<?php

namespace App\Admin\Resources\ClientResource\RelationManagers;

use App\Models\ClientInvite;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/** The account's invitations (docs/accounts.md, PR 2b): who was invited, by whom, and what became of it. */
class InvitesRelationManager extends RelationManager
{
    protected static string $relationship = 'invites';

    protected static ?string $title = 'Invitations';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['invitedByUser', 'invitedByStaff'])->latest())
            ->columns([
                TextColumn::make('email')->label(__('Email')),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->state(fn (ClientInvite $record) => match (true) {
                        $record->accepted_at !== null => __('Accepted'),
                        $record->revoked_at !== null => __('Cancelled'),
                        $record->expires_at->isPast() => __('Expired'),
                        default => __('Pending'),
                    }),
                TextColumn::make('invited_by')->label(__('Invited by'))
                    ->state(fn (ClientInvite $record) => $record->invitedByStaff?->name ?? $record->invitedByUser?->name),
                TextColumn::make('created_at')->label(__('Sent'))->dateTime(),
                TextColumn::make('expires_at')->label(__('account.expires'))->dateTime(),
            ])
            ->recordActions([
                Action::make('cancel')->label(__('account.cancel_invite'))->color('danger')->requiresConfirmation()
                    ->visible(fn (ClientInvite $record) => $record->isPending()
                        && (bool) Auth::guard('staff')->user()?->hasPermission('admin.clients.update'))
                    ->action(fn (ClientInvite $record) => $record->forceFill(['revoked_at' => now()])->save()),
            ]);
    }
}
