<?php

namespace App\Admin\Resources;

use App\Admin\Resources\TicketMailImportResource\Pages\ListTicketMailImports;
use App\Models\TicketDepartment;
use App\Models\TicketMailImport;
use App\Models\TicketQuarantine;
use App\Support\Tickets\InboundOutcome;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * «Ticket Mail Import Log» (owner 2026-10-23, WHMCS's): every mail the poller read and what came of it, with the ticket
 * or the quarantine it went to. Read only.
 */
class TicketMailImportResource extends Resource
{
    protected static ?string $model = TicketMailImport::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-mail-download-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-mail-download-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Other';

    protected static ?string $slug = 'ticket-mail-log';

    public static function getNavigationLabel(): string
    {
        return __('Ticket Mail Import Log');
    }

    public static function getModelLabel(): string
    {
        return __('Ticket Mail Import Log');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    public static function outcomes(): array
    {
        return [
            InboundOutcome::OPENED => __('Imported as a new ticket'),
            InboundOutcome::THREADED => __('Imported as a reply'),
            InboundOutcome::QUARANTINED => __('Quarantined'),
            InboundOutcome::BLOCKED => __('Blocked sender'),
        ];
    }

    public static function status(TicketMailImport $import): string
    {
        $outcome = self::outcomes()[$import->outcome] ?? $import->outcome;
        if ($import->outcome === InboundOutcome::QUARANTINED && $import->reason !== null) {
            return $outcome . ': ' . __(TicketQuarantine::REASONS[$import->reason] ?? $import->reason);
        }

        return $outcome;
    }

    /** The ticket or the quarantine it went to, if the staff member may open it. */
    public static function target(TicketMailImport $import): ?string
    {
        if ($import->ticket_id !== null && TicketResource::canAccess()) {
            return TicketResource::getUrl('edit', ['record' => $import->ticket_id]);
        }
        if ($import->quarantine_id !== null && $import->from_email !== null && TicketQuarantineResource::canAccess()) {
            return TicketQuarantineResource::getUrl('index', ['search' => $import->from_email]);
        }

        return null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            // The quarantine holds anyone's mail and has its own permission: its rows only to whom may open it (review of #406).
            ->modifyQueryUsing(fn (Builder $query) => $query->with('department')
                ->when(!TicketQuarantineResource::canAccess(), fn (Builder $q) => $q->where('outcome', '!=', InboundOutcome::QUARANTINED)))
            ->columns([
                TextColumn::make('created_at')->label(__('Date'))->dateTime()->sortable(),
                TextColumn::make('department.name')->label(__('To'))->placeholder('—'),
                TextColumn::make('subject')->label(__('Subject'))->placeholder('—')->wrap()->searchable()
                    ->description(fn (TicketMailImport $record) => trim(($record->from_name ?? '') . ' <' . ($record->from_email ?? '?') . '>'))
                    ->url(fn (TicketMailImport $record) => self::target($record)),
                TextColumn::make('from_email')->label(__('From'))->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('outcome')->label(__('Status'))->wrap()
                    ->state(fn (TicketMailImport $record) => self::status($record))
                    ->color(fn (TicketMailImport $record) => match ($record->outcome) {
                        InboundOutcome::OPENED, InboundOutcome::THREADED => 'success',
                        InboundOutcome::QUARANTINED => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('outcome')->label(__('Status'))->options(fn (): array => TicketQuarantineResource::canAccess()
                    ? self::outcomes() : array_diff_key(self::outcomes(), [InboundOutcome::QUARANTINED => true])),
                SelectFilter::make('department_id')->label(__('Department'))
                    ->options(fn (): array => TicketDepartment::query()->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '<', Carbon::parse($date)->startOfDay()->addDay()))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTicketMailImports::route('/')];
    }
}
