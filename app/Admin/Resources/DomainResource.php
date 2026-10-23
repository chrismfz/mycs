<?php

namespace App\Admin\Resources;

use App\Admin\Components\ClientComponent;
use App\Admin\Resources\DomainResource\DomainContactActions;
use App\Admin\Resources\DomainResource\Pages\ListDomains;
use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Admin\Resources\DomainResource\RelationManagers\RegistrarLogsRelationManager;
use App\Admin\Search\SearchTitle;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Domain;
use App\Services\Domains\DomainManagementService;
use App\Support\AdminBadge;
use App\Support\Domains\DnssecRecord;
use App\Support\Domains\RegistryStatuses;
use Closure;
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
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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

    /** With its client, not one query each. */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['client.primaryContact']);
    }

    /** «cdnet.gr ACTIVE», and two of one name told apart by it (owner 2026-10-23, as WHMCS). */
    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return $record instanceof Domain
            ? SearchTitle::make($record->fqdn, $record->status->getLabel(), $record->status->getColor())
            : (string) $record->getKey();
    }

    /** @return array<string, string> its client and when it expires, on one line */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        if (!$record instanceof Domain) {
            return [];
        }

        // Labelled, as every resource's: Filament labels all a dropdown's details or none, by the first (review of #380).
        return array_filter([
            __('Client') => $record->client->name . ' #' . $record->client_id,
            __('Expires') => $record->expires_at?->format('d/m/Y'),
        ]);
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
                Filter::make('not_ours')->label(__('Not ours at its registrar'))
                    ->query(fn ($query) => $query->whereNotNull('meta->not_ours')),
                Filter::make('expiring')->label(__('Expiring in 60 days'))
                    ->query(fn (Builder $query) => $query->whereBetween('expires_at', [today(), today()->addDays(60)])),
                SelectFilter::make('client')->label(__('Client'))->attribute('client_id')->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => ClientComponent::searchResults($search))
                    ->getOptionLabelUsing(fn ($value) => ClientComponent::labelOf($value)),
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

    /**
     * CentralNic Reseller's record (its StatusDomain, as the sync keeps it, lower case): shown under its own labels, not
     * Openprovider's (owner 2026-10-23: only its status showed).
     */
    private const CNIC_DETAILS = [
        'status' => 'Status at the registrar',
        'rgp_status' => 'RGP status',
        'paiduntil_date' => 'Paid until',
        'registration_expiration_date' => 'Expires at the registry',
        'renewal_date' => 'Renewal date',
        'renewalmode' => 'Renewal mode',
        'transfermode' => 'Transfer mode',
        'x-transferlock-until' => 'Transfer lock until',
        'x-registry-lock' => 'Registry lock',
        'x-time-to-suspension' => 'Time to suspension',
        'x-whoisprivacy' => 'WHOIS privacy',
        'zone' => 'Zone',
        'created_date' => 'Created',
        'updated_date' => 'Last changed',
        'is_lockable' => 'Lockable',
        'handles' => 'Handles at the registrar',
    ];

    /** The .gr registry's record fields shown (G8-3), in this order: what staff look up in grweb today. */
    private const REGISTRY_DETAILS = [
        'sponsor' => 'Sponsoring registrar',
        'transferred' => 'Last transfer',
        'protocol' => 'Protocol',
        'roid' => 'ROID',
        'bundle' => 'Forms (.gr / .ελ)',
        'punycode' => 'Punycode',
        'ds_check' => 'DNSSEC (DS) check',
        'updated' => 'Last changed at the registry',
        'registry' => 'The registry says',
        'transfer' => 'Transfer',
        'contacts' => 'Contacts at the registry',
        'status_reasons' => 'The registry\'s reasons',
        'comments' => 'The registry\'s comments',
        'handles' => 'Handles at the registry',
    ];

    /**
     * A .gr registry's record (G8-3): by its connection (review 1 of #208: one just registered keeps only its protocol,
     * a dead one only the registry's words), else by what the sync kept (roid and statuses: no other registrar's).
     */
    public static function fromGrRegistry(Domain $record): bool
    {
        $registrar = $record->effectiveRegistrarConnection()?->registrar;

        return $registrar !== null ? $registrar === 'grepp' && !empty($record->meta['registrar'] ?? null)
            : isset($record->meta['registrar']['roid'], $record->meta['registrar']['statuses']);
    }

    /** A kept value as text: an array (an older or odd record) never a 500 (review 4). */
    private static function detail(Domain $record, string $key): string
    {
        $value = $record->meta['registrar'][$key] ?? null;

        return is_scalar($value) ? (string) $value : ($value === null ? '' : (string) json_encode($value, JSON_UNESCAPED_UNICODE));
    }

    /**
     * The registry's statuses (EPP or the EETT's, «a, b»), each with what it means (grepp.md §6); one unknown, as given.
     *
     * @return list<string>
     */
    public static function registryStatuses(?string $statuses): array
    {
        return RegistryStatuses::words($statuses);
    }

    /** A stage's last day, with the days left while the domain is in it. */
    private static function stageEnd(?Carbon $ends, bool $current): ?string
    {
        if ($ends === null) {
            return null;
        }
        $date = $ends->translatedFormat('j M Y');
        // Its last day counted (review 12): ending on the 20th, on the 17th that is 4 days.
        $left = max(0, (int) Carbon::parse(Carbon::now('Europe/Amsterdam')->toDateString())->diffInDays($ends, false) + 1);

        return $current ? trans_choice(':date (:days day left)|:date (:days days left)', $left, ['date' => $date, 'days' => $left]) : $date;
    }

    public static function infolist(Schema $schema): Schema
    {
        $yesNo = fn (?bool $state) => $state === null ? '—' : ($state ? __('Yes') : __('No'));

        $domain = Section::make(__('domains.domain'))->columns(['default' => 1, 'xl' => 2])->schema([
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

        // All the registrar told the last sync (D4b): what staff would otherwise look up in its panel, under each registrar's
        // own labels (owner 2026-10-23: CentralNic's showed only its status), open, not folded.
        $isCnic = fn (Domain $record) => $record->effectiveRegistrarConnection()?->registrar === 'cnic';
        $kept = fn (string $prefix, array $labels, Closure $when) => array_map(
            fn (string $key, string $label) => TextEntry::make("{$prefix}_{$key}")->label(__($label))
                ->visible($when)
                ->state(function (Domain $record) use ($key, $yesNo) {
                    $value = $record->meta['registrar'][$key] ?? null;

                    // CentralNic's flags are «0»/«1» (ADV-6 of #401).
                    $value = in_array($key, ['x-registry-lock', 'x-whoisprivacy'], true) && in_array($value, ['0', '1'], true) ? $value === '1' : $value;

                    return is_bool($value) ? $yesNo($value) : ($value === null || $value === '' ? '—' : (string) $value);
                }),
            array_keys($labels),
            $labels,
        );
        $registrar = Section::make(__('At the registrar'))->columns(['default' => 1, 'sm' => 2])
            ->visible(fn (Domain $record) => !empty($record->meta['registrar'] ?? null) && !self::fromGrRegistry($record))
            ->schema([
                ...$kept('registrar', self::REGISTRAR_DETAILS, fn (Domain $record) => !$isCnic($record)),
                ...$kept('cnic', self::CNIC_DETAILS, $isCnic),
            ]);

        // The .gr registry's record (G8-3): its statuses, the EETT's above them, in words; its protocol, forms and DS check.
        $registry = Section::make(__('At the .gr registry'))
            ->visible(fn (Domain $record) => self::fromGrRegistry($record))
            ->schema([
                TextEntry::make('registry_statuses')->label(__('Status at the registry'))->listWithLineBreaks()
                    ->visible(fn (Domain $record) => self::detail($record, 'statuses') !== '')
                    ->state(fn (Domain $record) => self::registryStatuses(self::detail($record, 'statuses'))),
                TextEntry::make('registry_regulator')->label(__('EETT statuses'))->listWithLineBreaks()->color('danger')
                    ->visible(fn (Domain $record) => self::detail($record, 'regulator_statuses') !== '')
                    ->state(fn (Domain $record) => self::registryStatuses(self::detail($record, 'regulator_statuses'))),
                Grid::make(['default' => 1, 'sm' => 2])->schema(array_map(
                    fn (string $key, string $label) => TextEntry::make("registry_{$key}")->label(__($label))
                        ->visible(fn (Domain $record) => self::detail($record, $key) !== '')
                        ->state(fn (Domain $record) => self::detail($record, $key)),
                    array_keys(self::REGISTRY_DETAILS),
                    self::REGISTRY_DETAILS,
                )),
            ]);

        // As the mockup (owner 2026-10-23): the domain and the registrar's side by side, everything open; under them its
        // contacts and child nameservers; its notes last. The registrar's commands are one row above (ViewDomain).
        return $schema->columns(1)->components([
            // The registrar's commands in one row, as WHMCS's «Registrar Commands»: each opens the page's own action.
            Section::make()->compact()->schema([
                Actions::make(fn ($livewire) => method_exists($livewire, 'registrarCommands') ? $livewire->registrarCommands() : [])
                    ->label(__('Registrar'))->key('registrar_commands'),
            ])->visible(fn ($livewire) => method_exists($livewire, 'registrarCommands') && $livewire->registrarCommands() !== []),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Group::make([
                    $domain,
                ]),
                Group::make([
                    Section::make(__('Nameservers'))->schema([
                        TextEntry::make('nameservers.host')->hiddenLabel()->listWithLineBreaks()->placeholder('—'),
                    ]),
                    // Its registrar says it is not ours: for staff to confirm «Transferred away» or fix its connection.
                    Section::make(__('Not ours at its registrar'))
                        ->visible(fn (Domain $record) => is_array($record->meta['not_ours'] ?? null))
                        ->schema([
                            TextEntry::make('not_ours_notes')->hiddenLabel()->listWithLineBreaks()->color('danger')
                                ->state(fn (Domain $record) => collect($record->meta['not_ours'] ?? [])->map(fn ($v, $k) => "{$k}: " . (is_scalar($v) ? var_export($v, true) : json_encode($v)))->values()->all()),
                        ]),
                    // Staff's «Transferred away»: when, by whom, from what, and its open invoices then.
                    Section::make(__('Transferred away'))->collapsible()
                        ->visible(fn (Domain $record) => is_array($record->meta['transferred_away'] ?? null))
                        ->schema([
                            TextEntry::make('transferred_away_notes')->hiddenLabel()->listWithLineBreaks()
                                ->state(fn (Domain $record) => collect($record->meta['transferred_away'] ?? [])->map(fn ($v, $k) => "{$k}: " . (is_scalar($v) ? (string) $v : json_encode($v)))->values()->all()),
                        ]),
                    // What its transfer to us did (its roles replaced, or why not; its owner): kept apart from the sync's.
                    Section::make(__('Transfer to us'))
                        ->visible(fn (Domain $record) => is_array($record->meta['transfer'] ?? null))
                        ->schema([
                            TextEntry::make('transfer_notes')->hiddenLabel()->listWithLineBreaks()
                                ->state(fn (Domain $record) => collect($record->meta['transfer'] ?? [])->map(fn ($v, $k) => "{$k}: " . (is_scalar($v) ? (string) $v : json_encode($v)))->values()->all()),
                        ]),
                    $registrar,
                    $registry,
                    // DNSSEC (G8-4b): its records as last read from the registrar (DS at the .gr registry, DNSKEYs at
                    // Openprovider), and what the registrar said of the last change (the .gr registry's check).
                    Section::make(__('DNSSEC'))
                        ->visible(fn (Domain $record) => is_array($record->meta['dnssec'] ?? null))
                        ->description(fn (Domain $record) => match (true) {
                            (bool) ($record->meta['dnssec']['stale'] ?? false) => __('Out of date (changed after it was read): read again.'),
                            app(DomainManagementService::class)->dnssecRead($record) === null => __('Read from another registrar, or not readable: read again.'),
                            default => __('Read :when', ['when' => (string) ($record->meta['dnssec']['read_at'] ?? '—')]),
                        })
                        ->schema([
                            TextEntry::make('dnssec_records')->hiddenLabel()->placeholder(__('Not signed at the registrar.'))->listWithLineBreaks()->fontFamily('mono')
                                ->state(fn (Domain $record) => collect($record->meta['dnssec']['records'] ?? [])->filter(fn ($r) => is_array($r))
                                    ->map(function (array $r) {
                                        try {
                                            return DnssecRecord::fromArray($r)->label();
                                        } catch (\Throwable) {
                                            return '?';
                                        }
                                    })->values()->all()),
                            TextEntry::make('dnssec_note')->label(__('The registrar said'))
                                ->visible(fn (Domain $record) => is_string($record->meta['dnssec']['note'] ?? null))
                                ->state(fn (Domain $record) => (string) ($record->meta['dnssec']['note'] ?? '')
                                    . (is_string($record->meta['dnssec']['note_at'] ?? null) ? ' (' . $record->meta['dnssec']['note_at'] . ')' : '')),
                        ]),
                ]),
            ]),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                $contacts,
                // As last read from the registrar (its «Read child nameservers»): the forgotten ones too.
                Section::make(__('Child nameservers'))
                    ->visible(fn (Domain $record) => isset($record->meta['child_hosts']) || self::detail($record, 'hosts') !== '')
                    ->description(fn (Domain $record) => match (true) {
                        !isset($record->meta['child_hosts']) => __('Names from the last sync; «Read child nameservers» reads their addresses.'),
                        (bool) ($record->meta['child_hosts']['stale'] ?? false) => __('Out of date (changed after it was read): read again.'),
                        default => __('Read :when', ['when' => (string) ($record->meta['child_hosts']['read_at'] ?? '—')]),
                    })
                    ->schema([
                        TextEntry::make('child_hosts')->hiddenLabel()->placeholder(__('None at the registrar.'))->listWithLineBreaks()->fontFamily('mono')
                            ->state(fn (Domain $record) => !isset($record->meta['child_hosts'])
                                ? array_values(array_filter(array_map('trim', explode(',', self::detail($record, 'hosts')))))
                                : collect($record->meta['child_hosts']['hosts'] ?? [])->filter(fn ($h) => is_array($h))
                                    ->map(fn (array $h) => ($h['name'] ?? '?') . '  ' . implode(', ', (array) ($h['addresses'] ?? []))
                                        . (($h['read'] ?? true) === false ? '  (' . __('not read') . ')' : (($h['linked'] ?? false) ? '  (' . __('in use') . ')' : '')))->values()->all()),
                    ]),
            ]),
            Section::make(__('Admin notes'))->schema([
                TextEntry::make('notes')->hiddenLabel()->placeholder('—')->prose(),
            ]),
        ]);
    }

    /** Domains whose registrar says they are not ours: staff's to confirm (the sync only notes it). */
    public static function getNavigationBadge(): ?string
    {
        return AdminBadge::label('domains.not_ours', fn () => Domain::query()->whereNotNull('meta->not_ours')->whereNotIn('status', DomainStatus::terminalValues())->count());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
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
