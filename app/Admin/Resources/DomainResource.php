<?php

namespace App\Admin\Resources;

use App\Admin\Components\ClientComponent;
use App\Admin\Resources\DomainResource\DomainContactActions;
use App\Admin\Resources\DomainResource\Pages\ListDomains;
use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Admin\Resources\DomainResource\RelationManagers\RegistrarLogsRelationManager;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Domain;
use App\Services\Domains\DomainManagementService;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

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

    /** The registrar's record fields shown (D4b), in this order. */
    private const REGISTRAR_DETAILS = [
        'status' => 'Status at the registrar',
        'type' => 'Came in by',
        'creation_date' => 'Created',
        'registry_expiration_date' => 'Expires at the registry',
        'renewal_date' => 'Renewal date',
        'last_changed' => 'Last changed',
        'is_lockable' => 'Lockable',
        'is_private_whois_allowed' => 'Privacy allowed',
        'is_dnssec_enabled' => 'DNSSEC',
        'dnssec' => 'DNSSEC state',
        'autorenew' => 'Auto renew at the registrar',
        'can_renew' => 'Can renew',
        'is_premium' => 'Premium',
        'is_abusive' => 'Flagged abusive',
        'trade_allowed' => 'Owner change (trade) allowed',
        'modify_owner_allowed' => 'Owner details editable',
        'transfer_cancel_supported' => 'Transfer cancel supported',
        'is_hosted_whois' => 'Hosted WHOIS',
        'owner_company_name' => 'Registrant company',
        'glue' => 'Glue records',
        'handles' => 'Handles at the registrar',
    ];

    /** A stage's last day, with the days left while the domain is in it. */
    private static function stageEnd(?Carbon $ends, bool $current): ?string
    {
        if ($ends === null) {
            return null;
        }
        $date = $ends->translatedFormat('j M Y');
        $left = (int) Carbon::today()->diffInDays($ends, false);

        return $current ? __(':date (:days days left)', ['date' => $date, 'days' => max(0, $left)]) : $date;
    }

    public static function infolist(Schema $schema): Schema
    {
        $yesNo = fn (?bool $state) => $state === null ? '—' : ($state ? __('Yes') : __('No'));

        $domain = Section::make(__('domains.domain'))->columns(['default' => 1, 'sm' => 2, '2xl' => 3])->schema([
            TextEntry::make('fqdn')->label(__('domains.domain')),
            TextEntry::make('status')->label(__('Status'))->badge(),
            TextEntry::make('client.name')->label(__('Client')),
            TextEntry::make('registrar')->label(__('Registrar'))
                ->state(fn (Domain $record) => $record->effectiveRegistrarConnection()->label ?? '—'),
            TextEntry::make('registered_at')->label(__('Registered'))->date()->placeholder('—'),
            TextEntry::make('expires_at')->label(__('Expires'))->date()->placeholder('—'),
            // Where it stands after expiry (D5a): each stage's last day, the registrar's or from the TLD's days, and the
            // days left while it is in it.
            TextEntry::make('grace_ends')->label(__('Grace until'))->placeholder('—')
                ->state(fn (Domain $record) => self::stageEnd($record->graceEndsOn(), $record->status === DomainStatus::Grace)),
            TextEntry::make('redemption_ends')->label(__('Redemption until'))->placeholder('—')
                ->state(fn (Domain $record) => self::stageEnd($record->redemptionEndsOn(), $record->status === DomainStatus::Redemption)),
            TextEntry::make('years')->label(__('Period (years)')),
            TextEntry::make('recurring_amount')->label(__('Recurring amount'))->placeholder(__('The TLD\'s renew price')),
            TextEntry::make('auto_renew')->label(__('Auto renew'))->formatStateUsing(fn (bool $state) => $yesNo($state)),
            TextEntry::make('transfer_lock')->label(__('Transfer lock'))->formatStateUsing(fn (bool $state) => $yesNo($state)),
            TextEntry::make('id_protection')->label(__('WHOIS privacy'))->formatStateUsing(fn (bool $state) => $yesNo($state)),
            TextEntry::make('registrar_domain_id')->label(__('ID at the registrar'))->placeholder('—'),
            TextEntry::make('last_synced_at')->label(__('Last synced'))->dateTime()->placeholder('—'),
            TextEntry::make('sync_error')->label(__('Sync error'))->placeholder('—')->columnSpan(2),
        ]);

        // Each role: its contact here, its handle (linked, or only at the registrar), and what staff do with it (D4c).
        $contacts = Section::make(__('Contacts'))->columns(2)->schema(array_map(
            fn (string $role) => Group::make([
                TextEntry::make("{$role}Contact.email")->label(__('domains.role.' . $role))
                    ->state(fn (Domain $record) => $record->{"{$role}Contact"}?->displayName() ?? '—')
                    ->helperText(function (Domain $record) use ($role) {
                        $service = app(DomainManagementService::class);
                        $handle = $service->handleFor($record, $role);

                        return $handle === null ? null : ($service->isUnlinked($record, $role)
                            ? __('At the registrar: :handle (not linked here)', ['handle' => $handle])
                            : __('Handle: :handle', ['handle' => $handle]));
                    }),
                Actions::make(DomainContactActions::for($role))->key("contacts_{$role}_actions")->alignStart(),
            ]),
            Domain::ROLES,
        ));

        // All the registrar told the last sync (D4b): what staff would otherwise look up in its panel.
        $registrar = Section::make(__('At the registrar'))->columns(['default' => 1, 'sm' => 2, '2xl' => 3])->collapsible()
            ->visible(fn (Domain $record) => !empty($record->meta['registrar'] ?? null))
            ->schema(array_map(
                fn (string $key, string $label) => TextEntry::make("registrar_{$key}")->label(__($label))
                    ->state(function (Domain $record) use ($key, $yesNo) {
                        $value = $record->meta['registrar'][$key] ?? null;

                        return is_bool($value) ? $yesNo($value) : ($value === null || $value === '' ? '—' : (string) $value);
                    }),
                array_keys(self::REGISTRAR_DETAILS),
                self::REGISTRAR_DETAILS,
            ));

        // Two columns that each flow on their own: no gap beside a short section (D4c).
        return $schema->columns(1)->components([
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Group::make([
                    $domain,
                    $contacts,
                    Section::make(__('Admin notes'))->schema([
                        TextEntry::make('notes')->hiddenLabel()->placeholder('—')->prose(),
                    ]),
                ]),
                Group::make([
                    Section::make(__('Nameservers'))->schema([
                        TextEntry::make('nameservers.host')->hiddenLabel()->listWithLineBreaks()->placeholder('—'),
                    ]),
                    $registrar,
                ]),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [RegistrarLogsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomains::route('/'),
            'view' => ViewDomain::route('/{record}'),
        ];
    }
}
