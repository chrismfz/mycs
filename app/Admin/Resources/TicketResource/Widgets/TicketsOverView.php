<?php

namespace App\Admin\Resources\TicketResource\Widgets;

use App\Models\Ticket;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TicketsOverView extends BaseWidget
{
    protected ?string $pollingInterval = '20s';

    protected function getStats(): array
    {
        return [
            Stat::make('Awaiting us', Ticket::whereIn('status', Ticket::AWAITING_STAFF)->count())
                ->color('warning'),
            Stat::make('Answered', Ticket::where('status', Ticket::STATUS_ANSWERED)->count())
                ->color('info'),
            Stat::make('On hold', Ticket::where('status', Ticket::STATUS_ON_HOLD)->count())
                ->color('gray'),
        ];
    }
}
