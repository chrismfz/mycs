<?php

namespace App\Admin\Resources;

use App\Admin\Resources\MailcowDomainResource\Pages\ListMailcowDomains;
use App\Models\MailcowDomain;
use App\Models\Server;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Utilities → Mail Domains (owner 2026-10-28: «what goes on in each server», as Proxmox Guests): every domain of the
 * mailcow servers (mailcow:sync, every 15 minutes) with its mailboxes, aliases and use, each mapped to the customer's
 * service it is, or marked internal. Read-only on the server: mapping changes nothing there.
 */
class MailcowDomainResource extends Resource
{
    protected static ?string $model = MailcowDomain::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-mail-settings-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Utilities';

    protected static ?string $navigationLabel = 'Mail Domains';

    protected static ?string $modelLabel = 'mail domain';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('domain')
            ->modifyQueryUsing(fn (Builder $query) => $query->whereHas('server')->with(['server', 'service.product', 'service.client']))
            ->columns([
                // Shown by itself when there is more than one mailcow server.
                TextColumn::make('server.name')->label('Server')
                    ->toggleable(isToggledHiddenByDefault: Server::query()->where('extension', 'Mailcow')->count() <= 1),
                TextColumn::make('domain')->sortable()->searchable()
                    ->description(fn (MailcowDomain $record) => $record->admins ? 'admin: ' . implode(', ', $record->admins) : null),
                IconColumn::make('active')->boolean()->sortable(),
                // Its DNS as last checked (after each read): the service page says what is missing.
                TextColumn::make('dns_status')->label('DNS')->badge()->sortable()->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'ok' => '✓', 'fail' => '✗', default => '!'
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'ok' => 'success', 'fail' => 'danger', default => 'warning'
                    })
                    ->tooltip(fn (MailcowDomain $record) => match ($record->dns_status) {
                        'ok' => 'MX, SPF, DKIM, DMARC and autodiscover as they should be',
                        'warn' => 'An advised record missing, or one to check',
                        'fail' => 'A needed record (MX, SPF or DKIM) missing',
                        default => null,
                    } . ($record->dns_checked_at ? ' · checked ' . $record->dns_checked_at->diffForHumans() : '')),
                TextColumn::make('mailboxes')->sortable()
                    ->formatStateUsing(fn (MailcowDomain $record) => $record->mailboxes . ' / ' . ($record->mailbox_limit ?: '∞')),
                TextColumn::make('aliases')->sortable()->toggleable()
                    ->formatStateUsing(fn (MailcowDomain $record) => $record->aliases . ' / ' . ($record->alias_limit ?: '∞')),
                TextColumn::make('used_bytes')->label('Used / quota')->sortable()
                    ->formatStateUsing(fn (MailcowDomain $record) => Number::fileSize($record->used_bytes, 1) . ' / ' . ($record->quota_bytes ? Number::fileSize($record->quota_bytes) : '∞'))
                    ->description(fn (MailcowDomain $record) => self::percent($record->used_bytes, $record->quota_bytes))
                    ->color(fn (MailcowDomain $record) => $record->quota_bytes > 0 && $record->used_bytes >= 0.9 * $record->quota_bytes ? 'danger' : null),
                TextColumn::make('messages')->numeric()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('mapping')->label('Is')->badge()
                    ->state(fn (MailcowDomain $record) => match (true) {
                        $record->service !== null => '#' . $record->service_id . ' ' . ($record->service->product->name ?? '') . ' · ' . ($record->service->client->name ?? ''),
                        $record->internal => 'Internal' . (filled($record->note) ? ': ' . $record->note : ''),
                        default => 'Not mapped',
                    })
                    ->color(fn (MailcowDomain $record) => $record->service_id !== null ? 'success' : ($record->internal ? 'gray' : 'warning'))
                    ->url(fn (MailcowDomain $record) => $record->service_id !== null ? ServiceResource::getUrl('edit', ['record' => $record->service_id]) : null),
                TextColumn::make('last_seen_at')->label('Last read')->since()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('mapping')->label('Mapping')
                    ->options(['unmapped' => 'Not mapped', 'service' => 'A service', 'internal' => 'Internal'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'unmapped' => $query->whereNull('service_id')->where('internal', false),
                        'service' => $query->whereNotNull('service_id'),
                        'internal' => $query->where('internal', true),
                        default => $query,
                    }),
                SelectFilter::make('dns_status')->label('DNS')->options(['fail' => '✗ A needed record missing', 'warn' => '! To check', 'ok' => '✓ OK']),
                SelectFilter::make('server_id')->label('Server')->relationship('server', 'name', fn (Builder $query) => $query->where('extension', 'Mailcow')),
                TernaryFilter::make('gone')->label('Gone from the server')
                    ->queries(true: fn (Builder $query) => $query->whereNotNull('gone_at'), false: fn (Builder $query) => $query->whereNull('gone_at'))
                    ->default(false),
            ])
            ->recordActions([
                Action::make('mailboxes')->label('Mailboxes')->icon('ri-inbox-line')->color('gray')
                    ->modalHeading(fn (MailcowDomain $record) => 'Mailboxes of ' . $record->domain)
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (MailcowDomain $record) => view('admin.mailcow.mailboxes', ['mailboxes' => $record->mailboxList()->get()])),
                Action::make('assign')->label('Service')->icon('ri-links-line')
                    ->authorize('update')
                    ->fillForm(fn (MailcowDomain $record) => ['service_id' => $record->service_id ?? $record->suggestedServiceId()])
                    ->schema([
                        Select::make('service_id')->label('Service')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search) => ProxmoxGuestResource::services($search))
                            ->getOptionLabelUsing(fn ($value) => ProxmoxGuestResource::serviceLabel(Service::query()->with(['product', 'client'])->find($value))),
                    ])
                    ->action(function (MailcowDomain $record, array $data) {
                        $record->update(['service_id' => (int) $data['service_id'], 'internal' => false, 'note' => null]);
                        Notification::make()->success()->title('Mapped')->send();
                    }),
                Action::make('internal')->label('Internal')->icon('ri-home-gear-line')->color('gray')
                    ->authorize('update')
                    ->fillForm(fn (MailcowDomain $record) => ['note' => $record->note])
                    ->schema([TextInput::make('note')->label('What it is')->maxLength(255)->placeholder('e.g. our own mail, a test')])
                    ->action(function (MailcowDomain $record, array $data) {
                        $record->update(['service_id' => null, 'internal' => true, 'note' => $data['note'] ?? null]);
                        Notification::make()->success()->title('Marked internal')->send();
                    }),
                Action::make('unmap')->label('Clear')->icon('ri-link-unlink')->color('gray')
                    ->authorize('update')
                    ->visible(fn (MailcowDomain $record) => $record->service_id !== null || $record->internal)
                    ->requiresConfirmation()
                    ->action(fn (MailcowDomain $record) => $record->update(['service_id' => null, 'internal' => false, 'note' => null])),
            ]);
    }

    public static function percent(int $used, int $quota): ?string
    {
        return $quota > 0 ? round(100 * $used / $quota) . '%' : null;
    }

    public static function getPages(): array
    {
        return ['index' => ListMailcowDomains::route('/')];
    }
}
