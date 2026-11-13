<?php

namespace App\Admin\Resources\ClientResource\Pages;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Concerns\NamesTheClient;
use App\Admin\Resources\TicketResource;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * A client's tickets (owner 2026-11-13): each one's number and subject as the link to it, when and how it was opened,
 * how long since its last reply and whose it was, who has it. The open ones at first, the latest reply on top.
 */
class ShowTickets extends ManageRelatedRecords
{
    use NamesTheClient;

    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'tickets';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-customer-service-line';

    public static function getNavigationLabel(): string
    {
        return 'Tickets';
    }

    protected function getHeaderActions(): array
    {
        $client = $this->getRecord();

        return [
            // The ticket form takes the client from ?client_id=, as the Summary's «Open New Ticket».
            Action::make('newTicket')
                ->label(__('New ticket'))
                ->icon('heroicon-o-plus')
                ->visible(TicketResource::canCreate())
                ->url(TicketResource::getUrl('create', ['client_id' => $client instanceof Client ? $client->id : null])),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            // As the tickets' list: its last reply (the customer's or staff's, never an internal note), read with the rows,
            // and whose it was.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['department', 'assignedTo', 'service.product'])
                ->withMax('publicMessages as last_reply_at', 'created_at')
                ->withCount('publicMessages as messages_count')
                ->addSelect(['last_reply_by_staff' => TicketMessage::query()
                    ->selectRaw('CASE WHEN staff_id IS NULL THEN 0 ELSE 1 END')
                    ->whereColumn('ticket_messages.ticket_id', 'tickets.id')
                    ->where('is_internal', false)
                    ->orderByDesc('created_at')->orderByDesc('id')
                    ->limit(1)]))
            ->recordTitleAttribute('subject')
            ->recordUrl(fn (Ticket $record) => TicketResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('subject')
                    ->label(__('Ticket'))
                    ->state(fn (Ticket $record) => '#' . $record->reference . ' – ' . $record->subject)
                    ->icon('ri-flag-fill')
                    ->iconColor(fn (Ticket $record) => match ($record->priority) {
                        'high' => 'danger',
                        'low' => 'gray',
                        default => 'warning',
                    })
                    ->tooltip(fn (Ticket $record) => __('Priority') . ': ' . (TicketResource::priorityOptions()[$record->priority] ?? $record->priority))
                    ->color('primary')
                    ->description(fn (Ticket $record) => self::about($record))
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                        ->where(fn (Builder $query) => TicketResource::whereContains($query, 'subject', $search))
                        ->orWhere(fn (Builder $query) => TicketResource::whereContains($query, 'reference', ltrim(trim($search), '#')))
                        ->when(ctype_digit(trim($search)), fn (Builder $query) => $query->orWhere('tickets.id', (int) trim($search))))),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->sortable()
                    ->badge()
                    ->color(fn (Ticket $record) => Ticket::statusColor($record->status))
                    ->formatStateUsing(fn (Ticket $record) => $record->isMerged() ? __('Merged') : Ticket::statusLabel($record->status)),
                TextColumn::make('created_at')
                    ->label(__('Opened'))
                    ->date('d/m/Y')
                    ->dateTimeTooltip('d/m/Y H:i')
                    ->description(fn (Ticket $record) => self::openedVia($record->opened_via))
                    ->sortable(),
                TextColumn::make('last_reply_at')
                    ->label(__('Last reply'))
                    ->formatStateUsing(fn ($state) => TicketResource::sinceShort($state))
                    ->placeholder('—')
                    ->tooltip(fn (Ticket $record) => $record->last_reply_at ? Carbon::parse($record->last_reply_at)->format('d/m/Y H:i') : null)
                    ->description(fn (Ticket $record) => self::lastReply($record))
                    // Those with no reply (a merged one: its messages are its survivor's) last, either way.
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw('last_reply_at IS NULL')
                        ->orderBy('last_reply_at', $direction)),
                TextColumn::make('assignedTo.name')
                    ->label(__('Assigned to'))
                    ->placeholder('—'),
            ])
            ->filters([
                Filter::make('open')
                    ->label(__('Open only'))
                    ->query(fn (Builder $query) => $query->where('status', '!=', Ticket::STATUS_CLOSED))
                    ->default(),
            ])
            ->defaultSort(fn (Builder $query) => $query->orderByRaw('last_reply_at IS NULL')->orderByDesc('last_reply_at')->orderByDesc('tickets.id'));
    }

    /** Its department, and the service it is about when it has one (named as everywhere). */
    private static function about(Ticket $record): string
    {
        return collect([$record->department?->name, $record->service?->mail_name])->filter()->implode(' · ');
    }

    private static function openedVia(?string $via): string
    {
        return match ($via) {
            'email' => __('by email'),
            'portal' => __('from the client area'),
            'form' => __('from the contact form'),
            'api' => __('by API'),
            'whmcs' => __('from WHMCS'),
            default => __('by staff'),
        };
    }

    /** «client · 5 messages»: whose the last reply was, and how many public messages it has. */
    private static function lastReply(Ticket $record): ?string
    {
        if ($record->last_reply_at === null) {
            return null;
        }

        return ($record->last_reply_by_staff ? __('staff') : __('client')) . ' · '
            . trans_choice(':count message|:count messages', (int) $record->messages_count);
    }
}
