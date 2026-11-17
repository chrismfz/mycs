<?php

namespace App\Admin\Resources\ClientResource\Pages;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Concerns\NamesTheClient;
use App\Models\Client;
use App\Models\LiveChat;
use App\Models\Staff;
use App\Support\LiveChats;
use App\Support\VisitorChatTools;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The account's live chats, open and closed, read only (docs/livechat.md §6α), beside its Tickets and Emails: for our own
 * reference, never shown to the customer here. Only an operator (`admin.chats.view`) sees the tab, and only the chats of
 * the departments that list them (F3), filtered in the query on every request (F7). Opening the list writes nothing.
 */
class ShowChats extends ManageRelatedRecords
{
    use NamesTheClient;

    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'liveChats';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-chat-history-line';

    public static function getNavigationLabel(): string
    {
        return __('Chats');
    }

    /** Checked at mount and again on every Livewire request (ManageRelatedRecords::hydrate). */
    public static function canAccess(array $parameters = []): bool
    {
        return LiveChats::isOperator(self::me()) && parent::canAccess($parameters);
    }

    /** The tab stays lit while one of its chats is open. */
    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        return [static::getRouteName(), ViewChat::getRouteName()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => LiveChats::seenBy($query, self::me())
                ->with(['department:id,name', 'operator:id,first_name,last_name,email', 'user:id,first_name,last_name'])
                ->withCount('messages'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('created_at')->label(__('Started'))->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('department.name')->label(__('Department'))->placeholder('—'),
                TextColumn::make('operator.name')->label(__('Operator'))->placeholder('—'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->color(fn (string $state) => match ($state) {
                        LiveChat::WAITING => 'warning', LiveChat::ACTIVE => 'success', default => 'gray'
                    })
                    ->formatStateUsing(fn (string $state) => __(ucfirst($state))),
                // Which login of the account started it (G11), not only the account.
                TextColumn::make('user.name')->label(__('Started by'))
                    ->placeholder(fn (LiveChat $record) => $record->started_anonymously ? __('Visitor (linked)') : '—'),
                TextColumn::make('messages_count')->label(__('Messages'))->numeric(),
                TextColumn::make('closed_at')->label(__('Closed'))->dateTime('d/m/Y H:i')->placeholder('—'),
            ])
            ->headerActions([
                // §6α: a closed visitor's chat, by its number, with the member's password again (F18); audited by ids.
                Action::make('linkVisitorChat')->label(__('Link a visitor\'s chat'))->icon('ri-links-line')->color('gray')
                    ->visible(fn () => (bool) self::me()?->hasPermission('admin.chats.link'))
                    ->modalDescription(__('Only a closed chat. The customer does not see it; it shows here, marked as started anonymously.'))
                    ->schema([
                        TextInput::make('chat')->label(__('Chat number'))->integer()->minValue(1)->required(),
                        TextInput::make('password')->label(__('Your password'))->password()->required(),
                    ])
                    ->action(function (array $data, Action $action) {
                        $staff = self::me();
                        /** @var Client $client */
                        $client = $this->getRecord();
                        $refusal = $staff === null ? __('You may not link chats.')
                            : VisitorChatTools::link($staff, (int) $data['chat'], $client, (string) $data['password']);
                        if ($refusal !== null) {
                            Notification::make()->title($refusal)->danger()->send();
                            $action->halt();
                        }
                        Notification::make()->title(__('The chat is linked to this client.'))->success()->send();
                    }),
            ])
            ->recordUrl(fn (LiveChat $record) => $this->chatUrl($record))
            ->recordActions([
                Action::make('view')->label(__('View'))->icon('ri-eye-line')->url(fn (LiveChat $record) => $this->chatUrl($record)),
            ])
            ->emptyStateHeading(__('No chats'))
            ->paginated([25, 50, 100]);
    }

    /**
     * Only the sizes the table offers (ADV-707-1): Filament takes `tableRecordsPerPage` from the browser as it comes, so
     * a forged «all» or 100000 would load the whole history in one request.
     */
    public function getTableRecordsPerPage(): int|string|null
    {
        $options = $this->getTable()->getPaginationPageOptions();

        return in_array($this->tableRecordsPerPage, $options, false) && $this->tableRecordsPerPage !== 'all'
            ? (int) $this->tableRecordsPerPage : $this->getDefaultTableRecordsPerPageSelectOption();
    }

    private function chatUrl(LiveChat $chat): string
    {
        /** @var Client $client */
        $client = $this->getRecord();

        return ViewChat::getUrl(['record' => $client, 'chat' => $chat->id]);
    }

    private static function me(): ?Staff
    {
        $staff = auth('staff')->user();

        return $staff instanceof Staff ? $staff : null;
    }
}
