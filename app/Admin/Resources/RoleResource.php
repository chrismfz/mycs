<?php

namespace App\Admin\Resources;

use App\Admin\Resources\RoleResource\Pages\CreateRole;
use App\Admin\Resources\RoleResource\Pages\EditRole;
use App\Admin\Resources\RoleResource\Pages\ListRoles;
use App\Classes\PermissionGuard;
use App\Models\Role;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    // One name for the page, WHMCS's, in the top menu, the sidebar and its title (owner 2026-10-21).
    protected static ?string $navigationLabel = 'Staff Roles';

    protected static ?string $pluralModelLabel = 'Staff Roles';

    protected static ?string $modelLabel = 'Staff Role';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-shield-user-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-shield-user-fill';

    public static function form(Schema $schema): Schema
    {
        $extensionPermissions = once(fn () => Arr::dot(
            array_merge_recursive(...Event::dispatch('permissions', []))
        ));

        return $schema
            ->components([
                TextInput::make('name')
                    ->unique(ignoreRecord: true)
                    ->required()
                    ->maxLength(255),
                // Split permission UI into core and extension
                CheckboxList::make('permissions')
                    ->label(false)
                    ->options(array_merge(Arr::dot(config('permissions.role')), $extensionPermissions))
                    ->columns(4)
                    ->bulkToggleable()
                    ->searchable()
                    ->noSearchResultsMessage('Permission could not be found')->columnSpanFull()
                    ->disableOptionWhen(fn (string $value): bool => !PermissionGuard::canGrant(auth()->user(), [$value]))
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                        if (!PermissionGuard::canGrant(auth()->user(), (array) $value)) {
                            $fail('You cannot grant permissions you do not have.');
                        }
                    }),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable(),
                TextColumn::make('permissions_count')
                    ->label('permissions')
                    ->getStateUsing(fn (Role $record): string => in_array('*', $record->permissions) ? __('All') : (string) count($record->permissions))
                    // The full administrators' role is not edited (canEdit), so nobody takes the last «*» away.
                    ->description(fn (Role $record) => $record->id === 1 ? __('Full access, not editable') : null)
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordUrl(fn (Role $record) => static::canEdit($record) ? static::getUrl('edit', ['record' => $record]) : null)
            ->recordActions([
                EditAction::make()->visible(fn (Role $record) => static::canEdit($record)),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function canEdit(Model $record): bool
    {
        return $record->id !== 1 && parent::canEdit($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
