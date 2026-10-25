<?php

namespace App\Admin\Resources;

use App\Admin\Resources\TicketBlockedSenderResource\Pages\ManageTicketBlockedSenders;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Senders whose mail is dropped (docs/tickets.md §3 Ε): an address, or a whole domain written as @example.com. */
class TicketBlockedSenderResource extends Resource
{
    protected static ?string $model = TicketBlockedSender::class;

    // Under Tickets, where staff work on them.
    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    /** Under Tickets for staff who see them; else on its own (Filament drops a child whose parent is not there). */
    public static function getNavigationParentItem(): ?string
    {
        return auth('staff')->user()?->can('viewAny', Ticket::class) ? 'Tickets' : null;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'ri-mail-forbid-line';

    protected static ?string $navigationLabel = 'Blocked Senders';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('value')->label(__('Address or *@domain'))->required()->maxLength(255)
                ->regex('/^(\*?@|[^@\s]+@)?[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/')
                ->dehydrateStateUsing(fn (?string $state) => TicketBlockedSender::normalise((string) $state))
                ->rules([fn (?TicketBlockedSender $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record) {
                    if (TicketBlockedSender::query()->where('value', TicketBlockedSender::normalise((string) $value))->whereKeyNot($record?->id)->exists()) {
                        $fail('Already blocked.');
                    }
                }])
                ->helperText(__('maria@example.com, or *@example.com for the whole domain (not its subdomains).')),
            TextInput::make('note')->label('Note')->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('createdBy'))
            ->columns([
                TextColumn::make('value')->label(__('Sender'))->searchable()
                    ->formatStateUsing(fn (string $state) => TicketBlockedSender::display($state)),
                TextColumn::make('note')->label(__('Note'))->placeholder('—')->wrap(),
                // Who blocked it: staff by hand, from the quarantine or the ticket list (§6μ).
                TextColumn::make('createdBy.name')->label(__('By'))->placeholder('—'),
                TextColumn::make('created_at')->label(__('Since'))->since()->sortable(),
            ])
            ->recordActions([DeleteAction::make()->label('Unblock')]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTicketBlockedSenders::route('/')];
    }
}
