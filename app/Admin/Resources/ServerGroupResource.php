<?php

namespace App\Admin\Resources;

use App\Admin\Resources\ServerGroupResource\Pages\CreateServerGroup;
use App\Admin\Resources\ServerGroupResource\Pages\EditServerGroup;
use App\Admin\Resources\ServerGroupResource\Pages\ListServerGroups;
use App\Models\ServerGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** WHMCS's server groups: a product's new accounts go to one of its servers, by the group's fill rule. */
class ServerGroupResource extends Resource
{
    protected static ?string $model = ServerGroup::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-stack-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Extensions';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Radio::make('fill_type')->label('Fill Type')->required()->default(ServerGroup::FILL_LEAST_FULL)->options([
                ServerGroup::FILL_LEAST_FULL => 'Add to the least full server',
                ServerGroup::FILL_IN_ORDER => 'Fill the servers in order until full',
            ]),
            // In the order chosen: «fill in order» goes by it (review F2).
            Select::make('servers')->label('Servers')->multiple()->relationship('servers', 'name')->preload()
                ->saveRelationshipsUsing(fn (ServerGroup $record, ?array $state) => $record->servers()->sync(
                    collect($state ?? [])->values()->mapWithKeys(fn ($id, int $position) => [(int) $id => ['position' => $position]])->all()))
                ->helperText('Enabled servers of the product\'s module, with room under their max accounts. «Fill in order» fills them in the order listed here.')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('servers.name')->label('Servers')->badge(),
                TextColumn::make('fill_type')->label('Fill Type')
                    ->formatStateUsing(fn (string $state) => $state === ServerGroup::FILL_IN_ORDER ? 'In order' : 'Least full'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServerGroups::route('/'),
            'create' => CreateServerGroup::route('/create'),
            'edit' => EditServerGroup::route('/{record}/edit'),
        ];
    }
}
