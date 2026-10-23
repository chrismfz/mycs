<?php

namespace App\Admin\Resources\Audits\Tables;

use App\Models\Audit;
use App\Models\Staff;
use App\Models\User;
use App\Support\AuditSummary;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The Activity Log as WHMCS's (owner 2026-10-23): the date, what changed in words, the client it is about and who did
 * it; the system's own changes (the cron, the domain sync, a queued job) as «System». Append-only (AuditPolicy).
 */
class AuditsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user')) // the subjects: ListAudits, by page
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('entry')
                    ->label('Log Entry')
                    ->state(fn (Audit $record): string => AuditSummary::line($record))
                    ->url(fn (Audit $record): ?string => AuditSummary::subjectUrl($record))
                    ->wrap()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $query) use ($search) {
                            $query->where('event', 'like', "%{$search}%")
                                ->orWhere('auditable_type', 'like', "%{$search}%")
                                ->orWhere('auditable_id', $search)
                                ->orWhere('old_values', 'like', "%{$search}%")
                                ->orWhere('new_values', 'like', "%{$search}%");
                        });
                    }),
                TextColumn::make('client')
                    ->label('Client')
                    ->state(fn (Audit $record): ?string => AuditSummary::client($record)?->name)
                    ->url(fn (Audit $record): ?string => AuditSummary::clientUrl(AuditSummary::client($record)))
                    ->placeholder('—'),
                TextColumn::make('user_id')
                    ->label('User')
                    ->state(fn (Audit $record): string => AuditSummary::actor($record))
                    ->description(fn (Audit $record): ?string => AuditSummary::actorKind($record))
                    ->url(fn (Audit $record): ?string => AuditSummary::actorUrl($record))
                    ->color(fn (Audit $record): ?string => $record->user_id === null ? 'gray' : null),
                TextColumn::make('ip_address')
                    ->label('IP Address')
                    ->searchable(),
                TextColumn::make('user_agent')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('actor')
                    ->label('Done by')
                    ->options(fn (): array => [
                        'system' => __('System'),
                        'staff' => __('Staff'),
                        'client' => __('Client'),
                        ...Staff::query()->orderBy('first_name')->orderBy('last_name')->get()
                            ->mapWithKeys(fn (Staff $staff) => ['staff:' . $staff->id => $staff->name])->all(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match (true) {
                        blank($data['value'] ?? null) => $query,
                        $data['value'] === 'system' => $query->whereNull('user_id'),
                        $data['value'] === 'staff' => $query->where('user_type', Staff::class)->whereNotNull('user_id'),
                        $data['value'] === 'client' => $query->where('user_type', User::class)->whereNotNull('user_id'),
                        default => $query->where('user_type', Staff::class)
                            ->where('user_id', (int) Str::after((string) $data['value'], 'staff:')),
                    }),
                // The kinds there are, read at most every ten minutes: a DISTINCT over the whole log (review of #402).
                SelectFilter::make('auditable_type')
                    ->label('Type')
                    ->options(fn (): array => collect(Cache::remember('audits.filter.types', 600,
                        fn () => Audit::query()->distinct()->orderBy('auditable_type')->pluck('auditable_type')->all()))
                        ->mapWithKeys(fn (string $type) => [$type => __(Str::headline(class_basename($type)))])->all()),
                SelectFilter::make('event')
                    ->options(fn (): array => collect(Cache::remember('audits.filter.events', 600,
                        fn () => Audit::query()->distinct()->orderBy('event')->pluck('event')->all()))
                        ->mapWithKeys(fn (string $event) => [$event => AuditSummary::verb($event)])->all()),
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '<', Carbon::parse($date)->startOfDay()->addDay()))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort(function (Builder $query) {
                return $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
            });
    }
}
