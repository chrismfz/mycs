<?php

namespace App\Admin\Resources\ClientResource\RelationManagers;

use App\Admin\Resources\UserResource;
use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Models\Client;
use App\Models\ClientInvite;
use App\Models\ClientUser;
use App\Models\Staff;
use App\Models\User;
use App\Support\Actor;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The logins that see the account (`client_user`, WHMCS «Users» of a client; docs/accounts.md, PR 2b): staff add an
 * existing login or invite an email, set the WHMCS permissions, take access away (kept as history) and make another
 * login the owner.
 */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'grants';

    protected static ?string $title = 'Users';

    public function isReadOnly(): bool
    {
        return false;
    }

    private static function canManage(): bool
    {
        return (bool) Auth::guard('staff')->user()?->hasPermission('admin.clients.update');
    }

    private static function staff(): ?Staff
    {
        $staff = Auth::guard('staff')->user();

        return $staff instanceof Staff ? $staff : null;
    }

    /**
     * The permissions staff may tick: the ones they could use in «Login as client», and the ones the login has already
     * (kept, never added past the role: AUD3-1).
     *
     * @return array<string, string>
     */
    private static function permissionOptions(?ClientUser $grant = null): array
    {
        $had = $grant->permissions ?? [];

        return collect(ClientUser::PERMISSIONS)
            ->filter(fn (string $name) => in_array($name, $had, true) || Actor::staffMayGrant(self::staff(), [$name]))
            ->mapWithKeys(fn (string $name) => [$name => __('account.permission.' . $name)])->all();
    }

    /** @return array<int, mixed> */
    private static function permissionFields(?ClientUser $grant = null): array
    {
        $all = Actor::staffMayGrant(self::staff(), null, $grant->permissions ?? []);

        return [
            Toggle::make('all')->label(__('account.all_permissions'))->default($all && ($grant === null || $grant->permissions === null))
                ->visible($all)->live(),
            CheckboxList::make('permissions')->label(__('account.permissions'))->options(self::permissionOptions($grant))
                ->default($grant->permissions ?? [])->columns(2)
                ->hidden(fn (Get $get) => (bool) $get('all')),
        ];
    }

    /**
     * Refused, and staff told, when the permissions go past what their role could use as the customer (AUD3-1).
     *
     * @param  list<string>|null  $permissions
     * @param  list<string>|null  $had
     */
    private static function refusedPastTheRole(?array $permissions, ?array $had = []): bool
    {
        if (Actor::staffMayGrant(self::staff(), $permissions, $had)) {
            return false;
        }
        Notification::make()->danger()->title(__('account.grant_past_role'))->send();

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>|null
     */
    private static function chosen(array $data): ?array
    {
        return !empty($data['all']) ? null : array_values((array) ($data['permissions'] ?? []));
    }

    public function table(Table $table): Table
    {
        /** @var Client $client */
        $client = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('user.email')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'grantedBy'])->orderByDesc('owner')->orderBy('id'))
            ->columns([
                TextColumn::make('user.name')->label(__('Name')),
                TextColumn::make('user.email')->label(__('Email')),
                IconColumn::make('owner')->label(__('Owner'))->boolean(),
                TextColumn::make('permissions')->label(__('account.permissions'))->wrap()
                    ->state(fn (ClientUser $record) => $record->owner || $record->permissions === null
                        ? __('account.all_permissions')
                        : collect($record->permissions)->map(fn ($name) => __('account.permission.' . $name))->join(', ')),
                TextColumn::make('granted_at')->label(__('Granted'))->dateTime()
                    ->description(fn (ClientUser $record) => $record->grantedBy?->name),
                TextColumn::make('last_login_at')->label(__('Last login'))->dateTime(),
                TextColumn::make('revoked_at')->label(__('Revoked'))->dateTime()->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('revoked')->label(__('Revoked'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('revoked_at'),
                        false: fn (Builder $query) => $query->whereNull('revoked_at'),
                    )->default(false),
            ])
            ->headerActions([
                Action::make('add')->label(__('Add existing user'))->icon('heroicon-o-user-plus')
                    ->visible(fn () => self::canManage())
                    ->schema([
                        Select::make('user_id')->label(__('User'))->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search) => User::query()->where('email', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")
                                ->limit(20)->get()->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} <{$user->email}>"])->all())
                            ->getOptionLabelUsing(fn ($value) => User::query()->find($value)?->email),
                        ...self::permissionFields(),
                    ])
                    ->action(function (array $data) use ($client) {
                        if (self::refusedPastTheRole(self::chosen($data))) {
                            return;
                        }
                        $client->grantTo(User::query()->findOrFail($data['user_id']), self::chosen($data), self::staff());
                        Notification::make()->success()->title(__('account.permissions_saved'))->send();
                    }),
                Action::make('invite')->label(__('account.invite_user'))->icon('heroicon-o-envelope')
                    ->visible(fn () => self::canManage())
                    ->schema([
                        TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(255),
                        ...self::permissionFields(),
                    ])
                    ->action(function (array $data) use ($client) {
                        if (self::refusedPastTheRole(self::chosen($data))) {
                            return;
                        }
                        [$invite, $token] = ClientInvite::issue($client, $data['email'], self::chosen($data), self::staff());
                        NotificationHelper::clientInviteNotification($invite, $token);
                        Notification::make()->success()->title(__('account.invite_sent'))->send();
                    }),
            ])
            ->recordActions([
                Action::make('permissions')->label(__('account.permissions'))->icon('heroicon-o-key')
                    ->visible(fn (ClientUser $record) => self::canManage() && !$record->owner && $record->revoked_at === null)
                    ->schema(fn (ClientUser $record) => self::permissionFields($record))
                    ->action(function (ClientUser $record, array $data) use ($client) {
                        if (self::refusedPastTheRole(self::chosen($data), $record->permissions)) {
                            return;
                        }
                        $client->grantTo($record->user, self::chosen($data), self::staff());
                        Notification::make()->success()->title(__('account.permissions_saved'))->send();
                    }),
                Action::make('owner')->label(__('Make owner'))->icon('heroicon-o-star')->requiresConfirmation()
                    ->modalDescription(__('The current owner keeps access with all permissions.'))
                    ->visible(fn (ClientUser $record) => self::canManage() && !$record->owner && $record->revoked_at === null
                        && Actor::staffMayGrant(self::staff(), null, $record->permissions))
                    ->action(function (ClientUser $record) use ($client) {
                        // The owner has every permission.
                        if (!self::refusedPastTheRole(null, $record->permissions)) {
                            $client->transferOwnership($record->user, self::staff());
                        }
                    }),
                Action::make('revoke')->label(__('account.remove_access'))->icon('heroicon-o-no-symbol')->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ClientUser $record) => self::canManage() && !$record->owner && $record->revoked_at === null)
                    ->action(function (ClientUser $record) use ($client) {
                        try {
                            $client->revoke($record);
                        } catch (DisplayException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                Action::make('restore')->label(__('Restore access'))->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (ClientUser $record) => self::canManage() && $record->revoked_at !== null)
                    ->action(function (ClientUser $record) use ($client) {
                        if (!self::refusedPastTheRole($record->permissions)) {
                            $client->grantTo($record->user, $record->permissions, self::staff());
                        }
                    }),
                Action::make('open')->label(__('Open'))
                    ->url(fn (ClientUser $record) => UserResource::getUrl('edit', ['record' => $record->user_id])),
            ]);
    }
}
