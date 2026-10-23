<?php

namespace App\Admin\Resources;

use App\Admin\Resources\StaffResource\Pages\CreateStaff;
use App\Admin\Resources\StaffResource\Pages\EditStaff;
use App\Admin\Resources\StaffResource\Pages\ListStaff;
use App\Classes\PermissionGuard;
use App\Livewire\Admin\StaffSessionList;
use App\Models\Role;
use App\Models\Staff;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

/**
 * The staff (docs/accounts.md, PR 1): who enters the admin panel and with which role. Customers are under Users.
 */
class StaffResource extends Resource
{
    protected static ?string $model = Staff::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-user-star-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-user-star-fill';

    protected static ?string $modelLabel = 'staff member';

    protected static ?string $pluralModelLabel = 'staff';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('first_name')->translateLabel()->required()->maxLength(255),
                TextInput::make('last_name')->translateLabel()->required()->maxLength(255),
                // Unique among staff only: the same address may also be a customer's (two accounts, never one).
                TextInput::make('email')->translateLabel()->email()->required()->maxLength(255)
                    ->unique('staff', 'email', ignoreRecord: true),
                TextInput::make('password')->translateLabel()->password()->revealable()
                    ->rule('min:12')
                    ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create'),
                Select::make('role_id')
                    ->translateLabel()
                    ->relationship('role', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    // Nobody grants what they do not have (PermissionGuard).
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                        $role = $value ? Role::query()->find($value) : null;
                        if ($role && !PermissionGuard::canGrant(auth()->user(), $role->permissions ?? [])) {
                            $fail(__('You cannot assign a role with permissions you do not have.'));
                        }
                    }),
                // Disabled: no login, no API key. Staff with history are disabled, not deleted (DATA-3).
                Toggle::make('active')->translateLabel()->default(true)
                    ->disabled(fn (?Staff $record): bool => $record !== null && $record->is(auth()->user())),
                // A lost laptop: the member's sessions, to end (StaffSessions); their own are in their profile.
                Section::make(__('Sessions'))->collapsible()->columnSpanFull()
                    ->visible(fn (?Staff $record, string $operation): bool => $operation === 'edit' && $record !== null
                        && (bool) auth()->user()?->hasPermission('admin.staff.sessions'))
                    ->schema([Livewire::make(StaffSessionList::class, fn (Staff $record) => ['staffId' => $record->id])->key('staff-sessions')]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('first_name')->searchable()->sortable(),
                TextColumn::make('last_name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('role.name')->sortable(),
                IconColumn::make('active')->boolean(),
                IconColumn::make('two_factor')
                    ->label(__('2FA'))
                    ->boolean()
                    ->getStateUsing(fn (Staff $record): bool => filled($record->app_authentication_secret)),
                TextColumn::make('last_login_at')->dateTime()->sortable()->placeholder(__('Never')),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaff::route('/'),
            'create' => CreateStaff::route('/create'),
            'edit' => EditStaff::route('/{record}/edit'),
        ];
    }
}
