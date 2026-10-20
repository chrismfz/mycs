<?php

namespace App\Admin\Resources;

use App\Admin\Components\ClientComponent;
use App\Admin\Resources\TicketResource\Pages\CreateTicket;
use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Admin\Resources\TicketResource\Pages\ListTickets;
use App\Admin\Resources\TicketResource\Widgets\TicketsOverView;
use App\Models\Client;
use App\Models\Staff;
use App\Models\Ticket;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    /** Its name in «Recently viewed» and the breadcrumbs. */
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
        return Ticket::whereIn('status', Ticket::AWAITING_STAFF)->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['subject'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return $record->subject;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('subject')
                    ->label('Subject')
                    ->columnSpan(function ($record) {
                        return $record ? 2 : 1;
                    })
                    ->required(),
                Select::make('status')
                    ->label('Status')
                    ->options(Ticket::statusOptions())
                    ->columnSpan(function ($record) {
                        return $record ? 2 : 1;
                    })
                    ->default('open')
                    ->required(),
                Select::make('priority')
                    ->label('Priority')
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
                    ->label('Department')
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

    public static function table(Table $table): Table
    {
        return $table
            // The account's name is its owner's (until the contacts, PR 3): loaded with the rows, not per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client.primaryContact', 'client.ownerGrant.user', 'department']))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                // The number the customer sees (docs/tickets.md §5).
                TextColumn::make('reference')
                    ->label('Number')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('subject')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->color(fn (Ticket $record) => Ticket::statusColor($record->status))
                    ->formatStateUsing(fn (string $state) => Ticket::statusLabel($state)),
                TextColumn::make('priority')
                    ->sortable()
                    ->badge()
                    ->color(fn (Ticket $record) => Ticket::priorityColor($record->priority))
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextColumn::make('department.name')
                    ->label('Department'),
                // A guest's ticket names its guest (docs/tickets.md §3 Δ): no account behind it.
                TextColumn::make('client.name')
                    ->label('Client')
                    ->state(fn (Ticket $record) => $record->isGuest() ? __('Guest') . ': ' . $record->guest_name . ' <' . $record->guest_email . '>' : $record->client?->name)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $query) => ClientComponent::search($query, $search)
                        ->orWhere('guest_email', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%')
                        ->orWhere('guest_name', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%'))),
            ])
            ->filters([
                SelectFilter::make('client')
                    ->label('Client')
                    ->relationship('client', 'id')
                    ->indicateUsing(fn ($data) => $data['value'] ? 'Client: ' . Client::find($data['value'])?->name : null)
                    ->getOptionLabelFromRecordUsing(fn (Client $record) => ClientComponent::optionLabel($record)),
                SelectFilter::make('priority')
                    ->options([
                        'low' => 'Low',
                        'medium' => 'Medium',
                        'high' => 'High',
                    ]),
                SelectFilter::make('department_id')
                    ->label('Department')
                    ->relationship('department', 'name'),
                // Tickets without an account (docs/tickets.md §3 Δ).
                Filter::make('guest')
                    ->label('Guests')
                    ->query(fn (Builder $query) => $query->whereNull('client_id')),
                SelectFilter::make('assigned_to')
                    ->label('Assigned To')
                    // It filters by the staff member assigned, not by the customer (AUTH-14).
                    ->relationship('assignedTo', 'id')
                    ->indicateUsing(fn ($data) => $data['value'] ? 'Assigned to: ' . Staff::find($data['value'])?->name : null)
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
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
