<?php

namespace App\Admin\Resources\TicketResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Components\ClientComponent;
use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\TicketResource;
use App\Models\CannedReply;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketCc;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Support\Tickets\CannedReplyExpander;
use App\Support\Tickets\GuestLinks;
use App\Support\Tickets\MergeTickets;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use MyCS\TicketMail\Attachments;

class EditTicket extends EditRecord
{
    protected static string $resource = TicketResource::class;

    protected string $view = 'admin.resources.ticket-resource.pages.edit-ticket';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                // A predefined reply into the editor, its tokens filled in; added after what staff already wrote (§6ι).
                Select::make('canned')
                    ->label('Predefined reply')
                    ->placeholder('Insert a predefined reply…')
                    ->options(fn () => CannedReply::options())
                    ->searchable()
                    ->live()
                    ->dehydrated(false)
                    ->visible(fn () => CannedReply::query()->where('is_active', true)->exists())
                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                        $reply = filled($state) ? CannedReply::query()->where('is_active', true)->find($state) : null;
                        if ($reply !== null && $this->record instanceof Ticket) {
                            $staff = Auth::guard('staff')->user();
                            $text = CannedReplyExpander::expand($reply->body, $this->record, $staff instanceof Staff ? $staff : null);
                            $current = rtrim((string) $get('message'));
                            $set('message', $current === '' ? $text : $current . "\n\n" . $text);
                        }
                        $set('canned', null);
                    }),
                MarkdownEditor::make('message')
                    ->label('Message')
                    ->toolbarButtons([
                        ['bold', 'italic', 'strike', 'link'],
                        ['heading'],
                        ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
                        ['table'],
                        ['undo', 'redo'],
                    ])
                    ->required(fn (Get $get) => count($get('attachments')) == 0),
                FileUpload::make('attachments')
                    ->label('Attachments')
                    ->visibility('private')
                    ->directory('tickets/uploads')
                    ->multiple()
                    // The same types and sizes as customers' (docs/tickets.md §3 ΣΤ).
                    ->acceptedFileTypes(array_values(array_unique(array_merge(...array_values(Attachments::TYPES)))))
                    ->maxSize(Attachments::MAX_BYTES / 1024)
                    ->maxFiles(Attachments::MAX_COUNT)
                    ->storeFileNamesIn('attachment_names')
                    ->reorderable(),
            ]);
    }

    /** The one way a guest's ticket reaches an account: staff, by hand, never by its email (docs/tickets.md §3 Δ). */
    public function linkToClient(): Action
    {
        return Action::make('linkToClient')
            ->label('Link to client')
            ->icon('heroicon-o-link')
            ->color('gray')
            ->schema([ClientComponent::make('client_id')])
            ->visible(fn () => $this->record instanceof Ticket && $this->record->isGuest() && auth()->user()->can('update', $this->record))
            ->action(fn (array $data) => $this->record instanceof Ticket && $this->record->update(['client_id' => (int) $data['client_id']]))
            ->after(fn () => $this->record->refresh());
    }

    /** The guest's links stop working (a mail reached someone else): the next reply mails a new one (docs/tickets.md §3 Δ). */
    public function revokeGuestLinks(): Action
    {
        return Action::make('revokeGuestLinks')
            ->label('Revoke guest links')
            ->icon('heroicon-o-no-symbol')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Every link mailed to the guest stops opening this ticket. The next reply mails a new one.')
            ->visible(fn () => $this->record instanceof Ticket && $this->record->isGuest() && auth()->user()->can('update', $this->record))
            ->action(fn () => $this->record instanceof Ticket && GuestLinks::revoke($this->record));
    }

    public function closeTicket(): Action
    {
        return Action::make('closeTicket')
            ->label(__('ticket.close_ticket'))
            ->color('danger')
            ->hidden(!auth()->user()->can('update', $this->record) || $this->record->status === 'closed')
            ->action(fn (Ticket $record) => $record->update(['status' => 'closed']))
            ->after(function () {
                $this->record->refresh();

                Notification::make()
                    ->title('Ticket closed successfully.')
                    ->success()
                    ->send();
            });
    }

    // Save action
    public function send()
    {
        $this->store(internal: false);
    }

    /** A note for staff only: never mailed, never shown to the customer, moves no status (docs/tickets.md §5). */
    public function note()
    {
        $this->store(internal: true);

        Notification::make()->title(__('Internal note added.'))->success()->send();
    }

    private function store(bool $internal): void
    {
        // A merged ticket takes nothing more: its thread went on in the one it was merged into (§6κ).
        if ($this->record instanceof Ticket && $this->record->fresh()?->isMerged()) {
            Notification::make()->title(__('This ticket was merged into another: write there.'))->warning()->send();

            return;
        }
        $data = $this->form->getState();

        $message = $this->record->messages()->create([
            'staff_id' => Auth::guard('staff')->id(),
            'message' => $data['message'] ?? '',
            'is_internal' => $internal,
        ]);

        // Move attachments
        foreach ($data['attachments'] as $attachment) {
            $originalPath = storage_path('app/' . $attachment);
            $filename = $data['attachment_names'][$attachment];
            $mimeType = File::mimeType($originalPath);
            $filesize = File::size($originalPath);

            $message->attachments()->create([
                'path' => $attachment,
                'filename' => $filename,
                'mime_type' => $mimeType,
                'filesize' => $filesize,
            ]);
        }

        $this->form->fill();
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->record($this->record)
            ->columns(['default' => 3, 'md' => 1])
            ->components([
                // A guest's ticket names its guest; staff link it to an account by hand (docs/tickets.md §3 Δ).
                TextEntry::make('client_id')
                    ->size(TextSize::Large)
                    ->state(fn (Ticket $record) => $record->isGuest() ? __('Guest') . ': ' . $record->guest_name . ' <' . $record->guest_email . '>' : $record->client?->name)
                    ->url(fn (Ticket $record) => $record->client ? ClientResource::getUrl('view', ['record' => $record->client]) : null)
                    // Opened by a mail our server did not vouch for: the address may be forged (our replies to it say
                    // nothing of what the sender wrote).
                    ->helperText(fn (Ticket $record) => $record->sender_vouched === false ? '⚠ ' . __('Our mail server did not vouch for this address: it may be forged.') : null)
                    ->label('Client'),
                TextEntry::make('reference')
                    ->size(TextSize::Large)
                    ->label('Number'),
                TextEntry::make('subject')
                    ->size(TextSize::Large)
                    ->label('Subject'),
                TextEntry::make('status')
                    ->size(TextSize::Large)
                    ->badge()
                    ->formatStateUsing(fn ($state) => Ticket::statusLabel($state))
                    ->color(fn ($state) => Ticket::statusColor($state))
                    ->label('Status'),
                TextEntry::make('priority')
                    ->size(TextSize::Large)
                    ->badge()
                    ->formatStateUsing(fn ($state) => ucfirst($state))
                    ->color(fn ($state) => Ticket::priorityColor($state))
                    ->label('Priority'),
                TextEntry::make('department.name')
                    ->size(TextSize::Large)
                    ->placeholder('No department')
                    ->label('Department'),

                TextEntry::make('assigned_to')
                    ->size(TextSize::Large)
                    ->label('Assigned To')
                    ->placeholder('No assigned user')
                    ->formatStateUsing(fn ($record) => $record->assignedTo->name),

                TextEntry::make('ccs')
                    ->size(TextSize::Large)
                    ->label('CC')
                    ->state(fn (Ticket $record) => $record->ccs()->orderBy('id')->pluck('email')->all())
                    ->listWithLineBreaks()
                    ->placeholder('No one copied'),

                TextEntry::make('service_id')
                    ->size(TextSize::Large)
                    ->label('Service')
                    ->url(fn ($record) => $record->service ? ServiceResource::getUrl('edit', ['record' => $record->service]) : null)
                    ->placeholder('No service')
                    ->formatStateUsing(fn ($record) => $this->formatServiceOptionLabel($record->service)),

                Actions::make([
                    Action::make('Edit')
                        ->schema(function (Schema $schema) {
                            return $schema
                                ->columns(2)
                                ->components([
                                    Select::make('status')
                                        ->label('Status')
                                        ->options(Ticket::statusOptions())
                                        ->default('open')
                                        ->required(),
                                    Select::make('priority')
                                        ->label('Priority')
                                        ->options([
                                            'low' => 'Low',
                                            'medium' => 'Medium',
                                            'high' => 'High',
                                        ])
                                        ->default('medium')
                                        ->required(),
                                    Select::make('department_id')
                                        ->label('Department')
                                        ->relationship('department', 'name', fn (Builder $query) => $query->orderBy('sort')),
                                    // A guest's ticket keeps no account until staff choose one (docs/tickets.md §3 Δ).
                                    ClientComponent::make('client_id')->required(fn (Ticket $record) => !$record->isGuest()),
                                    Select::make('assigned_to')
                                        ->label('Assigned To')
                                        ->relationship('assignedTo', 'id', fn (Builder $query) => $query->where('active', true))
                                        ->searchable()
                                        ->preload()
                                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                                    Select::make('service_id')
                                        ->label('Service')
                                        ->relationship('service', 'id', function (Builder $query, Get $get) {
                                            $query->where('client_id', $get('client_id'));
                                        })
                                        ->getOptionLabelFromRecordUsing(fn ($record) => $this->formatServiceOptionLabel($record))
                                        ->disabled(fn (Get $get) => !$get('client_id')),
                                ]);
                        })
                        ->fillForm(fn ($record) => [
                            'status' => $record->status,
                            'priority' => $record->priority,
                            'department_id' => $record->department_id,
                            'client_id' => $record->client_id,
                            'assigned_to' => $record->assigned_to,
                            'service_id' => $record->service_id,
                        ])
                        ->action(function (array $data, Ticket $record): void {
                            $record->update($data);
                        })
                        // A merged ticket stays closed: reopened, it would wait on a reply it cannot take (review of #266).
                        ->hidden(!auth()->user()->can('update', $this->record) || ($this->record instanceof Ticket && $this->record->isMerged()))
                        ->icon('heroicon-o-pencil'),
                    // Addresses that get staff's replies and whose replies join the ticket (docs/tickets.md §6λ).
                    Action::make('cc')
                        ->label('CC')
                        ->icon('heroicon-o-users')
                        ->color('gray')
                        ->schema([
                            TagsInput::make('emails')
                                ->label('Copied addresses')
                                ->placeholder('name@example.com')
                                ->nestedRecursiveRules(['email:rfc', 'max:255'])
                                ->helperText('They get staff\'s replies (not notes), each its own mail without a link to the ticket; their replies join it.'),
                        ])
                        ->fillForm(fn (Ticket $record) => ['emails' => $record->ccs()->orderBy('id')->pluck('email')->all()])
                        ->action(function (array $data, Ticket $record, Action $action) {
                            $emails = array_values(array_unique(array_map(fn ($email) => TicketCc::normalise((string) $email), $data['emails'] ?? [])));
                            // Copying an address of ours would have us mail, and thread, ourselves: an address as the router
                            // tells ours (a department's email or IMAP login, plus-tags and case folded).
                            $ours = $this->ourAddresses();
                            if (array_filter($emails, fn ($email) => in_array(TicketBlockedSender::base($email), $ours, true)) !== []) {
                                Notification::make()->title(__('An address of ours cannot be copied.'))->danger()->send();
                                $action->halt();
                            }
                            if (count($emails) > TicketCc::MAX) {
                                Notification::make()->title(__('At most :count copied addresses.', ['count' => TicketCc::MAX]))->danger()->send();
                                $action->halt();
                            }
                            $record->ccs()->whereNotIn('email', $emails)->delete();
                            foreach ($emails as $email) {
                                $record->ccs()->firstOrCreate(['email' => $email], ['added_by' => Auth::guard('staff')->id()]);
                            }
                        })
                        ->hidden(!auth()->user()->can('update', $this->record) || ($this->record instanceof Ticket && $this->record->isMerged())),
                    // A duplicate into another ticket of the same owner (docs/tickets.md §6κ).
                    Action::make('merge')
                        ->label('Merge into…')
                        ->icon('heroicon-o-arrows-pointing-in')
                        ->color('gray')
                        ->schema([
                            Select::make('target_id')
                                ->label('Ticket to keep')
                                ->required()
                                ->searchable()
                                ->options(fn (Ticket $record) => $this->mergeTargets($record))
                                ->getSearchResultsUsing(fn (string $search, Ticket $record) => $this->mergeTargets($record, $search))
                                ->helperText('Only tickets of the same customer (or guest address). This ticket\'s messages move there and it is closed.'),
                        ])
                        ->requiresConfirmation()
                        ->modalDescription('Nothing is mailed. The customer\'s replies to this ticket\'s mails and its links lead to the ticket kept.')
                        ->action(function (array $data, Ticket $record) {
                            $target = Ticket::query()->find($data['target_id']);
                            $staff = Auth::guard('staff')->user();
                            if ($target === null || !$record->canMergeInto($target) || !auth()->user()->can('update', $target)) {
                                Notification::make()->title(__('These tickets cannot be merged: another owner, or one already merged.'))->danger()->send();

                                return;
                            }
                            app(MergeTickets::class)->handle($record, $target, $staff instanceof Staff ? $staff : null);
                            Notification::make()->title(__('Merged into ticket #:reference.', ['reference' => $target->reference]))->success()->send();
                            $this->redirect(TicketResource::getUrl('edit', ['record' => $target]));
                        })
                        ->hidden(!auth()->user()->can('update', $this->record) || ($this->record instanceof Ticket && $this->record->isMerged())),
                    Action::make('Delete')
                        ->color('danger')
                        ->icon('heroicon-o-trash')
                        ->requiresConfirmation()
                        ->action(fn (Ticket $record) => $record->delete())
                        ->hidden(!auth()->user()->can('delete', $this->record))
                        ->after(function (Action $action) {
                            Notification::make()
                                ->title(__('filament-actions::delete.single.notifications.deleted.title'))
                                ->success()
                                ->send();

                            $action->redirect(TicketResource::getUrl('index'));
                        }),
                    AuditAction::make()->auditChildren([
                        'messages',
                    ]),
                ])->key('ticketActions')->columnSpan(['default' => 'full', 'md' => 1]),
            ]);
    }

    /** @return list<string> our addresses as InboundRouter tells them (their base): copying one would mail ourselves */
    private function ourAddresses(): array
    {
        return TicketDepartment::query()->get()->flatMap(fn (TicketDepartment $department) => $department->ownAddresses())
            ->map(fn ($email) => TicketBlockedSender::base((string) $email))->unique()->values()->all();
    }

    /** @return array<int, string> the tickets this one may be merged into, newest first, by number or subject */
    private function mergeTargets(Ticket $record, ?string $search = null): array
    {
        $query = Ticket::query()->whereKeyNot($record->id)->whereNull('merged_into_id');
        $record->client_id !== null
            ? $query->where('client_id', $record->client_id)
            : $query->whereNull('client_id')->whereRaw('LOWER(guest_email) = ?', [mb_strtolower(trim((string) $record->guest_email))]);
        if (filled($search)) {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($search))) . '%';
            $query->where(fn ($q) => $q->where('reference', ltrim(trim($search), '#'))->orWhereRaw("LOWER(subject) LIKE ? ESCAPE '!'", [$like]));
        }

        return $query->latest('id')->limit(50)->get()
            ->mapWithKeys(fn (Ticket $ticket) => [$ticket->id => '#' . $ticket->reference . ' · ' . $ticket->subject . ' (' . Ticket::statusLabel($ticket->status) . ')'])->all();
    }

    private function formatServiceOptionLabel($record): string
    {
        return "{$record->product->name} #{$record->id} - " . ucfirst($record->status) . ($record->label == $record->baseLabel ? '' : " ({$record->label})");
    }

    public function deleteMessage(TicketMessage $message): void
    {
        if (auth()->user()->can('delete', $message)) {
            $message->delete();
        }
    }
}
