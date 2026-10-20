<?php

namespace App\Admin\Resources\TicketResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Components\ClientComponent;
use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Get;
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
        $data = $this->form->getState();

        $message = $this->record->messages()->create([
            'staff_id' => Auth::guard('staff')->id(),
            'message' => $data['message'] ?? '',
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
                        ->hidden(!auth()->user()->can('update', $this->record))
                        ->icon('heroicon-o-pencil'),
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
                ])->columnSpan(['default' => 'full', 'md' => 1]),
            ]);
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
