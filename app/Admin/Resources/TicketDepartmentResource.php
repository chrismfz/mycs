<?php

namespace App\Admin\Resources;

use App\Admin\Resources\TicketDepartmentResource\Pages\CreateTicketDepartment;
use App\Admin\Resources\TicketDepartmentResource\Pages\EditTicketDepartment;
use App\Admin\Resources\TicketDepartmentResource\Pages\ListTicketDepartments;
use App\Models\Staff;
use App\Models\TicketDepartment;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use MyCS\TicketMail\Imap\Mailbox;

/**
 * Ticket departments (docs/tickets.md §5): WHMCS «Support», «Sales»…, each with its own address and mailbox (A2). The
 * password is stored encrypted and never shown again; «Test connection» logs in and counts, changing nothing. A
 * department with tickets is hidden, not deleted.
 */
class TicketDepartmentResource extends Resource
{
    protected static ?string $model = TicketDepartment::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-customer-service-2-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-customer-service-2-fill';

    protected static ?string $navigationLabel = 'Ticket Departments';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)
            ->components([
                Section::make(__('Department'))->columns(2)->schema([
                    TextInput::make('name')->label('Name')->required()->maxLength(255),
                    TextInput::make('email')->label('Email')->email()->maxLength(255)
                        ->helperText('The department\'s address (e.g. support@…): its mails come from it, and a mail from it is never a ticket.'),
                    TextInput::make('sort')->label('Order')->integer()->minValue(0)->maxValue(65535)->default(0),
                    Toggle::make('hidden')->label('Hidden')->helperText('Not offered to customers; staff still use it.'),
                    Toggle::make('clients_only')->label('Clients only')
                        ->helperText('No tickets from guests (the contact form, mail from unknown senders).'),
                    Select::make('notifyStaff')->label('Notify')->multiple()->relationship('notifyStaff', 'email')->preload()
                        ->getOptionLabelFromRecordUsing(fn (Staff $staff) => "{$staff->name} <{$staff->email}>")
                        ->helperText('Staff mailed of its new tickets and customers\' replies, when the ticket is assigned to no one (then its staff member is). Only staff who may see tickets.'),
                ]),
                Section::make(__('Mailbox'))->columns(2)
                    ->description('Read every 5 minutes when «Read the mailbox» is on. A reply joins a ticket only by our mail\'s Message-ID and only from the ticket\'s owner; anything else waits in Support → Mail Quarantine.')
                    ->schema([
                        TextInput::make('imap_host')->label('IMAP host')->maxLength(255)->required(fn (Get $get) => (bool) $get('polling')),
                        TextInput::make('imap_port')->label('Port')->integer()->minValue(1)->maxValue(65535)->placeholder('993'),
                        Select::make('imap_encryption')->label('Encryption')->required()->default('ssl')
                            ->options(['ssl' => 'SSL/TLS (993)', 'starttls' => 'STARTTLS (143)'])
                            ->helperText('Never without encryption: the password is sent.'),
                        TextInput::make('imap_folder')->label('Folder')->maxLength(255)->default('INBOX')->required(),
                        TextInput::make('imap_username')->label('Login')->maxLength(255)->autocomplete(false)->required(fn (Get $get) => (bool) $get('polling')),
                        TextInput::make('imap_password')->label('Password')->password()->revealable(false)->maxLength(500)
                            ->autocomplete('new-password')->dehydrated(fn ($state) => filled($state))
                            // A new host or login asks for it again: the stored one never goes to another server.
                            ->required(fn (Get $get, ?TicketDepartment $record) => ((bool) $get('polling') && $record?->imap_password === null)
                                || ($record?->imap_password !== null && ($get('imap_host') !== $record->imap_host || $get('imap_username') !== $record->imap_username)))
                            ->helperText(fn (?TicketDepartment $record) => $record?->imap_password !== null ? 'Stored encrypted and never shown: leave empty to keep it.' : null),
                        TextInput::make('authserv_id')->label('authserv-id of our mail server')->maxLength(255)
                            ->placeholder('mymail.myip.gr')->regex('/^[A-Za-z0-9._-]+$/')
                            ->helperText('The first word of the Authentication-Results our receiving server writes (mailcow: its hostname). Only that header is trusted.'),
                        Toggle::make('require_dmarc')->label('Require DMARC')->default(true)->live()
                            ->helperText(fn (Get $get) => $get('require_dmarc')
                                ? 'A reply joins a ticket only when our server vouches for its From (DMARC, or aligned DKIM/SPF), and a mail goes to an account only then.'
                                : '⚠ Less protected: a reply joins by our Message-ID and the owner\'s From alone, which can be forged by anyone who has seen our mail. For a server that writes no Authentication-Results.'),
                        Toggle::make('polling')->label('Read the mailbox')->default(false)->live()
                            ->helperText(fn (?TicketDepartment $record) => self::lastRun($record)),
                    ]),
            ]);
    }

    /** The last read of the mailbox, for staff. */
    private static function lastRun(?TicketDepartment $department): ?string
    {
        $run = $department?->pollRuns()->latest('started_at')->first();
        if ($run === null) {
            return null;
        }

        return 'Last read ' . $run->started_at->diffForHumans() . ': ' . ($run->connected ? "{$run->fetched} read, {$run->opened} new, {$run->threaded} replies, {$run->quarantined} quarantined" : 'could not connect')
            . ($run->errors ? ' — ' . $run->errors[0] : '');
    }

    /** «Test connection»: logs in with the stored login and counts the folder; changes nothing. */
    public static function testAction(): Action
    {
        return Action::make('testMailbox')->label('Test connection')->icon('heroicon-o-signal')
            ->visible(fn (TicketDepartment $record) => $record->hasMailbox())
            ->authorize(fn (TicketDepartment $record) => auth('staff')->user()?->can('update', $record) ?? false)
            ->action(function (TicketDepartment $record) {
                $result = app(Mailbox::class)->test($record->mailboxSettings());
                Notification::make()->title($result->ok ? 'Connected' : 'Could not connect')->body($result->detail)
                    ->{$result->ok ? 'success' : 'danger'}()->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Name')->searchable(),
                TextColumn::make('email')->label('Email')->placeholder('—'),
                TextColumn::make('sort')->label('Order')->sortable(),
                IconColumn::make('hidden')->label('Hidden')->boolean()->falseColor('gray')->falseIcon('heroicon-o-minus'),
                IconColumn::make('clients_only')->label('Clients only')->boolean()->falseColor('gray')->falseIcon('heroicon-o-minus'),
                TextColumn::make('tickets_count')->label('Tickets')->counts('tickets'),
                IconColumn::make('polling')->label('Mailbox read')->boolean()->falseColor('gray')->falseIcon('heroicon-o-minus'),
            ])
            ->defaultSort('sort')
            ->recordActions([
                self::testAction(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTicketDepartments::route('/'),
            'create' => CreateTicketDepartment::route('/create'),
            'edit' => EditTicketDepartment::route('/{record}/edit'),
        ];
    }
}
