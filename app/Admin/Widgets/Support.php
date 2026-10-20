<?php

namespace App\Admin\Widgets;

use App\Admin\Resources\TicketResource;
use App\Models\Ticket;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class Support extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Ticket::query()
                    ->where('status', '!=', 'closed')
                    ->with(['client.primaryContact', 'client.ownerGrant.user'])
                    ->withMax('messages', 'created_at')
                    ->orderByDesc('messages_max_created_at')
                    ->limit(5)
            )
            ->columns([
                TextColumn::make('subject')
                    ->label('Subject'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (Ticket $record) => Ticket::statusColor($record->status))
                    ->formatStateUsing(fn (string $state) => Ticket::statusLabel($state)),
                TextColumn::make('client.name')
                    ->label('Client')
                    ->state(fn (Ticket $record) => $record->requesterName()),
                TextColumn::make('created_at')
                    ->label('Created At'),
            ])
            ->recordUrl(fn (Ticket $record) => TicketResource::getUrl('edit', ['record' => $record]))
            ->paginated(false);
    }

    public static function canView(): bool
    {
        return auth()->user()->hasPermission('admin.widgets.support');
    }
}
