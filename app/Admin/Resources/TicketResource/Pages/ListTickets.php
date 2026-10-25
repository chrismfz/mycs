<?php

namespace App\Admin\Resources\TicketResource\Pages;

use App\Admin\Resources\TicketResource;
use App\Admin\Resources\TicketResource\Widgets\TicketsOverView;
use App\Models\Ticket;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            TicketsOverView::class,
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All Tickets')
                ->icon('heroicon-m-user-group'),
            // Waiting on us: new and the customers' answers (docs/tickets.md §5).
            'open' => Tab::make('Awaiting us')
                ->icon('heroicon-o-lock-open')
                ->badge(fn () => Ticket::query()->whereIn('status', Ticket::AWAITING_STAFF)->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', Ticket::AWAITING_STAFF)),
            'answered' => Tab::make('Answered')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->badge(fn () => Ticket::query()->where('status', Ticket::STATUS_ANSWERED)->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Ticket::STATUS_ANSWERED)),
            'on_hold' => Tab::make('On hold')
                ->icon('heroicon-o-pause-circle')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Ticket::STATUS_ON_HOLD)),
            'closed' => Tab::make('Closed')
                ->icon('heroicon-o-lock-closed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Ticket::STATUS_CLOSED)),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }
}
