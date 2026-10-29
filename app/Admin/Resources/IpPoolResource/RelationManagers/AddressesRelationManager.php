<?php

namespace App\Admin\Resources\IpPoolResource\RelationManagers;

use App\Admin\Resources\ProxmoxGuestResource;
use App\Admin\Resources\ServiceResource;
use App\Exceptions\DisplayException;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\ProxmoxGuest;
use App\Models\Service;
use App\Services\Ip\IpAllocator;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * A pool's addresses: free, reserved, a service's, or still with one that ended; and the VM each is seen on, from the
 * cluster's last read, so one in use but not given (a VM set up by hand) or on another service's VM shows.
 */
class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    protected static ?string $title = 'Addresses';

    private const STATES = [IpAddress::FREE => 'Free', IpAddress::ASSIGNED => 'Assigned', IpAddress::HELD => 'Held by an ended service', IpAddress::RESERVED => 'Reserved'];

    public function isReadOnly(): bool
    {
        return false;
    }

    private static function canManage(): bool
    {
        return (bool) Auth::guard('staff')->user()?->hasPermission('admin.servers.update');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('address')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['service.product', 'service.client'])->orderBy('id'))
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('address')->fontFamily('mono')->searchable()->copyable(),
                TextColumn::make('state')->badge()->state(fn (IpAddress $record) => self::STATES[$record->state()])
                    ->color(fn (IpAddress $record) => match ($record->state()) {
                        IpAddress::FREE => 'success',
                        IpAddress::ASSIGNED => 'info',
                        IpAddress::HELD => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (IpAddress $record) => $record->reserved ? $record->note : null),
                TextColumn::make('service')->label('Service')->placeholder('—')
                    ->state(fn (IpAddress $record) => $record->service ? '#' . $record->service_id . ' ' . ($record->service->product->name ?? '') . ' · ' . ($record->service->client->name ?? '') : null)
                    ->limit(40)
                    ->url(fn (IpAddress $record) => $record->service_id ? ServiceResource::getUrl('edit', ['record' => $record->service_id]) : null),
                TextColumn::make('seen')->label('Seen on')->badge()->placeholder('—')
                    ->state(fn (IpAddress $record) => array_map(fn (ProxmoxGuest $guest) => 'VM ' . $guest->vmid . ' ' . $guest->name, $record->seenOn()) ?: null)
                    ->color(fn (IpAddress $record) => $this->mismatchOf($record) !== null ? 'danger' : 'gray')
                    ->tooltip(fn (IpAddress $record) => $this->mismatchOf($record)),
                TextColumn::make('assigned_at')->label('Since')->since()->placeholder('—')->toggleable(),
                TextColumn::make('released_at')->label('Released')->since()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('state')->options(self::STATES)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        IpAddress::FREE => IpAddress::whereFree($query),
                        IpAddress::RESERVED => $query->where('reserved', true),
                        IpAddress::HELD => IpAddress::whereHeld($query),
                        IpAddress::ASSIGNED => $query->whereHas('service', fn (Builder $s) => $s->whereNotIn('status', IpAddress::ENDED)),
                        default => $query,
                    }),
            ])
            ->headerActions([
                Action::make('add')->label('Add addresses')->icon('heroicon-o-plus')->visible(fn () => self::canManage())
                    ->schema([
                        Textarea::make('lines')->label(fn () => $this->pool()->family === 6 ? 'Prefixes, one a line' : 'Addresses, ranges or networks, one a line')
                            ->placeholder(fn () => $this->pool()->family === 6 ? "2001:db8:0:1::/64\n2001:db8:0:2::" : "192.0.2.10\n192.0.2.20-192.0.2.30\n192.0.2.64/27")
                            ->rows(6)->required(),
                    ])
                    ->action(function (array $data, Action $action) {
                        try {
                            $result = $this->pool()->add((string) $data['lines']);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Nothing added')->body(e($e->getMessage()))->send();
                            $action->halt();

                            return;
                        }
                        Notification::make()->success()->title("{$result['added']} added")
                            ->body($result['skipped'] !== [] ? count($result['skipped']) . ' already in a pool: ' . implode(', ', array_slice($result['skipped'], 0, 10)) : null)->send();
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('assign')->label('Give to a service')->icon('heroicon-o-link')
                        ->visible(fn (IpAddress $record) => self::canManage() && $record->state() === IpAddress::FREE && !isset($this->forbidden()['v4'][$record->address]))
                        ->schema([
                            Select::make('service_id')->label('Service')->required()->searchable()
                                ->getSearchResultsUsing(fn (string $search) => self::services($search))
                                ->getOptionLabelUsing(fn ($value) => ProxmoxGuestResource::serviceLabel(Service::query()->with(['product', 'client'])->find($value))),
                        ])
                        ->action(fn (IpAddress $record, array $data) => self::attempt(fn () => IpAllocator::assign(
                            Service::query()->findOrFail($data['service_id']), $this->pool(), $record), 'Given')),
                    Action::make('release')->label('Release')->icon('heroicon-o-link-slash')->color('warning')
                        ->visible(fn (IpAddress $record) => self::canManage() && $record->service_id !== null)
                        ->requiresConfirmation()
                        ->modalDescription(fn (IpAddress $record) => "{$record->address} back to the pool, from service #{$record->service_id}. Nothing changes on the VM: take it off there too.")
                        ->action(fn (IpAddress $record) => self::attempt(fn () => IpAllocator::release($record, $record->service_id), 'Released')),
                    Action::make('reserve')->label('Reserve')->icon('heroicon-o-lock-closed')
                        ->visible(fn (IpAddress $record) => self::canManage() && $record->state() === IpAddress::FREE)
                        ->schema([TextInput::make('note')->label('What it is (ours, a router\'s)')->maxLength(191)])
                        ->action(fn (IpAddress $record, array $data) => self::attempt(fn () => IpAllocator::reserve($record, true, $data['note'] ?? null), 'Reserved')),
                    Action::make('unreserve')->label('Back to the pool')->icon('heroicon-o-lock-open')
                        ->visible(fn (IpAddress $record) => self::canManage() && $record->reserved)
                        ->requiresConfirmation()
                        ->action(fn (IpAddress $record) => self::attempt(fn () => IpAllocator::reserve($record, false), 'Free')),
                    // Only one no service holds as it is deleted (review of #505: a tab giving it meanwhile).
                    DeleteAction::make()->visible(fn (IpAddress $record) => self::canManage() && $record->service_id === null)
                        ->using(fn (IpAddress $record) => IpAddress::query()->whereKey($record->id)->whereNull('service_id')->delete() === 1),
                ]),
            ])
            ->toolbarActions([]);
    }

    /**
     * What is wrong with where it is seen: in use on a VM though no one's (a VM set up by hand: give it, or reserve it),
     * or on a VM of another service than the one it is given to.
     */
    public static function mismatch(IpAddress $address): ?string
    {
        foreach ($address->seenOn() as $guest) {
            if ($address->service_id === null && !$address->reserved) {
                return "In use on VM {$guest->vmid}, but free here: give it to " . ($guest->service_id ? "service #{$guest->service_id}" : 'its service') . ', or reserve it.';
            }
            if ($address->service_id !== null && $guest->service_id !== null && $guest->service_id !== $address->service_id) {
                return "On VM {$guest->vmid}, which is service #{$guest->service_id}'s, not #{$address->service_id}'s.";
            }
        }

        return null;
    }

    /** @var array{v4: array<string, string>, gateways6: list<string>}|null */
    private ?array $forbidden = null;

    /** @return array{v4: array<string, string>, gateways6: list<string>} IpPool::forbidden(), once a request */
    private function forbidden(): array
    {
        return $this->forbidden ??= IpPool::forbidden();
    }

    /** @var array<int, string|null> each row's, once a request */
    private array $mismatches = [];

    private function mismatchOf(IpAddress $address): ?string
    {
        return array_key_exists($address->id, $this->mismatches) ? $this->mismatches[$address->id] : ($this->mismatches[$address->id] = self::mismatch($address));
    }

    private function pool(): IpPool
    {
        /** @var IpPool */
        return $this->getOwnerRecord();
    }

    /** @return array<int, string> the guests page's search, without ended services (they are given nothing) */
    private static function services(string $search): array
    {
        $ended = Service::query()->whereIn('status', IpAddress::ENDED)->whereKey(array_keys($found = ProxmoxGuestResource::services($search)))->pluck('id')->all();

        return array_diff_key($found, array_flip($ended));
    }

    private static function attempt(callable $change, string $done): void
    {
        try {
            $change();
        } catch (DisplayException $e) {
            Notification::make()->danger()->title('Not done')->body(e($e->getMessage()))->send();

            return;
        }
        Notification::make()->success()->title($done)->send();
    }
}
