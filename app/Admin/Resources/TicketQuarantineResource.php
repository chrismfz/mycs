<?php

namespace App\Admin\Resources;

use App\Admin\Resources\TicketQuarantineResource\Pages\ListTicketQuarantine;
use App\Models\Client;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketQuarantine;
use App\Support\Tickets\QuarantineActions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Mail that did not pass the security model (docs/tickets.md §3): staff read it as text and decide. Nothing in it joins
 * a ticket without them.
 */
class TicketQuarantineResource extends Resource
{
    protected static ?string $model = TicketQuarantine::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Support';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-mail-lock-line';

    protected static ?string $navigationLabel = 'Mail Quarantine';

    protected static ?string $slug = 'ticket-quarantine';

    public static function getNavigationBadge(): ?string
    {
        $pending = TicketQuarantine::query()->where('status', TicketQuarantine::PENDING)->where('reason', '!=', 'automated')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    private static function staff(): Staff
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff instanceof Staff, 403);

        return $staff;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['department', 'suggestedTicket', 'ticket']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Received')->since()->sortable(),
                TextColumn::make('department.name')->label('Department')->placeholder('—'),
                TextColumn::make('from_email')->label('From')->searchable()->description(fn (TicketQuarantine $record) => $record->from_name),
                TextColumn::make('subject')->label('Subject')->searchable()->limit(60),
                TextColumn::make('reason')->label('Why')->badge()->formatStateUsing(fn (TicketQuarantine $record) => $record->reasonLabel())
                    ->color(fn (string $state) => $state === 'automated' ? 'gray' : 'warning'),
                TextColumn::make('suggestedTicket.reference')->label('Suggested')->prefix('#')->placeholder('—'),
                TextColumn::make('status')->label('Status')->badge()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->default(TicketQuarantine::PENDING)->options([TicketQuarantine::PENDING => 'Pending',
                    TicketQuarantine::ATTACHED => 'Attached', TicketQuarantine::OPENED => 'Opened as a ticket', TicketQuarantine::DISMISSED => 'Dismissed']),
                SelectFilter::make('reason')->options(collect(TicketQuarantine::REASONS)->map(fn ($label) => __($label))->all()),
            ])
            ->recordActions([
                Action::make('read')->label('Read')->icon('ri-mail-open-line')->modalSubmitAction(false)
                    ->modalHeading(fn (TicketQuarantine $record) => (string) $record->subject)
                    ->modalContent(fn (TicketQuarantine $record) => view('admin.ticket-quarantine.show', ['record' => $record])),
                ActionGroup::make([
                    Action::make('attach')->label('Attach to a ticket')->icon('ri-attachment-2')
                        ->authorize(fn (TicketQuarantine $record) => self::staff()->can('update', $record))
                        ->schema([
                            Select::make('ticket_id')->label('Ticket')->required()->searchable()
                                ->default(fn (TicketQuarantine $record) => $record->suggested_ticket_id)
                                ->getSearchResultsUsing(fn (string $search) => Ticket::query()->where('reference', 'like', "{$search}%")
                                    ->orWhere('subject', 'like', "%{$search}%")->latest('id')->limit(20)->get()
                                    ->mapWithKeys(fn (Ticket $ticket) => [$ticket->id => "#{$ticket->reference} · {$ticket->subject} ({$ticket->requesterName()})"])->all())
                                ->getOptionLabelUsing(fn ($value) => ($ticket = Ticket::query()->find($value)) ? "#{$ticket->reference} · {$ticket->subject}" : null),
                        ])
                        ->action(fn (TicketQuarantine $record, array $data) => self::run(fn () => app(QuarantineActions::class)
                            ->attach($record, Ticket::query()->findOrFail($data['ticket_id']), self::staff()), 'Attached')),
                    Action::make('open')->label('Open a new ticket')->icon('ri-add-line')
                        ->authorize(fn (TicketQuarantine $record) => self::staff()->can('update', $record))
                        ->schema([
                            Select::make('client_id')->label('In the account (empty: the sender\'s, as a guest)')->searchable()
                                ->getSearchResultsUsing(fn (string $search) => Client::query()->whereHas('users', fn ($query) => $query->where('email', 'like', "%{$search}%"))
                                    ->limit(20)->get()->mapWithKeys(fn (Client $client) => [$client->id => "#{$client->id} {$client->name}"])->all())
                                ->getOptionLabelUsing(fn ($value) => ($client = Client::query()->find($value)) ? "#{$client->id} {$client->name}" : null),
                        ])
                        ->action(fn (TicketQuarantine $record, array $data) => self::run(fn () => app(QuarantineActions::class)
                            ->open($record, self::staff(), filled($data['client_id'] ?? null) ? (int) $data['client_id'] : null), 'Ticket opened')),
                    Action::make('dismiss')->label('Dismiss')->icon('ri-close-line')->color('gray')->requiresConfirmation()
                        ->authorize(fn (TicketQuarantine $record) => self::staff()->can('update', $record))
                        ->action(fn (TicketQuarantine $record) => self::run(fn () => app(QuarantineActions::class)->dismiss($record, self::staff()), 'Dismissed')),
                    Action::make('block')->label('Block the sender')->icon('ri-mail-forbid-line')->color('danger')->requiresConfirmation()
                        ->modalDescription(fn (TicketQuarantine $record) => "Mail from {$record->from_email} will be dropped from now on, and its pending mail dismissed.")
                        ->authorize(fn (TicketQuarantine $record) => self::staff()->can('update', $record) && self::staff()->can('create', TicketBlockedSender::class))
                        ->visible(fn (TicketQuarantine $record) => filled($record->from_email))
                        ->action(fn (TicketQuarantine $record) => self::run(fn () => app(QuarantineActions::class)->block($record, self::staff()), 'Blocked')),
                ])->visible(fn (TicketQuarantine $record) => $record->isPending()),
            ]);
    }

    private static function run(\Closure $action, string $done): void
    {
        try {
            $action();
            Notification::make()->success()->title($done)->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->warning()->title($e->getMessage())->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => ListTicketQuarantine::route('/')];
    }
}
