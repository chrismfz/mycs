<?php

namespace App\Admin\Resources;

use App\Admin\Actions\CancelSubscriptionAction;
use App\Admin\Clusters\Services;
use App\Admin\Components\ClientComponent;
use App\Admin\RelationManagers\EmailLogsRelationManager;
use App\Admin\Resources\Common\RelationManagers\PropertiesRelationManager;
use App\Admin\Resources\ServiceResource\OfflineMachine;
use App\Admin\Resources\ServiceResource\Pages\CreateService;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Admin\Resources\ServiceResource\Pages\ListService;
use App\Admin\Resources\ServiceResource\RelationManagers\ConfigOptionsRelationManager;
use App\Admin\Resources\ServiceResource\RelationManagers\InvoicesRelationManager;
use App\Admin\Resources\ServiceResource\RelationManagers\LicenseAccessLogsRelationManager;
use App\Admin\Resources\ServiceResource\RelationManagers\SslVersionsRelationManager;
use App\Admin\Search\SearchTitle;
use App\Exceptions\DisplayException;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\License;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUsage;
use App\Services\Licensing\Licenses;
use App\Services\Ops\Scrub;
use App\Services\Service\DeleteService;
use App\Support\AdminBadge;
use App\Support\LicenseKeyMask;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    /** Its name in «Recently viewed» (the sidebar shows its kind beside it). */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (!$record instanceof Service) {
            return parent::getRecordTitle($record);
        }

        return trim(($record->product !== null ? $record->product->name : 'Service') . ' · ' . $record->label, ' ·');
    }

    // One name for the page, WHMCS's, in the top menu, the sidebar and its title (owner 2026-10-21).
    protected static ?string $navigationLabel = 'Products/Services';

    protected static ?string $pluralModelLabel = 'Products/Services';

    protected static ?string $modelLabel = 'Product/Service';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-function-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-function-fill';

    public static function getNavigationBadge(): ?string
    {
        return AdminBadge::label('services.pending', fn () => Service::query()->where('status', 'pending')->count());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    protected static ?string $cluster = Services::class;

    /** The admin search (ctrl+k) finds a service by its domain or IP too: «who has IP x» (abuse), as WHMCS. */
    public static function getGloballySearchableAttributes(): array
    {
        // …and by any domain its account hosts (addon, alias, subdomain; a reseller's customers'), live ones: owner 2026-10-22.
        return ['id', 'domain', 'dedicated_ip', 'assigned_ips', 'username', 'liveHostedDomains.domain'];
    }

    /** Its client, server and count of hosted domains with the results, not one query each (code review M2 of #320). */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['client', 'product.server', 'server', 'license'])
            ->withCount(['liveHostedDomains as hosted_main_count' => fn ($q) => $q->where('type', '!=', 'sub')]);
    }

    public static function modifyGlobalSearchQuery(Builder $query, string $search): void
    {
        $term = mb_strtolower(trim($search));
        // The hosted domain the term matches, loaded with the results (its account, for a reseller's customer: L1).
        $query->with(['liveHostedDomains' => fn ($q) => $q->where('domain', 'like', '%' . $term . '%')]);
    }

    /**
     * Each word of the search, on the domain: one that is its license's key is matched by the whole key only, for staff
     * who see keys masked (review of #368); every other attribute as Filament does.
     *
     * @param  array<string>  $searchAttributes
     */
    protected static function applyGlobalSearchAttributeConstraint(Builder $query, string $search, array $searchAttributes, bool &$isFirst): Builder
    {
        if ($searchAttributes === ['domain'] && !LicenseKeyMask::shown()) {
            LicenseKeyMask::whereDomainLike($query, $search, $isFirst ? 'and' : 'or');
            $isFirst = false;

            return $query;
        }

        return parent::applyGlobalSearchAttributeConstraint($query, $search, $searchAttributes, $isFirst);
    }

    /** An IDN typed in Unicode (ελλάδα.gr) found as the server keeps it, in punycode (code review L4). */
    public static function getGlobalSearchResults(string $search): \Illuminate\Support\Collection
    {
        $results = parent::getGlobalSearchResults($search);
        $plain = mb_strtolower(trim($search));
        $ascii = $plain !== '' ? idn_to_ascii($plain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
        if (is_string($ascii) && $ascii !== $plain) {
            $results = collect($results->all())->merge(parent::getGlobalSearchResults($ascii)->all())->unique(fn ($result) => $result->url)->values();
        }

        return $results;
    }

    /** Where a domain found by the search lives: its account and server (owner 2026-10-22: «which account to look in first»). */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        if (!$record instanceof Service) {
            return [];
        }
        $holder = $record->relationLoaded('liveHostedDomains') ? $record->liveHostedDomains->first()?->account : null;

        return array_filter([
            'Client' => $record->client?->name,
            'Account' => $record->username,
            'On account' => $holder !== null && $holder !== strtolower((string) $record->username) ? $holder : null,
            'Server' => $record->provisioningServer()?->name,
            'Hosts' => ($count = (int) ($record->hosted_main_count ?? 0)) > 1 ? "{$count} domains" : null,
        ]);
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return $record instanceof Service
            ? SearchTitle::make('#' . $record->id . ' ' . ($record->product->name ?? '') . ($record->domain ? ' · ' . LicenseKeyMask::domain($record) : ''),
                Service::statusLabel($record->status), Service::statusColor($record->status))
            : '#' . $record->getKey();
    }

    /**
     * A dedicated IPv4 set here that is not the one its server says (both known). cPanel tells the account's IPv4 only:
     * an IPv6 set here is not compared (code review of #389); the addresses compared as addresses, not as text.
     */
    public static function ipDiffers(Service $service): bool
    {
        $mine = (string) $service->dedicated_ip;
        $theirs = (string) $service->usage?->server_ip;
        if (!filter_var($mine, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($theirs, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        return inet_pton($mine) !== inet_pton($theirs);
    }

    public static function form(Schema $schema): Schema
    {
        // As WHMCS's product page, compact (owner 2026-10-23, the mockup): its usage and hosts on one line, then the billing
        // and the account on the server side by side, labels beside the fields, the help behind ⓘ.
        $hint = fn (string $text) => ['heroicon-o-information-circle', $text];

        return $schema
            ->columns(2)
            ->components([
                // Its usage as its server last told it (services:usage, every 4 hours) and the domains its account hosts, one line.
                View::make('admin.resources.service-resource.summary-line')->columnSpanFull()
                    ->visible(fn (?Service $record) => $record !== null && ($record->usage !== null || $record->hostedDomains()->exists()))
                    ->viewData(fn (?Service $record) => $record === null ? [] : self::summaryLine($record)),
                Section::make('Billing')
                    ->columnSpan(1)
                    ->schema([
                        Select::make('product_id')
                            ->label('Product')
                            ->required()
                            ->relationship(
                                name: 'product',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->with('category')
                            )
                            ->getOptionLabelFromRecordUsing(fn (Product $product) => "{$product->name} - {$product->category->name} (#{$product->id})")
                            ->searchable()
                            ->live()
                            ->preload()
                            ->placeholder('Select the product'),
                        Select::make('plan_id')
                            ->label('Plan')
                            ->required()
                            ->relationship('plan', 'name', fn (Builder $query, Get $get) => $query->where('priceable_id', $get('product_id'))->where('priceable_type', Product::class))
                            ->searchable()
                            ->preload()
                            ->disabled(fn (Get $get) => !$get('product_id'))
                            ->live()
                            ->placeholder('Select the plan'),
                        // A paying service moved to a free or one-time plan is never billed again (billing-cursor §5): said so.
                        Toggle::make('ends_billing')->label('Never bill it again')->dehydrated()
                            ->helperText('This service is billed every cycle; on a free or one-time plan it stays without being billed again. Or set its price to 0 in the same save.')
                            ->visible(fn (Get $get, ?Service $record) => $record !== null && filled($get('plan_id')) && (int) $get('plan_id') !== (int) $record->plan_id
                                && Service::endsBillingRefusal($record, Plan::query()->find($get('plan_id'))) !== null),
                        // Chosen when the service is made; afterwards it moves with «Move to another client» (its invoices with it),
                        // never by editing the field (owner 2026-10-23).
                        ClientComponent::make('client_id')->disabledOn('edit'),
                        Select::make('status')
                            ->label('Status')
                            ->required()
                            ->options(Service::statusOptions())
                            ->default(Service::STATUS_PENDING),
                        // Only where it means something (WHMCS: no service with more than 1 at nixpal): the product allows a
                        // quantity, or the service has one. Hidden, it is kept as it is.
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->required()
                            ->default(1)
                            ->placeholder('Enter the quantity')
                            ->hidden(fn (?Service $record) => $record !== null && (int) $record->quantity <= 1
                                && ($record->product->allow_quantity ?? 'disabled') === 'disabled'),
                        DatePicker::make('expires_at')
                            ->label('Expires At')
                            ->required(fn (Get $get) => $get('plan')?->type != 'one-time' && $get('plan')?->type != 'free' && $get('status') !== 'pending')
                            ->placeholder('Select the expiration date'),
                        DatePicker::make('suspend_hold_until')
                            ->label('Do Not Suspend Until')
                            ->hintIcon(...$hint('While set to a future date, the cronjob neither suspends nor terminates this service, even if it is overdue (through that day). This does not affect manual suspension or termination.'))
                            // A new date from today on; one already there, passed (an automatic hold of a day, review of
                            // #411), never stops the page from saving.
                            ->minDate(fn ($state, ?Service $record) => $record?->suspend_hold_until !== null && filled($state)
                                && Carbon::parse($state)->isSameDay($record->suspend_hold_until) ? null : now()->startOfDay())
                            ->nullable()
                            ->placeholder('dd/mm/yyyy'),
                        Select::make('coupon_id')
                            ->label('Coupon')
                            ->relationship('coupon', 'code')
                            ->searchable()
                            ->preload()
                            ->placeholder('Select the coupon'),
                        Select::make('currency_code')
                            ->options(function (Get $get, ?string $state) {
                                $pricing = collect($get('../../pricing'))->pluck('currency_code');
                                if ($state !== null) {
                                    $pricing = $pricing->filter(function ($code) use ($state) {
                                        return $code !== $state;
                                    });
                                }
                                $pricing = $pricing->filter(function ($code) {
                                    return $code !== null;
                                });

                                return Currency::whereNotIn('code', $pricing)->pluck('code', 'code');
                            })
                            ->live()
                            ->default(config('settings.default_currency'))
                            ->required(),
                        TextInput::make('price')
                            ->required()
                            ->label('Price')
                            // Suffix based on chosen currency
                            ->prefix(fn (Get $get) => Currency::where('code', $get('currency_code'))->first()?->prefix)
                            ->suffix(fn (Get $get) => Currency::where('code', $get('currency_code'))->first()?->suffix)
                            ->live(onBlur: true)
                            ->mask(RawJs::make(
                                <<<'JS'
                                $money($input, '.', '', 2)
                            JS
                            ))
                            ->numeric()
                            ->minValue(0)
                            ->hintAction(
                                Action::make('Recalculate Price')
                                    ->action(function (Component $component, Service $service) {
                                        if ($service) {
                                            Notification::make('Price Recalculated')
                                                ->title('The price has been successfully recalculated')
                                                ->success()
                                                ->send();
                                            // Update the form field
                                            $component->state($service->calculatePrice());
                                        }
                                    })
                                    ->label('Recalculate Price')
                                    ->icon('ri-refresh-line')
                            ),
                        // Never follows the account's group discount (ROADMAP 5, reviews of #272): staff's price stays.
                        Toggle::make('price_locked')
                            ->label(__('Keep this price'))
                            ->hintIcon(...$hint(__('A change of the customer\'s group never reprices it.')))
                            ->default(false),
                        // A gateway subscription of the old kind (Billing Agreement replaced it): shown only on a service that has one
                        // (nixpal: 5 PayPal ones).
                        TextInput::make('subscription_id')
                            ->label('Subscription ID (deprecated)')
                            ->hidden(fn (?Service $record) => blank($record?->subscription_id))
                            ->nullable()
                            ->placeholder('Enter the subscription ID')
                            ->hintAction(
                                CancelSubscriptionAction::make(),
                            ),
                    ]),
                // WHMCS's product details (tblhosting): read by the server modules, shown to the customer.
                Section::make('Account on the server')
                    ->columnSpan(1)
                    ->schema([
                        // Which server it lives on (WHMCS's «Server»): the record only, nothing is moved on the servers.
                        // Servers of the product's module only (a cPanel account never «moves» to a Proxmox server by mistake).
                        Select::make('server_id')->label('Server')->nullable()
                            ->relationship('server', 'name', fn (Builder $query, ?Service $record) => $query
                                ->when($record->product->server->extension ?? $record->server->extension ?? null, fn ($servers, $module) => $servers->where('extension', $module)))
                            ->hintIcon(...$hint('The server this account is on (e.g. after moving it with WHM\'s Transfer Tool). Changing it moves nothing: it records where the account is, and every action goes there from now on.')),
                        TextInput::make('domain')->label('Domain')->maxLength(255),
                        TextInput::make('username')->label('Username')->maxLength(255),
                        // Hidden from serialization, so filled from the record here (else a save would wipe it); emptied,
                        // it is cleared (review F3).
                        TextInput::make('password')->label('Password')->password()->revealable()->maxLength(255)
                            ->afterStateHydrated(fn (TextInput $component, ?Service $record) => $component->state(rescue(fn () => $record?->password, null, false)))
                            ->dehydrateStateUsing(fn (?string $state) => filled($state) ? $state : null),
                        TextInput::make('dedicated_ip')->label('Dedicated IP')->ip()->maxLength(45),
                        Textarea::make('assigned_ips')->label('Assigned IPs')->placeholder('One per line')->rows(2),
                        // The contact of the account it concerns (a domain's owner, a site's company): the invoice stays the
                        // account's (docs/accounts.md 3b); Ekdosi issues the fiscal document for its lines to it (ekdosi-bridge.md).
                        Select::make('contact_id')->label('Related contact')->nullable()
                            // The form's account (create page, or a client changed on the form: review 3).
                            ->relationship('contact', 'id', fn (Builder $query, Get $get) => $query
                                ->where('client_id', $get('client_id'))->whereNull('archived_at'))
                            ->getOptionLabelFromRecordUsing(fn (Contact $contact) => $contact->label() . ($contact->email && $contact->label() !== $contact->email ? " <{$contact->email}>" : ''))
                            ->hintIcon(...$hint('Who this service is for, among the account\'s contacts. The invoice stays the account\'s; Ekdosi issues the fiscal document for its lines to this contact.')),
                        TextInput::make('suspend_reason')->label('Suspension Reason')->maxLength(255)
                            ->hintIcon(...$hint('Shown to the customer while the service is suspended.')),
                        // The server's commands in one row, as WHMCS's «Module Commands»: each opens the page's own action
                        // (its checks, its confirmation, the reseller's username for a terminate) at that command.
                        Actions::make(collect(self::MODULE_COMMANDS)->map(fn (string $label, string $command) => Action::make('module_' . $command)
                            ->label($label)->size('sm')->color($command === 'terminate' ? 'danger' : 'gray')->outlined()
                            ->alpineClickHandler("\$wire.mountAction('changeStatus', { action: '{$command}' })"))->values()->all())
                            ->label('Module')
                            ->visible(fn (?Service $record, string $operation) => $operation === 'edit' && $record?->provisioningServer() !== null),
                    ]),
                // A machine with no API (OfflineVm, OfflineDedicated, owner 2026-10-24): what staff keep about it, edited
                // in one window. Its hostname and IPv4 are the account's domain and IPs above.
                Section::make('Machine')
                    ->columns(3)
                    ->columnSpanFull()
                    ->visible(fn (?Service $record, string $operation) => $operation === 'edit' && OfflineMachine::fields($record) !== [])
                    ->schema(fn (?Service $record) => [
                        ...collect(OfflineMachine::fields($record))->map(fn (array $field) => TextEntry::make('machine_' . $field['name'])->label($field['label'])
                            ->state(fn (Service $record) => OfflineMachine::value($record, $field['name']))->placeholder('—')
                            ->fontFamily(in_array($field['type'], ['mac', 'ip'], true) ? 'mono' : null)
                            ->copyable(in_array($field['type'], ['mac', 'ip'], true))
                            ->columnSpan(in_array($field['type'], ['textarea'], true) ? 'full' : 1))->values()->all(),
                        Actions::make([Action::make('machine_edit')->label('Edit machine details')->icon('heroicon-o-pencil-square')->size('sm')->color('gray')
                            ->alpineClickHandler("\$wire.mountAction('editMachine')")
                            ->visible(fn (Service $record) => (bool) auth()->user()?->can('update', $record))]),
                    ]),
                // Its nixpal license (L2, WHMCS's licensing «Manage License»): key, bindings, and signs of life beyond WHMCS.
                Section::make('License')
                    ->columns(3)
                    ->columnSpanFull()
                    ->visible(fn (?Service $record) => $record?->license !== null)
                    ->schema([
                        TextEntry::make('license.license_key')->label('License key')->fontFamily('mono')
                            ->formatStateUsing(fn ($state) => auth()->user()?->hasPermission('admin.services.update') ? $state : Scrub::licenseKey($state)) // SA-11
                            ->copyable(fn (): bool => (bool) auth()->user()?->hasPermission('admin.services.update')),
                        TextEntry::make('license.status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                            License::STATUS_ACTIVE => 'success', License::STATUS_EXPIRED => 'danger', default => 'warning',
                        }),
                        TextEntry::make('license.reissues')->label('Reissues')->state(fn (Service $record) => $record->license?->reissues . ' / ' . Licenses::MAX_REISSUES),
                        TextEntry::make('license.valid_ip')->label('Valid IPs')->placeholder('Not bound yet'),
                        TextEntry::make('license.valid_domain')->label('Valid domains')->placeholder('—'),
                        TextEntry::make('license.valid_directory')->label('Valid directory')->placeholder('—'),
                        TextEntry::make('license.last_ok_at')->label('Last good check')->since()->dateTimeTooltip()->placeholder('Never'),
                        TextEntry::make('license.last_seen_ip')->label('Last seen from')
                            ->state(fn (Service $record) => trim(($record->license->last_seen_ip ?? '') . ' ' . ($record->license->last_seen_client ?? '')) ?: null)->placeholder('—'),
                        TextEntry::make('license.last_failure')->label('Last failure')
                            ->state(fn (Service $record) => $record->license?->last_failure_at
                                ? $record->license->last_failure_at->diffForHumans() . ': ' . $record->license->last_failure : null)->placeholder('None'),
                        TextEntry::make('license_checks')->label('Checks, last 30 days')->columnSpanFull()
                            ->state(fn (Service $record) => self::licenseChecks($record)),
                    ]),
                Textarea::make('notes')->label('Admin Notes')->hintIcon(...$hint('Staff only.'))->rows(2)->columnSpanFull(),
            ]);
    }

    /** WHMCS's «Module Commands», in its order: the page's «Trigger Extension Action» at each. */
    public const MODULE_COMMANDS = ['create' => 'Create', 'suspend' => 'Suspend', 'unsuspend' => 'Unsuspend', 'upgrade' => 'Change package', 'terminate' => 'Terminate'];

    /**
     * Its usage and hosts for the one line above the form: what its server last told (services:usage).
     *
     * @return array<string, mixed>
     */
    public static function summaryLine(Service $record): array
    {
        $usage = $record->usage;
        // A reseller's by account, as before (ADV-2 of #400): «acct: a, b»; one account's, the names alone.
        $byAccount = $record->hostedDomains->groupBy('account');
        $hosted = $byAccount->count() > 1
            ? $byAccount->map(fn ($domains, $account) => "{$account}: " . $domains->map(fn ($d) => $d->domain . ($d->type === 'main' ? '' : " ({$d->type})"))->implode(', '))->values()
            : $record->hostedDomains->map(fn ($d) => $d->domain . ($d->type === 'main' ? '' : " ({$d->type})"))->values();

        return [
            'disk' => $usage === null ? null : self::meter($usage->disk_used_mb, $usage->disk_limit_mb, true),
            'diskPercent' => $usage === null ? null : ServiceUsage::percent($usage->disk_used_mb, $usage->disk_limit_mb),
            'bandwidth' => $usage === null ? null : self::meter($usage->bandwidth_used_mb, $usage->bandwidth_limit_mb, true),
            'accounts' => $usage?->accounts === null ? null : [$usage->accounts_kind === 'mailboxes' ? 'Mailboxes' : 'Accounts', self::meter($usage->accounts, $usage->accounts_limit, false)],
            'ip' => $usage?->server_ip,
            'ipDiffers' => self::ipDiffers($record),
            'dedicatedIp' => $record->dedicated_ip,
            'readAt' => $usage?->read_at?->format('d/m/Y H:i'),
            'hosted' => $hosted->all(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            // The account's name is its owner's (until the contacts, PR 3): loaded with the rows, not per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client.primaryContact', 'client.ownerGrant.user', 'license']))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('client.name')
                    ->label('Client')
                    ->searchable(true, fn (Builder $query, string $search) => ClientComponent::search($query, $search)),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                // «Who has IP x» (abuse): the domain, the dedicated IP and the assigned IPs are searched.
                TextColumn::make('domain')
                    ->label('Domain')
                    ->formatStateUsing(fn (Service $record) => LicenseKeyMask::domain($record))
                    ->description(fn (Service $record) => $record->dedicated_ip)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($match) => LicenseKeyMask::whereDomainLike($match, $search)
                        ->orWhere('dedicated_ip', 'like', "%{$search}%")->orWhere('assigned_ips', 'like', "%{$search}%")))
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (Service $record) => Service::statusColor($record->status))
                    ->formatStateUsing(fn (string $state) => Service::statusLabel($state))
                    ->label('Status')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label('Expires At')
                    ->date()
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Service::statusOptions()),
                SelectFilter::make('client')
                    ->label('Client')
                    ->attribute('client_id')->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => ClientComponent::searchResults($search))
                    ->getOptionLabelUsing(fn ($value) => ClientComponent::labelOf($value))
                    ->indicateUsing(fn (array $data) => filled($data['value'] ?? null) ? __('Client') . ': ' . ClientComponent::labelOf($data['value']) : null),
                SelectFilter::make('product')
                    ->label('Product')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),
                // Its dedicated IP set here is not the one its server says (owner 2026-10-23).
                Filter::make('ip_differs')->label('IP differs from the server')
                    // IPv4 only, as cPanel tells it (an IPv6 set here is never «different»).
                    ->query(fn (Builder $query) => $query->whereNotNull('dedicated_ip')->where('dedicated_ip', '!=', '')->where('dedicated_ip', 'not like', '%:%')
                        ->whereHas('usage', fn (Builder $usage) => $usage->whereNotNull('server_ip')->whereColumn('service_usages.server_ip', '!=', 'services.dedicated_ip'))),
                Filter::make('expires_at')
                    ->form([
                        DatePicker::make('expires_from')->label('Expires From'),
                        DatePicker::make('expires_until')->label('Expires Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['expires_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('expires_at', '>=', $date),
                            )
                            ->when(
                                $data['expires_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('expires_at', '<=', $date),
                            );
                    }),
            ])
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderBy('id', 'desc');
            })
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::deleteBulkAction(),
                ]),
            ]);
    }

    /** «ok / failed» over the last 30 days, from license_check_days. */
    private static function licenseChecks(Service $record): string
    {
        $license = $record->license;
        if ($license === null) {
            return '—';
        }
        $days = DB::table('license_check_days')->where('license_id', $license->id)->where('day', '>=', now()->subDays(30)->toDateString());

        return sprintf('%d ok, %d failed, on %d days', (int) (clone $days)->sum('ok'), (int) (clone $days)->sum('failed'), (clone $days)->count());
    }

    public static function getRelations(): array
    {
        return [
            InvoicesRelationManager::class,
            LicenseAccessLogsRelationManager::class,
            SslVersionsRelationManager::class,
            PropertiesRelationManager::class,
            ConfigOptionsRelationManager::class,
            EmailLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListService::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }

    /**
     * Delete the selected services through DeleteService (DATA-2, SVC-7, AUTH-9): optionally their accounts on the
     * server first; one the server refuses is kept and reported, the others go.
     */
    public static function deleteBulkAction(): BulkAction
    {
        return BulkAction::make('delete')
            ->label('Delete selected')
            ->icon('heroicon-o-trash')
            ->color('danger')
            // Filament authorizes only its own DeleteBulkAction: this one asks the policy itself (deleteAny, then each).
            ->authorize(fn (): bool => (bool) auth()->user()?->can('deleteAny', Service::class))
            ->requiresConfirmation()
            ->schema([
                Checkbox::make('deleteExtensionServer')->label('Also trigger deletion of server (running services)')->default(true),
            ])
            ->action(function (Collection $records, array $data): void {
                $kept = [];
                foreach ($records as $service) {
                    /** @var Service $service */
                    if (!auth()->user()?->can('delete', $service)) {
                        $kept[] = "#{$service->id}: " . __('You do not have access to this area of the account.');

                        continue;
                    }
                    try {
                        (new DeleteService)->handle($service, (bool) ($data['deleteExtensionServer'] ?? false));
                    } catch (Throwable $e) {
                        if (!$e instanceof DisplayException) {
                            report($e);
                        }
                        $kept[] = "#{$service->id}: {$e->getMessage()}";
                    }
                }
                if ($kept !== []) {
                    Notification::make()->danger()->title(__('Not deleted'))->body(implode("\n", $kept))->persistent()->send();
                }
            })
            ->deselectRecordsAfterCompletion();
    }

    /** «793 MB / 20 GB (4%)», «5 / unlimited». */
    private static function meter(?int $used, ?int $limit, bool $size): string
    {
        $show = fn (?int $v) => $size ? ServiceUsage::size($v) : ($v === null ? '—' : (string) $v);
        $percent = ServiceUsage::percent($used, $limit);

        return $show($used) . ' / ' . ($limit === null ? 'unlimited' : $show($limit)) . ($percent !== null ? " ({$percent}%)" : '');
    }
}
