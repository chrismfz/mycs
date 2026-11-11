<?php

namespace App\Admin\Resources;

use App\Admin\Resources\RedfishMachineResource\Pages\ListRedfishMachines;
use App\Admin\Resources\RedfishMachineResource\Pages\ViewRedfishMachine;
use App\Models\RedfishMachine;
use App\Services\Redfish\MachineSync;
use App\Support\Redact;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use MyCS\Redfish\Machine;
use Throwable;

/**
 * Utilities → Machines (owner 2026-11-11): every physical machine with a BMC (a Redfish server each), a line each (the enabled ones) as
 * redfish:sync last read it (every hour): who has it, on or off, health, watts, the air it takes in, the parts to look
 * at. Its page has the parts. Read-only on the machine; an indication only, nobody is told (the monitoring alerts).
 */
class RedfishMachineResource extends Resource
{
    protected static ?string $model = RedfishMachine::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-cpu-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Utilities';

    protected static ?string $navigationLabel = 'Machines';

    protected static ?string $modelLabel = 'machine';

    protected static ?string $slug = 'machines';

    public static function table(Table $table): Table
    {
        $stale = fn () => now()->subHours(RedfishMachine::STALE_HOURS);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereHas('server', fn (Builder $server) => $server->where('extension', 'Redfish')->where('enabled', true))->with('server.settings'))
            ->defaultSort('server.name')
            ->recordUrl(fn (RedfishMachine $record) => self::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('server.name')->label('Machine')->sortable()->searchable()->weight('medium')
                    ->description(fn (RedfishMachine $record) => collect([self::what($record), $record->server?->settings->firstWhere('key', 'location')?->value])->filter()->implode(' · ') ?: null),
                TextColumn::make('given')->label('Given to')->badge()
                    ->state(fn (RedfishMachine $record) => self::given($record)['label'])
                    ->color(fn (RedfishMachine $record) => self::given($record)['color'])
                    ->url(fn (RedfishMachine $record) => self::given($record)['service'] === null ? null : ServiceResource::getUrl('edit', ['record' => self::given($record)['service']])),
                TextColumn::make('power_state')->label('Power')->badge()->placeholder('—')
                    ->color(fn (?string $state) => match ($state) {
                        'On' => 'success', 'Off' => 'danger', default => 'gray'
                    }),
                TextColumn::make('health')->badge()->placeholder('—')->sortable()->color(fn (?string $state) => self::healthColor($state)),
                TextColumn::make('watts')->label('Power now')->suffix(' W')->placeholder('—')->sortable()->alignEnd(),
                TextColumn::make('inlet_celsius')->label('Air in')->suffix(' °C')->placeholder('—')->sortable()->alignEnd(),
                TextColumn::make('attention')->label('Look at')->placeholder('—')->wrap()->color('danger')
                    ->state(fn (RedfishMachine $record) => $record->attentionLines() ?: null)->listWithLineBreaks()->limitList(3)->expandableLimitedList(),
                TextColumn::make('read_at')->label('Last read')->since()->placeholder('never')->sortable()
                    ->color(fn (RedfishMachine $record) => $record->error !== null ? 'danger' : ($record->read_at === null || $record->read_at->lt($stale()) ? 'warning' : null))
                    ->description(fn (RedfishMachine $record) => $record->error !== null ? 'Failed ' . ($record->failed_at?->diffForHumans() ?? '') . ': ' . $record->error : null)
                    ->wrap(),
            ])
            ->filters([
                TernaryFilter::make('look')->label('Needs a look')
                    ->queries(
                        true: fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNotNull('error')->orWhereNotNull('attention')->orWhereNull('read_at')
                            ->orWhere('read_at', '<', $stale())->orWhere(fn (Builder $q) => $q->whereNotNull('health')->where('health', '!=', Machine::HEALTH_OK))),
                        false: fn (Builder $query) => $query->whereNull('error')->whereNull('attention')->where('read_at', '>=', $stale())
                            ->where(fn (Builder $q) => $q->whereNull('health')->orWhere('health', Machine::HEALTH_OK)),
                    ),
                SelectFilter::make('power_state')->label('Power')->options(['On' => 'On', 'Off' => 'Off']),
            ])
            ->recordActions([self::readAction()]);
    }

    /** The cron's read of one machine, now. */
    public static function readAction(): Action
    {
        return Action::make('read')->label('Read now')->icon('ri-refresh-line')->color('gray')
            ->authorize('update')
            ->action(function (RedfishMachine $record) {
                try {
                    // Less than the cron's: a page's request must end. Parts not reached are said on its page.
                    $row = MachineSync::sync($record->server, budget: 20.0);
                    Notification::make()->success()->title($record->server->name)
                        ->body(e(($row->power_state ?? '?') . ', health ' . ($row->health ?? 'not said') . ($row->watts !== null ? ", {$row->watts} W" : '')))->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($record->server->name)->body(e(Redact::text($e->getMessage())))->send();
                }
                $record->refresh();
            });
    }

    /** «Supermicro SYS-…», as last read. */
    private static function what(RedfishMachine $record): ?string
    {
        $data = (array) $record->data;

        return trim(($data['manufacturer'] ?? '') . ' ' . ($data['model'] ?? '')) ?: null;
    }

    /**
     * Who has it, for the list: a customer's service, free, or our own.
     *
     * @return array{label: string, color: string, service: ?int}
     */
    public static function given(RedfishMachine $record): array
    {
        return $record->givenMemo ??= (function () use ($record) {
            $given = $record->server === null ? ['services' => [], 'own' => false] : RedfishMachine::givenTo($record->server);
            $first = $given['services'][0] ?? null;

            return match (true) {
                $first !== null => ['label' => $first['label'] . (count($given['services']) > 1 ? ' +' . (count($given['services']) - 1) : ''), 'color' => 'info', 'service' => $first['id']],
                $given['own'] => ['label' => 'Our own', 'color' => 'gray', 'service' => null],
                default => ['label' => 'Free', 'color' => 'success', 'service' => null],
            };
        })();
    }

    public static function healthColor(?string $health): string
    {
        return ['OK' => 'success', 'Warning' => 'warning', 'Critical' => 'danger'][$health] ?? 'gray';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRedfishMachines::route('/'),
            'view' => ViewRedfishMachine::route('/{record}'),
        ];
    }
}
