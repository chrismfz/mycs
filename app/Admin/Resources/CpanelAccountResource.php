<?php

namespace App\Admin\Resources;

use App\Admin\Resources\CpanelAccountResource\Pages\ListCpanelAccounts;
use App\Models\CpanelAccount;
use App\Models\Server;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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
use Illuminate\Support\Str;

/**
 * Utilities → Hosting Accounts (owner 2026-10-28: «what goes on in each server», hosting being the main product): every
 * account of the WHM servers (cpanel:sync, every 30 minutes) with its package, owner, disk, inodes and bandwidth against
 * their limits, suspension and outgoing mail held or suspended, each mapped to the customer's service it is, or
 * marked internal. Read-only on the server: mapping changes nothing there.
 */
class CpanelAccountResource extends Resource
{
    protected static ?string $model = CpanelAccount::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-server-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Utilities';

    protected static ?string $navigationLabel = 'Hosting Accounts';

    protected static ?string $modelLabel = 'hosting account';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('user')
            ->modifyQueryUsing(function (Builder $query) {
                $query->whereHas('server')->with(['server', 'service.product', 'service.client']);
                CpanelAccount::withLvePeaks($query);
            })
            ->columns([
                // Shown by itself when there is more than one cPanel server.
                TextColumn::make('server.name')->label('Server')->sortable()
                    ->toggleable(isToggledHiddenByDefault: Server::query()->where('extension', 'CPanel')->count() <= 1),
                TextColumn::make('user')->sortable()->searchable()
                    ->description(fn (CpanelAccount $record) => $record->domain),
                TextColumn::make('domain')->searchable()->toggleable(isToggledHiddenByDefault: true),
                // Cut short (a reseller's «owner_long-package-name» widened the table past the page: owner 2026-10-28), whole on hover.
                TextColumn::make('plan')->label('Package')->sortable()->searchable()->toggleable()
                    ->limit(24)->tooltip(fn (CpanelAccount $record) => mb_strlen((string) $record->plan) > 24 ? $record->plan : null)
                    ->description(fn (CpanelAccount $record) => $record->reseller ? 'reseller' : ($record->owner && $record->owner !== 'root' ? 'of ' . $record->owner : null)),
                TextColumn::make('owner')->sortable()->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('disk_used_mb')->label('Disk')->sortable()
                    ->formatStateUsing(fn (CpanelAccount $record) => self::meter($record->disk_used_mb, $record->disk_limit_mb, true))
                    ->color(fn (CpanelAccount $record) => CpanelAccount::near($record->disk_used_mb, $record->disk_limit_mb) ? 'danger' : null),
                TextColumn::make('inodes_used')->label('Inodes')->sortable()->toggleable()
                    ->formatStateUsing(fn (CpanelAccount $record) => self::meter($record->inodes_used, $record->inodes_limit, false))
                    ->color(fn (CpanelAccount $record) => CpanelAccount::near($record->inodes_used, $record->inodes_limit) ? 'danger' : null),
                TextColumn::make('bandwidth_used_mb')->label('Bandwidth (mo)')->sortable()->toggleable()
                    ->formatStateUsing(fn (CpanelAccount $record) => self::meter($record->bandwidth_used_mb, $record->bandwidth_limit_mb, true))
                    ->color(fn (CpanelAccount $record) => CpanelAccount::near($record->bandwidth_used_mb, $record->bandwidth_limit_mb) ? 'danger' : null),
                // CloudLinux (cpanel:lve): the peaks of the last 24 hours against its limits, CPU and memory shown, all on hover.
                TextColumn::make('lve')->label('CPU · RAM (24h)')->toggleable()
                    ->state(fn (CpanelAccount $record) => self::lveSummary($record))
                    ->tooltip(fn (CpanelAccount $record) => self::lveDetail($record))
                    ->color(fn (CpanelAccount $record) => self::lveNear($record) ? 'danger' : null),
                // One badge, the worst (owner 2026-10-28: the row fits a 22" screen, a window not full screen); all of it on hover.
                TextColumn::make('state')->label('State')->badge()
                    ->state(fn (CpanelAccount $record) => match (true) {
                        $record->suspended => 'Suspended',
                        $record->mail_suspended => 'Mail suspended',
                        $record->mail_held => 'Mail held',
                        default => 'OK',
                    })
                    ->color(fn (string $state) => $state === 'OK' ? 'success' : 'danger')
                    ->tooltip(fn (CpanelAccount $record) => implode(' · ', array_filter([
                        $record->suspended ? trim('Suspended ' . ($record->suspended_at?->format('d/m/Y') ?? '') . ($record->suspend_reason ? ': ' . $record->suspend_reason : '')) : null,
                        $record->mail_suspended ? 'Outgoing mail suspended' : ($record->mail_held ? 'Outgoing mail held' : null),
                    ])) ?: null),
                // Cut short, whole on hover (as the package).
                TextColumn::make('mapping')->label('Is')->badge()
                    ->state(fn (CpanelAccount $record) => self::mapping($record))
                    ->formatStateUsing(fn (string $state) => Str::limit($state, 22))
                    ->tooltip(fn (CpanelAccount $record) => mb_strlen(self::mapping($record)) > 22 ? self::mapping($record) : null)
                    ->color(fn (CpanelAccount $record) => $record->service_id !== null ? 'success' : ($record->internal ? 'gray' : 'warning'))
                    ->url(fn (CpanelAccount $record) => $record->service_id !== null ? ServiceResource::getUrl('edit', ['record' => $record->service_id]) : null),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_on_server_at')->label('Created')->date('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_seen_at')->label('Last read')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('mapping')->label('Mapping')
                    ->options(['unmapped' => 'Not mapped', 'service' => 'A service', 'internal' => 'Internal'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'unmapped' => $query->whereNull('service_id')->where('internal', false),
                        'service' => $query->whereNotNull('service_id'),
                        'internal' => $query->where('internal', true),
                        default => $query,
                    }),
                TernaryFilter::make('near')->label('Near a limit (90%)')
                    ->queries(true: fn (Builder $query) => $query->where(fn ($q) => $q
                        ->orWhereRaw('disk_limit_mb > 0 and disk_used_mb >= ? * disk_limit_mb', [CpanelAccount::NEAR])
                        ->orWhereRaw('inodes_limit > 0 and inodes_used >= ? * inodes_limit', [CpanelAccount::NEAR])
                        ->orWhereRaw('bandwidth_limit_mb > 0 and bandwidth_used_mb >= ? * bandwidth_limit_mb', [CpanelAccount::NEAR])),
                        false: fn (Builder $query) => $query),
                TernaryFilter::make('lve_near')->label('Near a CloudLinux limit (24h)')
                    ->queries(true: fn (Builder $query) => $query->whereHas('lveSamples', fn ($q) => $q->where('sampled_at', '>=', now()->subDay())
                        ->where(function ($q) {
                            $q->where('cpu', '>=', 100 * CpanelAccount::NEAR);
                            foreach (CpanelAccount::LVE as $columns) {
                                if ($columns['limit'] !== null) {
                                    $q->orWhereRaw("cpanel_accounts.{$columns['limit']} > 0 and cpanel_lve_samples.{$columns['sample']} >= ? * cpanel_accounts.{$columns['limit']}", [CpanelAccount::NEAR]);
                                }
                            }
                        })),
                        false: fn (Builder $query) => $query),
                TernaryFilter::make('suspended'),
                TernaryFilter::make('mail')->label('Outgoing mail held or suspended')
                    ->queries(true: fn (Builder $query) => $query->where(fn ($q) => $q->where('mail_held', true)->orWhere('mail_suspended', true)),
                        false: fn (Builder $query) => $query->where('mail_held', false)->where('mail_suspended', false)),
                SelectFilter::make('owner')->label('Reseller')
                    ->options(fn () => CpanelAccount::query()->where('reseller', true)->whereNull('gone_at')->orderBy('user')->pluck('user', 'user')->all()),
                SelectFilter::make('server_id')->label('Server')->relationship('server', 'name', fn (Builder $query) => $query->where('extension', 'CPanel')),
                TernaryFilter::make('gone')->label('Gone from the server')
                    ->queries(true: fn (Builder $query) => $query->whereNotNull('gone_at'), false: fn (Builder $query) => $query->whereNull('gone_at'))
                    ->default(false),
            ])
            // In one «⋯» menu: three buttons a row took a third of its width.
            ->recordActions([ActionGroup::make([
                Action::make('assign')->label('Service')->icon('ri-links-line')
                    ->authorize('update')
                    ->fillForm(fn (CpanelAccount $record) => ['service_id' => $record->service_id ?? $record->suggestedServiceId()])
                    ->schema([
                        Select::make('service_id')->label('Service')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search) => ProxmoxGuestResource::services($search))
                            ->getOptionLabelUsing(fn ($value) => ProxmoxGuestResource::serviceLabel(Service::query()->with(['product', 'client'])->find($value))),
                    ])
                    ->action(function (CpanelAccount $record, array $data) {
                        $record->update(['service_id' => (int) $data['service_id'], 'internal' => false, 'note' => null]);
                        Notification::make()->success()->title('Mapped')->send();
                    }),
                Action::make('internal')->label('Internal')->icon('ri-home-gear-line')->color('gray')
                    ->authorize('update')
                    ->fillForm(fn (CpanelAccount $record) => ['note' => $record->note])
                    ->schema([TextInput::make('note')->label('What it is')->maxLength(255)->placeholder('e.g. our site, a demo')])
                    ->action(function (CpanelAccount $record, array $data) {
                        $record->update(['service_id' => null, 'internal' => true, 'note' => $data['note'] ?? null]);
                        Notification::make()->success()->title('Marked internal')->send();
                    }),
                Action::make('unmap')->label('Clear')->icon('ri-link-unlink')->color('gray')
                    ->authorize('update')
                    ->visible(fn (CpanelAccount $record) => $record->service_id !== null || $record->internal)
                    ->requiresConfirmation()
                    ->action(fn (CpanelAccount $record) => $record->update(['service_id' => null, 'internal' => false, 'note' => null])),
            ])]);
    }

    /** «#12 Business10 · Acme», «Internal: our site», «Not mapped». */
    private static function mapping(CpanelAccount $record): string
    {
        return match (true) {
            $record->service !== null => '#' . $record->service_id . ' ' . ($record->service->product->name ?? '') . ' · ' . ($record->service->client->name ?? ''),
            $record->internal => 'Internal' . (filled($record->note) ? ': ' . $record->note : ''),
            default => 'Not mapped',
        };
    }

    /** Its peaks as the list loaded them (withLvePeaks), with its limits. @return array<string, array{peak: int|null, limit: int|null}> */
    private static function lvePeaks(CpanelAccount $record): array
    {
        // A suspended one is not read: its last readings are not shown, as on the service page (review 3 of #497).
        if ($record->lve_read_at === null || $record->suspended) {
            return [];
        }
        $peaks = [];
        foreach (CpanelAccount::LVE as $key => $columns) {
            $peak = $record->getAttribute("lve_{$key}_peak");
            $peaks[$key] = ['peak' => $peak === null ? null : (int) $peak, 'limit' => $columns['limit'] === null ? 100 : $record->getAttribute($columns['limit'])];
        }

        return $peaks;
    }

    private static function percentOf(?int $used, ?int $limit): ?int
    {
        return $used === null || !$limit ? null : (int) round(100 * $used / $limit);
    }

    /** «CPU 45% · RAM 12%», or «—» never read. */
    public static function lveSummary(CpanelAccount $record): string
    {
        $peaks = self::lvePeaks($record);
        if ($peaks === [] || $peaks['cpu']['peak'] === null) {
            return '—';
        }
        $memory = self::percentOf($peaks['memory']['peak'], $peaks['memory']['limit']);

        return 'CPU ' . $peaks['cpu']['peak'] . '%' . ($memory === null ? '' : ' · RAM ' . $memory . '%');
    }

    public static function lveDetail(CpanelAccount $record): ?string
    {
        $peaks = self::lvePeaks($record);
        if ($peaks === []) {
            return null;
        }
        $labels = ['cpu' => 'CPU', 'memory' => 'RAM', 'entry_processes' => 'Entry processes', 'processes' => 'Processes', 'io' => 'I/O', 'iops' => 'IOPS'];
        $lines = [];
        foreach ($peaks as $key => $peak) {
            $format = fn (?int $value) => $value === null ? '—' : match ($key) {
                'cpu' => $value . '%',
                'memory' => Number::fileSize($value * 1048576, 1),
                'io' => Number::fileSize($value * 1024, 1) . '/s',
                default => number_format($value, 0, ',', '.'),
            };
            $lines[] = $labels[$key] . ': ' . $format($peak['peak']) . ($key === 'cpu' ? ' of its limit' : ' / ' . ($peak['limit'] ? $format($peak['limit']) : '∞'));
        }

        return 'Peak of the last 24 hours: ' . implode(' · ', $lines);
    }

    private static function lveNear(CpanelAccount $record): bool
    {
        foreach (self::lvePeaks($record) as $peak) {
            if (CpanelAccount::near($peak['peak'], $peak['limit'])) {
                return true;
            }
        }

        return false;
    }

    /** «1.2 GB / 5 GB · 24%», a count «12,000 / 250,000», or «… / ∞» with no limit. */
    public static function meter(?int $used, ?int $limit, bool $mb): string
    {
        if ($used === null) {
            return '—';
        }
        $format = fn (int $value) => $mb ? Number::fileSize($value * 1048576, 1) : number_format($value, 0, ',', '.');

        return $format($used) . ' / ' . ($limit ? $format($limit) . ' · ' . (int) round(100 * $used / $limit) . '%' : '∞');
    }

    public static function getPages(): array
    {
        return ['index' => ListCpanelAccounts::route('/')];
    }
}
