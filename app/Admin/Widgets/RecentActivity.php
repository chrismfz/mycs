<?php

namespace App\Admin\Widgets;

use App\Admin\Resources\Audits\AuditResource;
use App\Models\Audit;
use App\Support\AuditSummary;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The last entries of the Activity Log (owner 2026-11-03, from the Billanz demo): who, what, when, and «View all» to the
 * log. The same permission and the same lines as the log's page (AuditSummary: redacted where the model redacts), so the
 * card never shows what the page would not.
 */
class RecentActivity extends TableWidget
{
    public const ROWS = 8;

    // In the right column of the dashboard (`Dashboard::RIGHT`), under Support.
    protected static ?int $sort = 5;

    protected static ?string $pollingInterval = '60s';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Recent activity'))
            // Newest first by the primary key, as the log: rows are written in time order.
            ->query(Audit::query()->orderByDesc('id')->limit(self::ROWS))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('When'))
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i')
                    ->color('gray'),
                TextColumn::make('entry')
                    ->label(__('Log Entry'))
                    ->state(fn (Audit $record): string => AuditSummary::line($record))
                    ->limit(110)
                    ->wrap()
                    ->url(fn (Audit $record): ?string => AuditSummary::subjectUrl($record)),
                TextColumn::make('user_id')
                    ->label(__('User'))
                    ->state(fn (Audit $record): string => AuditSummary::actor($record))
                    ->description(fn (Audit $record): ?string => $record->user_id === null ? null : $record->ip_address)
                    ->color(fn (Audit $record): ?string => $record->user_id === null ? 'gray' : null),
            ])
            ->headerActions([
                Action::make('all')
                    ->label(__('View all'))
                    ->link()
                    ->url(AuditResource::getUrl('index')),
            ])
            ->emptyStateHeading(__('No activity yet'))
            ->paginated(false);
    }

    /** Who did each and the rows' subjects in one query per type, never failing on a class no longer there (as ListAudits). */
    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = parent::getTableRecords();
        /** @var EloquentCollection<int, Audit> $rows */
        $rows = new EloquentCollection($records instanceof Collection ? $records->all() : $records->items());
        $rows->load('user');
        AuditSummary::loadSubjects($rows);

        return $records;
    }

    public static function canView(): bool
    {
        return AuditResource::canViewAny();
    }
}
