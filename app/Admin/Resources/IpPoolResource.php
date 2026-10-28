<?php

namespace App\Admin\Resources;

use App\Admin\Resources\IpPoolResource\Pages\CreateIpPool;
use App\Admin\Resources\IpPoolResource\Pages\EditIpPool;
use App\Admin\Resources\IpPoolResource\Pages\ListIpPools;
use App\Admin\Resources\IpPoolResource\Pages\ViewIpPool;
use App\Admin\Resources\IpPoolResource\RelationManagers\AddressesRelationManager;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Server;
use Closure;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use InvalidArgumentException;
use MyCS\Proxmox\IpRange;

/**
 * Utilities → IP Pools (docs/proxmox.md, B3): the networks the VPS take their addresses from, each address free, reserved
 * or a service's. MyCS gives them and tells the customer their network settings (the install is from an ISO); nothing
 * is changed on the cluster or the router.
 */
class IpPoolResource extends Resource
{
    protected static ?string $model = IpPool::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-git-merge-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Utilities';

    protected static ?string $navigationLabel = 'IP Pools';

    protected static ?string $modelLabel = 'IP pool';

    public static function form(Schema $schema): Schema
    {
        $valid = fn (Closure $check) => fn (Get $get) => function (string $attribute, $value, Closure $fail) use ($check, $get) {
            if (blank($value)) {
                return;
            }
            try {
                $check((string) $value, $get);
            } catch (InvalidArgumentException $e) {
                $fail($e->getMessage());
            }
        };

        return $schema->components([
            TextInput::make('name')->required()->maxLength(191),
            Select::make('family')->label('Family')->options([4 => 'IPv4: addresses', 6 => 'IPv6: a prefix per service'])->default(4)
                ->required()->live()->disabledOn('edit')
                ->afterStateUpdated(fn ($state, callable $set) => $set('prefix', (int) $state === 6 ? 64 : null)),
            TextInput::make('network')->label('Network')->placeholder(fn (Get $get) => (int) $get('family') === 6 ? '2001:db8::/48' : '192.0.2.0/24')
                ->maxLength(49)->required(fn (Get $get) => (int) $get('family') === 4)
                ->helperText('Its addresses must be in it.')
                // Kept while it has addresses: they were checked against it.
                ->disabled(fn (?IpPool $record) => $record?->addresses()->exists() ?? false)
                ->rule(fn (Get $get, ?IpPool $record) => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                    if (blank($value)) {
                        return;
                    }
                    try {
                        if (IpRange::isV4((string) $value) !== ((int) $get('family') === 4)) {
                            throw new InvalidArgumentException('Not of the pool\'s family.');
                        }
                        $network = IpRange::network((string) $value);
                        // Two pools never over one network: each would give the other's addresses (round 2 of #505).
                        $others = IpPool::query()->whereNotNull('network')->where('family', (int) $get('family'))
                            ->when($record?->id, fn ($q, $id) => $q->whereKeyNot($id))->get();
                        foreach ($others as $other) {
                            if (IpRange::overlaps((string) $other->network, $network)) {
                                throw new InvalidArgumentException("Overlaps the pool «{$other->name}» ({$other->network}).");
                            }
                        }
                    } catch (InvalidArgumentException $e) {
                        $fail($e->getMessage());
                    }
                })
                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? IpRange::network($state) : null)
                ->live(onBlur: true),
            // IPv4: the network's own (IpPool::booted). IPv6: each service's prefix, kept once it has prefixes (ADV-505-2).
            TextInput::make('prefix')->label('Prefix length a service')
                ->visible(fn (Get $get) => (int) $get('family') === 6)
                ->numeric()->integer()->required(fn (Get $get) => (int) $get('family') === 6)->maxValue(128)
                ->minValue(fn (Get $get) => filled($get('network')) ? rescue(fn () => (int) explode('/', IpRange::network((string) $get('network')))[1], 1, false) : 1)
                ->disabled(fn (?IpPool $record) => $record?->addresses()->exists() ?? false)
                ->helperText('e.g. 64: each service gets a /64. At least the network\'s length.'),
            TextInput::make('gateway')->label('Gateway')->ip()->maxLength(45)
                ->rule($valid(function (string $value, Get $get) {
                    if (IpRange::isV4($value) !== ((int) $get('family') === 4)) {
                        throw new InvalidArgumentException('Not of the pool\'s family.');
                    }
                    // In the network, or (IPv6) the router's link-local address, as routers advertise (ADV-505-R2-5).
                    if (filled($get('network')) && !IpRange::contains((string) $get('network'), $value) && !IpRange::contains('fe80::/10', $value)) {
                        throw new InvalidArgumentException('Not in the network.');
                    }
                    // Never an address a customer holds, or may be given (ADV-505-5).
                    if (($conflict = IpPool::gatewayConflict($value)) !== null) {
                        throw new InvalidArgumentException($conflict);
                    }
                })),
            TextInput::make('nameservers')->label('DNS servers')->placeholder('Comma or space separated')->maxLength(191)
                ->rule($valid(function (string $value) {
                    foreach (preg_split('/[\s,]+/', trim($value)) ?: [] as $ns) {
                        if (filter_var($ns, FILTER_VALIDATE_IP) === false) {
                            throw new InvalidArgumentException("{$ns} is not an IP address.");
                        }
                    }
                })),
            Select::make('server_id')->label('Cluster')->nullable()
                ->options(fn () => Server::query()->where('extension', 'Proxmox')->pluck('name', 'id'))
                ->helperText('Where its addresses are used; for staff only.'),
            Textarea::make('note')->rows(2)->columnSpanFull(),
            Toggle::make('add_hosts')->label('Add the network\'s addresses now (without the gateway)')->default(true)->dehydrated(false)
                ->visibleOn('create')->visible(fn (Get $get) => (int) $get('family') === 4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->with('server')->withCount([
                'addresses',
                'addresses as free_count' => fn ($q) => IpAddress::whereFree($q),
                'addresses as held_count' => fn ($q) => IpAddress::whereHeld($q),
            ]))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->description(fn (IpPool $record) => $record->server?->name),
                TextColumn::make('network')->fontFamily('mono')->placeholder('—')->searchable()
                    ->description(fn (IpPool $record) => 'IPv' . $record->family . ' · /' . $record->prefix . ($record->gateway ? ' · gw ' . $record->gateway : '')),
                TextColumn::make('addresses_count')->label('Addresses'),
                TextColumn::make('free_count')->label('Free')->badge()->color(fn ($state) => $state > 0 ? 'success' : 'danger'),
                TextColumn::make('held_count')->label('Held by ended')->badge()->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->tooltip('Addresses still with a terminated or cancelled service: release them on the pool\'s page.'),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [AddressesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIpPools::route('/'),
            'create' => CreateIpPool::route('/create'),
            'view' => ViewIpPool::route('/{record}'),
            'edit' => EditIpPool::route('/{record}/edit'),
        ];
    }
}
