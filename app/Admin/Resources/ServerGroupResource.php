<?php

namespace App\Admin\Resources;

use App\Admin\Resources\ServerGroupResource\Pages\CreateServerGroup;
use App\Admin\Resources\ServerGroupResource\Pages\EditServerGroup;
use App\Admin\Resources\ServerGroupResource\Pages\ListServerGroups;
use App\Models\Server;
use App\Models\ServerGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
                // One module per group (sweep §7): a product only takes servers of its own module from it.
                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                    if (Server::query()->whereKey((array) $value)->distinct()->count('extension') > 1) {
                        $fail(__('A group holds servers of one module only.'));
                    }
                })
                ->helperText('Servers of one module, enabled and with room under their max accounts. «Fill in order» fills them in the order listed here.')
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
            ->recordActions([EditAction::make(), DeleteAction::make()->before(function (DeleteAction $action, ServerGroup $record) {
                // Not while products use it (the model refuses it too).
                if (($products = $record->products()->count()) > 0) {
                    Notification::make()->danger()->title("Used by {$products} product(s)")->body('Choose another group for them first.')->send();
                    $action->cancel();
                }
            })]);
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
