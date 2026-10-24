<?php

namespace App\Admin\Resources;

use App\Admin\Components\ClientComponent;
use App\Admin\Resources\ProxmoxGuestResource\Pages\ListProxmoxGuests;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Models\Service;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Utilities → Proxmox Guests (owner 2026-10-24): every VM and container of the Proxmox clusters (proxmox:sync, every
 * 15 minutes), each mapped to the customer's service it is, or marked internal. Read-only on the cluster: mapping changes
 * nothing there. A guest no longer listed is kept as gone, with its mapping, for staff to look at.
 */
class ProxmoxGuestResource extends Resource
{
    protected static ?string $model = ProxmoxGuest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-instance-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Utilities';

    protected static ?string $navigationLabel = 'Proxmox Guests';

    protected static ?string $modelLabel = 'Proxmox guest';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('vmid')
            ->modifyQueryUsing(fn (Builder $query) => $query->whereHas('server')->with(['server.settings', 'service.product', 'service.client']))
            ->columns([
                TextColumn::make('server.name')->label('Cluster')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vmid')->label('VMID')->sortable()->searchable(),
                TextColumn::make('name')->sortable()->searchable()
                    ->description(fn (ProxmoxGuest $record) => $record->template ? 'template' : ($record->type === 'lxc' ? 'container' : null)),
                TextColumn::make('node')->sortable(),
                TextColumn::make('pool')->badge()->placeholder('—')->toggleable()
                    ->color(fn (ProxmoxGuest $record) => $record->service_id !== null && $record->pool !== self::pool($record) ? 'danger' : 'gray')
                    ->tooltip(fn (ProxmoxGuest $record) => $record->service_id !== null && $record->pool !== self::pool($record)
                        ? 'Not in the MyCS pool: MyCS will not suspend or destroy it. «Pool command» above.' : null),
                TextColumn::make('status')->badge()->sortable()
                    ->color(fn (?string $state) => match ($state) {
                        'running' => 'success',
                        'stopped' => 'gray',
                        'gone' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('size')->label('CPU / RAM / Disk')
                    ->state(fn (ProxmoxGuest $record) => $record->cpus . ' vCPU · ' . Number::fileSize($record->max_memory) . ' · ' . Number::fileSize($record->max_disk)),
                TextColumn::make('ips')->label('IPs')->listWithLineBreaks()->searchable(query: fn (Builder $query, string $search) => $query->where('ips', 'like', '%' . $search . '%')),
                TextColumn::make('macs')->label('MACs')->listWithLineBreaks()->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('macs', 'like', '%' . strtoupper($search) . '%')),
                TextColumn::make('mapping')->label('Is')->badge()
                    ->state(fn (ProxmoxGuest $record) => match (true) {
                        $record->service !== null => '#' . $record->service_id . ' ' . ($record->service->product->name ?? '') . ' · ' . ($record->service->client->name ?? ''),
                        $record->internal => 'Internal' . (filled($record->note) ? ': ' . $record->note : ''),
                        default => 'Not mapped',
                    })
                    ->color(fn (ProxmoxGuest $record) => $record->service_id !== null ? 'success' : ($record->internal ? 'gray' : 'warning'))
                    ->url(fn (ProxmoxGuest $record) => $record->service_id !== null ? ServiceResource::getUrl('edit', ['record' => $record->service_id]) : null),
                TextColumn::make('uptime')->formatStateUsing(fn ($state) => $state > 0 ? now()->subSeconds((int) $state)->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, parts: 2) : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_seen_at')->label('Last read')->since()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('mapping')->label('Mapping')
                    ->options(['unmapped' => 'Not mapped', 'service' => 'A service', 'internal' => 'Internal'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'unmapped' => ProxmoxGuest::whereUnmapped($query),
                        'service' => $query->whereNotNull('service_id'),
                        'internal' => $query->where('internal', true),
                        default => $query,
                    }),
                SelectFilter::make('server_id')->label('Cluster')->relationship('server', 'name', fn (Builder $query) => $query->where('extension', 'Proxmox')),
                SelectFilter::make('node')->options(fn () => ProxmoxGuest::query()->whereNotNull('node')->distinct()->orderBy('node')->pluck('node', 'node')->all()),
                TernaryFilter::make('outside_pool')->label('Mapped, outside the MyCS pool')
                    ->queries(true: fn (Builder $query) => $query->whereNotNull('service_id')->where(function (Builder $query) {
                        // Each cluster's own pool setting (review of #447).
                        $query->whereRaw('1 = 0');
                        foreach (Server::query()->where('extension', 'Proxmox')->with('settings')->get() as $server) {
                            $pool = trim((string) $server->settings->firstWhere('key', 'pool')?->value) ?: 'mycs';
                            $query->orWhere(fn ($q) => $q->where('server_id', $server->id)->where(fn ($q) => $q->whereNull('pool')->orWhere('pool', '!=', $pool)));
                        }
                    }), false: fn (Builder $query) => $query),
                TernaryFilter::make('gone')->label('Gone from the cluster')
                    ->queries(true: fn (Builder $query) => $query->whereNotNull('gone_at'), false: fn (Builder $query) => $query->whereNull('gone_at'))
                    ->default(false),
            ])
            ->recordActions([
                Action::make('assign')->label('Service')->icon('ri-links-line')
                    ->authorize('update')
                    ->hidden(fn (ProxmoxGuest $record) => $record->template) // a template is never a customer's VM
                    ->fillForm(fn (ProxmoxGuest $record) => ['service_id' => $record->service_id ?? $record->suggestedServiceId()])
                    ->schema([
                        Select::make('service_id')->label('Service')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search) => self::services($search))
                            ->getOptionLabelUsing(fn ($value) => self::serviceLabel(Service::query()->with(['product', 'client'])->find($value)))
                            ->helperText('Suggested from its IPs, MACs or name when one matches a service: check it.'),
                    ])
                    ->action(function (ProxmoxGuest $record, array $data) {
                        $record->update(['service_id' => (int) $data['service_id'], 'internal' => false, 'note' => null, 'onboot_before_suspend' => null]);
                        Notification::make()->success()->title('Mapped')->send();
                    }),
                Action::make('internal')->label('Internal')->icon('ri-home-gear-line')->color('gray')
                    ->authorize('update')
                    ->fillForm(fn (ProxmoxGuest $record) => ['note' => $record->note])
                    ->schema([TextInput::make('note')->label('What it is')->maxLength(255)->placeholder('e.g. our DNS, a test VM')])
                    ->action(function (ProxmoxGuest $record, array $data) {
                        $record->update(['service_id' => null, 'internal' => true, 'note' => $data['note'] ?? null, 'onboot_before_suspend' => null]);
                        Notification::make()->success()->title('Marked internal')->send();
                    }),
                Action::make('unmap')->label('Clear')->icon('ri-link-unlink')->color('gray')
                    ->authorize('update')
                    ->visible(fn (ProxmoxGuest $record) => $record->service_id !== null || $record->internal)
                    ->requiresConfirmation()
                    ->action(fn (ProxmoxGuest $record) => $record->update(['service_id' => null, 'internal' => false, 'note' => null, 'onboot_before_suspend' => null])),
            ]);
    }

    /** The pool MyCS acts in on this guest's cluster (its server's setting). */
    public static function pool(ProxmoxGuest $guest): string
    {
        return trim((string) $guest->server?->settings->firstWhere('key', 'pool')?->value) ?: 'mycs';
    }

    /** @return array<int, string> up to 50 services for a search: by id, domain, dedicated IP or the client's name */
    public static function services(string $search): array
    {
        $search = trim($search);

        return Service::query()->with(['product', 'client'])
            ->where(fn (Builder $query) => $query
                ->when(ctype_digit($search), fn ($q) => $q->orWhereKey((int) $search))
                ->orWhere('domain', 'like', "%{$search}%")
                ->orWhere('dedicated_ip', 'like', "%{$search}%")
                ->orWhere(fn (Builder $query) => ClientComponent::search($query, $search)))
            ->orderByDesc('id')->limit(50)->get()
            ->mapWithKeys(fn (Service $service) => [$service->id => self::serviceLabel($service)])->all();
    }

    public static function serviceLabel(?Service $service): ?string
    {
        if ($service === null) {
            return null;
        }

        return '#' . $service->id . ' ' . ($service->product->name ?? '') . ($service->domain ? ' (' . $service->domain . ')' : '')
            . ' · ' . ($service->client->name ?? '') . ' · ' . $service->status;
    }

    public static function getPages(): array
    {
        return ['index' => ListProxmoxGuests::route('/')];
    }
}
