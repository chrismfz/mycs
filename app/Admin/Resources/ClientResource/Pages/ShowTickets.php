<?php

namespace App\Admin\Resources\ClientResource\Pages;

use Illuminate\Database\Eloquent\Builder;
use App\Admin\Resources\ClientResource;
use App\Models\Ticket;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShowTickets extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'tickets';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-customer-service-line';

    public static function getNavigationLabel(): string
    {
        return 'Tickets';
    }

    public function table(Table $table): Table
    {
        return $table
            // The account's name is its owner's (until the contacts, PR 3): loaded with the rows, not per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('client.ownerGrant.user'))
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('subject')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->color(fn (Ticket $record) => match ($record->status) {
                        'open' => 'success',
                        'closed' => 'danger',
                        'replied' => 'warning',
                    })
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextColumn::make('priority')
                    ->sortable()
                    ->badge()
                    ->color(fn (Ticket $record) => match ($record->priority) {
                        'low' => 'success',
                        'medium' => 'gray',
                        'high' => 'danger',
                    })
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextColumn::make('department')
                    ->sortable(),
                TextColumn::make('client.name')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name', 'last_name']),
            ]);
    }
}
