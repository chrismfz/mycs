<?php

namespace App\Admin\Resources;

use App\Admin\Components\ClientComponent;
use App\Admin\Resources\DomainResource\Pages\ListDomains;
use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Domain;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * WHMCS's «Domains» (docs/domains.md, D1): the domains of every account, on their own, beside Products/Services. Read
 * only for now: registering, renewing, syncing and the rest come as actions with D2–D5.
 */
class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-global-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-global-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'fqdn';

    public static function getNavigationLabel(): string
    {
        return __('domains.domains');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['fqdn'];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client.primaryContact', 'domainTld.registrarConnection', 'registrarConnection']))
            ->defaultSort('expires_at')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('fqdn')->label(__('domains.domain'))->searchable()->sortable(),
                TextColumn::make('client.name')->label(__('Client'))
                    ->url(fn (Domain $record) => ClientResource::canView($record->client) ? ClientResource::getUrl('view', ['record' => $record->client_id]) : null),
                TextColumn::make('registrar')->label(__('Registrar'))
                    ->state(fn (Domain $record) => $record->effectiveRegistrarConnection()->label ?? '—'),
                TextColumn::make('expires_at')->label(__('Expires'))->date()->sortable()->placeholder('—'),
                IconColumn::make('auto_renew')->label(__('Auto renew'))->boolean(),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(DomainStatus::options()),
                Filter::make('expiring')->label(__('Expiring in 60 days'))
                    ->query(fn (Builder $query) => $query->whereBetween('expires_at', [today(), today()->addDays(60)])),
                SelectFilter::make('client')->label(__('Client'))->relationship('client', 'id')->searchable()
                    ->getOptionLabelFromRecordUsing(fn (Client $record) => ClientComponent::optionLabel($record)),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('domains.domain'))->columns(3)->schema([
                TextEntry::make('fqdn')->label(__('domains.domain')),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('client.name')->label(__('Client')),
                TextEntry::make('registrar')->label(__('Registrar'))
                    ->state(fn (Domain $record) => $record->effectiveRegistrarConnection()->label ?? '—'),
                TextEntry::make('registered_at')->label(__('Registered'))->date()->placeholder('—'),
                TextEntry::make('expires_at')->label(__('Expires'))->date()->placeholder('—'),
                TextEntry::make('years')->label(__('Period (years)')),
                TextEntry::make('recurring_amount')->label(__('Recurring amount'))->placeholder(__('The TLD\'s renew price')),
                TextEntry::make('auto_renew')->label(__('Auto renew'))->formatStateUsing(fn (bool $state) => $state ? __('Yes') : __('No')),
                TextEntry::make('last_synced_at')->label(__('Last synced'))->dateTime()->placeholder('—'),
                TextEntry::make('sync_error')->label(__('Sync error'))->placeholder('—')->columnSpan(2),
            ]),
            Section::make(__('Nameservers'))->schema([
                TextEntry::make('nameservers.host')->hiddenLabel()->listWithLineBreaks()->placeholder('—'),
            ]),
            Section::make(__('Contacts'))->columns(4)->schema(array_map(
                fn (string $role) => TextEntry::make("{$role}Contact.email")->label(__('domains.role.' . $role))
                    ->state(fn (Domain $record) => $record->{"{$role}Contact"}?->displayName() ?? '—'),
                Domain::ROLES,
            )),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomains::route('/'),
            'view' => ViewDomain::route('/{record}'),
        ];
    }
}
