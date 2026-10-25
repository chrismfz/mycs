<?php

namespace App\Admin\Resources;

use App\Admin\Components\ClientComponent;
use App\Admin\Resources\TicketResource\Pages\CreateTicket;
use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Admin\Resources\TicketResource\Pages\ListTickets;
use App\Admin\Resources\TicketResource\TicketBulkActionsMenu;
use App\Admin\Resources\TicketResource\TicketFilters;
use App\Admin\Resources\TicketResource\Widgets\TicketsOverView;
use App\Admin\Search\SearchTitle;
use App\Models\Ticket;
use App\Support\AdminBadge;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    /** Its name in «Recently viewed» (the sidebar shows its kind beside it). */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (!$record instanceof Ticket) {
            return parent::getRecordTitle($record);
        }

        return '#' . $record->reference . ' ' . $record->subject;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'ri-customer-service-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-customer-service-fill';

    public static string|\UnitEnum|null $navigationGroup = 'Administration';

    public static function getNavigationBadge(): ?string
    {
        // Waiting on us: new tickets and the customers' answers (docs/tickets.md §5).
        return AdminBadge::label('tickets.awaiting', fn () => Ticket::query()->whereIn('status', Ticket::AWAITING_STAFF)->count());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getGloballySearchableAttributes(): array
    {
        // …and by its number (#ABC-123 in mails) or id, as WHMCS finds a ticket.
        return ['subject', 'reference', 'id'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return $record instanceof Ticket
            ? SearchTitle::make('#' . $record->reference . ' ' . $record->subject, Ticket::statusLabel($record->status), Ticket::statusColor($record->status))
            : (string) $record->getKey();
    }

    /** With its client, not one query each. */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['client.primaryContact']);
    }

    /** @return array<string, string> whose it is and when it last moved, on one line */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return !$record instanceof Ticket ? [] : array_filter([
            __('Client') => $record->client !== null ? $record->client->name . ' #' . $record->client_id : ($record->guest_email ?: null),
            __('Updated') => $record->updated_at?->format('d/m/Y H:i'),
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('subject')
                    ->label(__('Subject'))
                    ->columnSpan(function ($record) {
                        return $record ? 2 : 1;
                    })
                    ->required(),
                Select::make('status')
                    ->label(__('Status'))
                    ->options(Ticket::statusOptions())
                    ->columnSpan(function ($record) {
                        return $record ? 2 : 1;
                    })
                    ->default('open')
                    ->required(),
                Select::make('priority')
                    ->label(__('Priority'))
                    ->options([
                        'low' => 'Low',
                        'medium' => 'Medium',
                        'high' => 'High',
                    ])
                    ->columnSpan(function ($record) {
                        return $record ? 2 : 1;
                    })
                    ->default('medium')
                    ->required(),
                Select::make('department_id')
                    ->label(__('Department'))
                    ->relationship('department', 'name', fn (Builder $query) => $query->orderBy('sort'))
                    ->columnSpan(function ($record) {
                        return $record ? 2 : 1;
                    }),
                ClientComponent::make('client_id')->columnSpan(function ($record) {
                    return $record ? 2 : 1;
                }),
                Select::make('assigned_to')
                    ->label('Assigned To')
                    ->searchable()
                    ->preload()
                    // Staff, not customers (AUTH-14).
                    ->relationship('assignedTo', 'id', fn (Builder $query) => $query->where('active', true))
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)
                    ->columnSpan(function ($record) {
                        return $record ? 2 : 1;
                    }),
                Select::make('service_id')
                    ->label('Service')
                    ->relationship('service', 'id', function (Builder $query, Get $get) {
                        $query->where('client_id', $get('client_id'));
                    })
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->product?->name} #{$record->id} - " . ucfirst($record->status) . ($record->label == $record->baseLabel ? '' : " ({$record->label})"))
                    ->columnSpan(function ($record) {
                        return $record ? 2 : 1;
                    })
                    ->disabled(fn (Get $get) => !$get('client_id')),
                MarkdownEditor::make('message')
                    ->columnSpan(2)
                    ->label('Initial Message')
                    ->hiddenOn('edit')
                    ->columnSpan(function ($record) {
                        return $record ? 2 : 1;
                    })
                    ->required(),
            ]);
    }

    /** «2d 3h», «8h 43m», «12m» since then, as WHMCS's ticket list. */
    public static function sinceShort(mixed $at): string
    {
        if (blank($at)) {
            return '—';
        }
        $minutes = max(0, (int) Carbon::parse($at)->diffInMinutes(now()));
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);

        return match (true) {
            $days > 0 => "{$days}d {$hours}h",
            $hours > 0 => "{$hours}h " . ($minutes % 60) . 'm',
            default => ($minutes % 60) . 'm',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            // The account's name is its owner's (until the contacts, PR 3): loaded with the rows, not per row.
            // Its last reply's time, read with the rows (WHMCS «Last Reply»: the customer's or staff's, never an internal note).
            // Pinned ones first, whatever the sort (WHMCS «Pin», §6μ).
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client.primaryContact', 'client.ownerGrant.user', 'department', 'assignedTo'])
                ->withMax('publicMessages as last_reply_at', 'created_at')
                ->orderByRaw('pinned_at IS NULL'))
            ->columns([
                // A pin when pinned, a flag in the priority's colour (as WHMCS).
                TextColumn::make('marker')
                    ->label('')
                    ->state(fn (Ticket $record) => $record->priority)
                    ->formatStateUsing(fn (Ticket $record) => self::marker($record))
                    ->width('1%'),
                TextColumn::make('id')
                    ->label(__('ID'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('department.name')
                    ->label(__('Department')),
                // «#number – subject», one link; searched by either (and by id).
                TextColumn::make('subject')
                    ->label(__('Subject'))
                    ->state(fn (Ticket $record) => '#' . $record->reference . ' – ' . $record->subject)
                    ->url(fn (Ticket $record) => self::getUrl('edit', ['record' => $record]))
                    ->color('primary')
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                        ->where(fn (Builder $query) => self::whereContains($query, 'subject', $search))
                        ->orWhere(fn (Builder $query) => self::whereContains($query, 'reference', ltrim(trim($search), '#')))
                        ->when(ctype_digit(trim($search)), fn (Builder $query) => $query->orWhere('id', (int) trim($search)))))
                    ->sortable(),
                // Who opened it: the account, or the guest with a badge and the address under it (docs/tickets.md §3 Δ).
                TextColumn::make('requestor')
                    ->label(__('Requestor'))
                    ->state(fn (Ticket $record) => $record->requesterName())
                    ->formatStateUsing(fn (Ticket $record) => self::requestor($record))
                    ->description(fn (Ticket $record) => $record->isGuest() ? $record->guest_email : __('Client') . ' #' . $record->client_id)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $query) => ClientComponent::search($query, $search)
                        ->orWhere(fn (Builder $query) => self::whereContains($query, 'guest_email', $search))
                        ->orWhere(fn (Builder $query) => self::whereContains($query, 'guest_name', $search)))),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->sortable()
                    ->badge()
                    ->color(fn (Ticket $record) => Ticket::statusColor($record->status))
                    ->formatStateUsing(fn (Ticket $record) => $record->isMerged() ? __('Merged') : Ticket::statusLabel($record->status)),
                TextColumn::make('priority')
                    ->label(__('Priority'))
                    ->sortable()
                    ->badge()
                    ->color(fn (Ticket $record) => Ticket::priorityColor($record->priority))
                    ->formatStateUsing(fn (string $state) => self::priorityOptions()[$state] ?? ucfirst($state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('assignedTo.name')
                    ->label(__('Assigned to'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                // How long since its last reply, as WHMCS shows it («8h 43m»; owner 2026-10-23): what waits longest is
                // seen at once, and sorted by.
                TextColumn::make('last_reply_at')
                    ->label(__('Last reply'))
                    // Those with no reply yet (a merged one: its messages are its survivor's) last, either way (review).
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw('last_reply_at IS NULL')
                        ->orderBy('last_reply_at', $direction))
                    ->formatStateUsing(fn ($state) => self::sinceShort($state))
                    ->tooltip(fn (Ticket $record) => $record->last_reply_at ? Carbon::parse($record->last_reply_at)->format('d/m/Y H:i') : null),
            ])
            ->filters(TicketFilters::make(), layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(2)
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make(TicketBulkActionsMenu::make())->label(__('With selected')),
            ])
            // As WHMCS: the latest reply first (pinned still on top), those without one last.
            ->defaultSort(fn (Builder $query) => $query->orderByRaw('last_reply_at IS NULL')->orderBy('last_reply_at', 'desc')->orderBy('id', 'desc'));
    }

    /** @return array<string, string> */
    public static function priorityOptions(): array
    {
        return ['low' => __('Low'), 'medium' => __('Medium'), 'high' => __('High')];
    }

    /**
     * $column holds $text, case aside, its % and _ taken literally on MariaDB and SQLite alike (an explicit ESCAPE: SQLite
     * has no default one). $column is ours, never the user's.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function whereContains(Builder $query, string $column, string $text): Builder
    {
        return $query->whereRaw('LOWER(' . $column . ") LIKE ? ESCAPE '!'", ['%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($text))) . '%']);
    }

    private static function marker(Ticket $record): HtmlString
    {
        $flag = match ($record->priority) {
            'high' => 'text-danger-600',
            'low' => 'text-gray-400',
            default => 'text-warning-600',
        };
        $html = '<span class="inline-flex items-center gap-0.5">';
        if ($record->pinned_at !== null) {
            $html .= '<span title="' . e(__('Pinned')) . '">' . svg('ri-pushpin-fill', 'h-3.5 w-3.5 text-primary-600')->toHtml() . '</span>';
        }
        $html .= '<span title="' . e(self::priorityOptions()[$record->priority] ?? (string) $record->priority) . '">' . svg('ri-flag-fill', 'h-3.5 w-3.5 ' . $flag)->toHtml() . '</span>';

        return new HtmlString($html . '</span>');
    }

    private static function requestor(Ticket $record): HtmlString
    {
        $name = e($record->requesterName() !== '' ? $record->requesterName() : ($record->guest_email ?? '—'));
        if (!$record->isGuest()) {
            return new HtmlString($name);
        }

        return new HtmlString($name . ' <span class="ms-1 rounded border border-gray-300 bg-gray-100 px-1 text-[10px] font-medium uppercase tracking-wide text-gray-600 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">' . e(__('Guest')) . '</span>');
    }

    public static function getWidgets(): array
    {
        return [
            TicketsOverView::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'create' => CreateTicket::route('/create'),
            'edit' => EditTicket::route('/{record}/edit'),
        ];
    }
}
