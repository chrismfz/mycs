<?php

namespace App\Admin\Resources;

use App\Admin\Resources\EmailLogResource\Pages\ListEmailLogs;
use App\Admin\Resources\EmailLogResource\Pages\ViewEmailLog;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Services\Mail\MailAudit;
use App\Support\Redact;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The mail audit (docs/mail.md): every mail MyCS sent or tried to, with who it went to and from, its template, what it
 * was about, who sent it and its way out; filters, the body as sent (secrets masked) and Resend.
 */
class EmailLogResource extends Resource
{
    protected static ?string $model = EmailLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-mail-send-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-mail-send-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Other';

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            EmailLog::STATUS_SENT => 'success',
            EmailLog::STATUS_FAILED => 'danger',
            EmailLog::STATUS_SENDING => 'info',
            default => 'gray',
        };
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            EmailLog::STATUS_PENDING => 'Queued',
            null => '—',
            default => ucfirst($status),
        };
    }

    /** The page of what the mail was about, where staff have one. */
    public static function relatedUrl(EmailLog $record): ?string
    {
        if ($record->related_id === null) {
            return null;
        }

        return match ($record->related_type ? EmailLog::getActualClassNameForMorph($record->related_type) : null) {
            Invoice::class => InvoiceResource::getUrl('view', ['record' => $record->related_id]),
            Service::class => ServiceResource::getUrl('edit', ['record' => $record->related_id]),
            Order::class => OrderResource::getUrl('edit', ['record' => $record->related_id]),
            Ticket::class => TicketResource::getUrl('edit', ['record' => $record->related_id]),
            default => null,
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->columns(3)->columnSpanFull()->schema([
                    TextEntry::make('subject')->columnSpan(2),
                    TextEntry::make('status')->badge()->color(fn (EmailLog $record) => self::statusColor($record->status))
                        ->formatStateUsing(fn (?string $state) => self::statusLabel($state)),
                    TextEntry::make('to'),
                    TextEntry::make('cc')->label('Cc')->state(fn (EmailLog $record) => implode(', ', $record->cc ?? []) ?: null)->placeholder('—'),
                    TextEntry::make('bcc')->label('Bcc')->state(fn (EmailLog $record) => implode(', ', $record->bcc ?? []) ?: null)->placeholder('—'),
                    TextEntry::make('from_address')->label('From')->placeholder('—'),
                    TextEntry::make('template_key')->label('Template')->placeholder('—'),
                    TextEntry::make('related')->label('About')->state(fn (EmailLog $record) => $record->relatedLabel())
                        ->url(fn (EmailLog $record) => self::relatedUrl($record))->placeholder('—'),
                    TextEntry::make('client_id')->label('Account')->state(fn (EmailLog $record) => $record->client_id ? '#' . $record->client_id : null)
                        ->url(fn (EmailLog $record) => $record->client_id ? ClientResource::getUrl('edit', ['record' => $record->client_id]) : null)->placeholder('—'),
                    TextEntry::make('trigger')->label('Sent by')
                        ->state(fn (EmailLog $record) => match ($record->trigger) {
                            EmailLog::TRIGGER_MANUAL => 'Resent by ' . ($record->staff instanceof Staff ? $record->staff->name : 'staff'),
                            EmailLog::TRIGGER_BATCH => 'Batch resend',
                            default => 'The system',
                        }),
                    TextEntry::make('resent_from_id')->label('Resend of')->state(fn (EmailLog $record) => $record->resent_from_id ? '#' . $record->resent_from_id : null)
                        ->url(fn (EmailLog $record) => $record->resent_from_id ? self::getUrl('view', ['record' => $record->resent_from_id]) : null)->placeholder('—'),
                    TextEntry::make('created_at')->label('Queued')->dateTime(),
                    TextEntry::make('sent_at')->label('Sent')->dateTime()->placeholder('—'),
                    TextEntry::make('failed_at')->label('Failed')->dateTime()->placeholder('—'),
                    TextEntry::make('attempts'),
                    TextEntry::make('message_id')->label('Message-ID')->placeholder('—')->copyable(),
                    TextEntry::make('error')->columnSpanFull()->hidden(fn ($state) => $state === null),
                ]),
                View::make('admin.infolists.components.iframe')->columnSpanFull(),
            ]);
    }

    /** @return list<TextColumn> shared with the account's, invoice's and service's «Emails» */
    public static function columns(): array
    {
        return [
            TextColumn::make('created_at')->label('Queued')->dateTime()->sortable(),
            TextColumn::make('status')->badge()->color(fn (EmailLog $record) => self::statusColor($record->status))
                ->formatStateUsing(fn (?string $state) => self::statusLabel($state))
                ->tooltip(fn (EmailLog $record) => $record->error),
            TextColumn::make('to')->searchable(),
            TextColumn::make('subject')->searchable()->limit(60),
            TextColumn::make('template_key')->label('Template')->toggleable(),
            TextColumn::make('related_id')->label('About')->state(fn (EmailLog $record) => $record->relatedLabel())
                ->url(fn (EmailLog $record) => self::relatedUrl($record))->toggleable(),
            TextColumn::make('trigger')->label('Sent by')->badge()->color('gray')
                ->formatStateUsing(fn (string $state) => match ($state) {
                    EmailLog::TRIGGER_MANUAL => 'Staff',
                    EmailLog::TRIGGER_BATCH => 'Batch',
                    default => 'System',
                })->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('sent_at')->label('Sent')->dateTime()->sortable()->toggleable(),
        ];
    }

    /** @return list<Filter|SelectFilter> */
    public static function filters(): array
    {
        return [
            SelectFilter::make('status')->multiple()->options([
                EmailLog::STATUS_PENDING => 'Queued',
                EmailLog::STATUS_SENDING => 'Sending',
                EmailLog::STATUS_SENT => 'Sent',
                EmailLog::STATUS_FAILED => 'Failed',
            ]),
            SelectFilter::make('template_key')->label('Template')->multiple()
                ->options(fn () => EmailLog::query()->whereNotNull('template_key')->distinct()->orderBy('template_key')->pluck('template_key', 'template_key')->all()),
            SelectFilter::make('trigger')->label('Sent by')->options([
                EmailLog::TRIGGER_AUTO => 'System',
                EmailLog::TRIGGER_MANUAL => 'Staff',
                EmailLog::TRIGGER_BATCH => 'Batch',
            ]),
            Filter::make('created_at')->label('Queued')
                ->schema([DatePicker::make('from'), DatePicker::make('until')])
                ->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date))),
        ];
    }

    /** Resend to its address or another, with or without its copies. */
    public static function resendAction(): Action
    {
        return Action::make('resend')
            ->label('Resend')
            ->icon('ri-mail-send-line')
            ->authorize('resend')
            ->modalDescription(fn (EmailLog $record) => str_contains($record->body, Redact::MASK)
                ? 'Sent as the log keeps it: what was masked (a password) goes out masked.' : 'Sent as the log keeps it.')
            ->fillForm(fn (EmailLog $record) => ['to' => $record->to, 'with_copies' => true])
            ->schema([
                TextInput::make('to')->label('To')->email()->required(),
                Checkbox::make('with_copies')->label('With its Cc/Bcc')
                    ->visible(fn (EmailLog $record) => ($record->cc ?? []) !== [] || ($record->bcc ?? []) !== []),
            ])
            ->action(function (EmailLog $record, array $data): void {
                $staff = Auth::user();
                $log = MailAudit::resend($record, $data['to'], (bool) ($data['with_copies'] ?? false), $staff instanceof Staff ? $staff : null);
                Notification::make()->title("Queued again as #{$log->id}")->success()->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ...self::columns(),
                TextColumn::make('client_id')->label('Account')->state(fn (EmailLog $record) => $record->client_id ? '#' . $record->client_id : null)
                    ->url(fn (EmailLog $record) => $record->client_id ? ClientResource::getUrl('edit', ['record' => $record->client_id]) : null)->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                ...self::filters(),
                SelectFilter::make('client_id')->label('Account')->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Client::query()->whereHas('primaryContact', fn ($query) => $query
                        ->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%"))->limit(25)->get()->mapWithKeys(fn (Client $client) => [$client->id => "#{$client->id} {$client->name}"])->all())
                    ->getOptionLabelUsing(fn ($value) => "#{$value}"),
            ])
            ->recordActions([
                ViewAction::make(),
                self::resendAction(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailLogs::route('/'),
            'view' => ViewEmailLog::route('/{record}'),
        ];
    }
}
