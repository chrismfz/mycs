<?php

namespace App\Admin\Resources;

use App\Admin\RelationManagers\EmailLogsRelationManager;
use App\Admin\Resources\ClientResource\Pages\ClientSummaryPage;
use App\Admin\Resources\ClientResource\Pages\CreateClient;
use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\ClientResource\Pages\ListClients;
use App\Admin\Resources\ClientResource\Pages\ShowBillingAgreements;
use App\Admin\Resources\ClientResource\Pages\ShowCreditHistory;
use App\Admin\Resources\ClientResource\Pages\ShowCredits;
use App\Admin\Resources\ClientResource\Pages\ShowDomains;
use App\Admin\Resources\ClientResource\Pages\ShowInvoices;
use App\Admin\Resources\ClientResource\Pages\ShowNotes;
use App\Admin\Resources\ClientResource\Pages\ShowServices;
use App\Admin\Resources\ClientResource\Pages\ShowTickets;
use App\Admin\Resources\ClientResource\RelationManagers\ContactsRelationManager;
use App\Admin\Resources\ClientResource\RelationManagers\InvitesRelationManager;
use App\Admin\Resources\ClientResource\RelationManagers\UsersRelationManager;
use App\Admin\Resources\Common\RelationManagers\PropertiesRelationManager;
use App\Admin\Schemas\ContactForm;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Credit;
use App\Services\Admin\ClientSummary;
use App\Support\Locales;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The customer accounts (docs/accounts.md, PR 2a), WHMCS «Clients»: what the account holds (services, invoices,
 * credit, tickets) and its details. The logins that see it are on the Users page.
 */
class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    /** Its name in «Recently viewed» (the sidebar shows its kind beside it). */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (!$record instanceof Client) {
            return parent::getRecordTitle($record);
        }

        return (string) $record->name;
    }

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-building-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-building-fill';

    protected static ?int $navigationSort = -1;

    // The client's pages as WHMCS's tabs (Summary, Edit, Services, …), above the page.
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'primaryContact.first_name', 'primaryContact.last_name', 'primaryContact.company_name', 'primaryContact.email'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return '#' . $record->getKey() . ' ' . ($record instanceof Client ? $record->name : '');
    }

    /** With its contact, not one query each. */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['primaryContact']);
    }

    /** @return array<string, string> its company and email, on one line (owner 2026-10-23, as WHMCS) */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        $contact = $record instanceof Client ? $record->primaryContact : null;

        return $contact === null ? [] : array_filter([
            __('Company') => filled($contact->company_name) && $contact->company_name !== $record->name ? (string) $contact->company_name : null,
            __('Email') => filled($contact->email) ? (string) $contact->email : null,
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // The owner of a new account (WHMCS «Add New Client» makes the client and its user together).
                Section::make(__('Owner'))
                    ->schema([
                        TextInput::make('first_name')->translateLabel()->required(),
                        TextInput::make('last_name')->translateLabel()->required(),
                        TextInput::make('email')->translateLabel()->email()->required()->unique('users', 'email'),
                        TextInput::make('password')->translateLabel()->password()->revealable()->required(),
                    ])
                    ->columns(2)
                    ->visibleOn('create'),
                // The account's details: its primary contact (docs/accounts.md, PR 3a; WHMCS keeps them in tblclients).
                Section::make(__('Details'))
                    ->statePath('contact')
                    ->schema(ContactForm::fields(newPrimary: true))
                    ->columns(2)
                    ->visibleOn('create'),
                Section::make(__('Details'))
                    ->relationship('primaryContact')
                    ->schema(ContactForm::fields())
                    ->columns(2)
                    ->visibleOn('edit'),
                // The account's billing choices, under its details (they sat beside them as grid columns).
                Section::make(__('Billing'))
                    ->schema([
                        // WHMCS «Language» (docs/accounts.md): its mails and PDFs.
                        Select::make('language')
                            ->label(__('Language'))
                            ->options(fn () => Locales::options())
                            ->formatStateUsing(fn (?string $state) => Locales::allowed($state))
                            ->placeholder(fn () => __('Default') . ' (' . Locales::main() . ')')
                            ->helperText(__('The language of its emails and PDFs.')),
                        // The customer's choice, also set by staff (MT-1e): credit pays open invoices on its own, or is kept.
                        Toggle::make('credit_auto_apply')
                            ->label(__('Credit pays open invoices automatically'))
                            ->helperText(__('Off: the credit is kept until the customer or staff use it.'))
                            ->default(true),
                        // WHMCS «Client Group» (ROADMAP 5): its discount goes into its services' computed prices.
                        // A group is a discount: staff who may manage groups choose it (review F5 of #272).
                        Select::make('client_group_id')
                            ->label(__('Client group'))
                            ->relationship('group', 'name')
                            // Each on its colour, and what the chosen one gives (owner 2026-11-01).
                            ->getOptionLabelFromRecordUsing(fn (ClientGroup $group) => (string) $group->badge())
                            ->allowHtml()
                            ->live()
                            ->placeholder(__('No group'))
                            ->disabled(fn () => !(auth()->user()?->hasPermission('admin.client_groups.update') ?? false))
                            ->dehydrated(fn () => auth()->user()?->hasPermission('admin.client_groups.update') ?? false)
                            ->helperText(function (Get $get) {
                                $perks = ClientGroup::query()->find($get('client_group_id'))?->perks() ?? [];

                                return ($perks === [] ? '' : implode(' · ', $perks) . '. ')
                                    . __('Its discount applies to the services\' prices from their next renewal, except a price kept by hand.');
                            }),
                        // WHMCS «Tax Exempt» (Δ1): its new invoice lines get no tax.
                        Toggle::make('tax_exempt')
                            ->label(__('Tax exempt'))
                            ->helperText(__('New invoice lines of this account get no tax; issued invoices keep theirs.'))
                            ->default(false),
                        // WHMCS «Separate Invoices» (Δ3, PR C): its renewals are not grouped by due date.
                        Toggle::make('separate_invoices')
                            ->label(__('Separate invoices'))
                            ->helperText(__('One invoice per service renewal, instead of one per due date.'))
                            ->default(false),
                        // WHMCS «Exempt from auto suspend/terminate» and «Disable overdue notices» (sweep of myip, 2026-10-25).
                        Toggle::make('dunning_exempt')
                            ->label(__('No automatic suspension or termination'))
                            ->helperText(__('The cron never suspends or terminates this account\'s services for non-payment; staff still can. Also set on its group. Turned off, its overdue services are suspended on the next run, and terminated soon after if long overdue.'))
                            ->default(false),
                        Toggle::make('no_overdue_reminders')
                            ->label(__('No overdue reminders'))
                            ->helperText(__('No reminder after the due date (the one before it is still sent). Also set on its group.'))
                            ->default(false),
                        // Decision 6 (PR C2): the account's saved method pays its renewals, another gateway's method those of its items (#568; WHMCS «Disable automatic card charging»).
                        Toggle::make('auto_charge')
                            ->label(__('Charge renewals automatically'))
                            ->helperText(__('Renewal invoices are charged to the saved payment method below; those of a service or domain set to pay with another gateway, to its saved method of that gateway. Off: none is charged.'))
                            ->default(true),
                        Select::make('default_billing_agreement_id')
                            ->label(__('Payment method for renewals'))
                            ->relationship('defaultBillingAgreement', 'name', fn (Builder $query, ?Client $record) => $query->where('client_id', $record === null ? 0 : $record->id))
                            ->visibleOn('edit')
                            ->placeholder(__('None')),
                    ])
                    ->columns(2),
                // Ekdosi's two choices of the account (owner 2026-10-24): staff only, kept now for the bridge to come.
                Section::make(__('Fiscal invoicing (Ekdosi)'))
                    ->description(__('What Ekdosi reads through the bridge: «Ignore» keeps this account\'s invoices out of its inbox (Send to Ekdosi on an invoice sends one all the same); «Immediate» lets Ekdosi issue them unattended.'))
                    ->schema([
                        Toggle::make('ignore_invoicing')
                            ->label(__('Ignore invoicing'))
                            ->helperText(__('As Ekdosi\'s «Ignore invoicing» for this customer.'))
                            ->default(false),
                        Toggle::make('immediate_invoicing')
                            ->label(__('Immediate invoicing'))
                            ->helperText(__('As Ekdosi\'s «Immediate invoicing» for this customer.'))
                            ->default(false),
                    ])
                    ->columns(2),
            ])
            ->columns(1);
    }

    /**
     * As WHMCS's «View/Search Clients»: name, company, email, its services (active and all), the date it was created.
     * The name searches the primary contact's names and company; the credit shows under the name.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['primaryContact', 'ownerGrant.user', 'credits', 'group'])
                ->withCount(['services', 'services as active_services_count' => fn (Builder $query) => $query->where('status', 'active')]))
            ->columns([
                // On its group's colour, as WHMCS marks a group's clients (owner 2026-11-01).
                TextColumn::make('id')->label('ID')->sortable()->searchable()
                    ->formatStateUsing(fn (Client $record, $state) => $record->group?->safeColour() !== null ? $record->group->badge((string) $state) : (string) $state)
                    ->html(),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('primaryContact', fn (Builder $query) => $query
                        ->where('first_name', 'like', "%$search%")->orWhere('last_name', 'like', "%$search%")->orWhere('company_name', 'like', "%$search%")))
                    // Its credit, as the Summary shows it: only to staff who may see credits (review #213).
                    ->description(function (Client $client) {
                        if (!auth()->user()?->hasPermission('admin.credits.viewAny')) {
                            return null;
                        }
                        $credits = $client->credits->filter(fn (Credit $credit) => (float) $credit->amount != 0);
                        if ($credits->isEmpty()) {
                            return null;
                        }

                        return __('Credit') . ': ' . $credits->map(fn (Credit $credit) => ClientSummary::money((int) round((float) $credit->amount * 100), $credit->currency_code))->implode(', ');
                    }),
                TextColumn::make('primaryContact.company_name')->label(__('Company'))->placeholder('—'),
                TextColumn::make('group.name')->label(__('Client group'))->placeholder('—')->toggleable()
                    ->formatStateUsing(fn (Client $record) => $record->group?->badge() ?? '—')->html()
                    ->tooltip(fn (Client $record) => $record->group === null ? null : (implode(' · ', $record->group->perks()) ?: null)),
                TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('primaryContact', fn (Builder $query) => $query->where('email', 'like', "%$search%"))),
                // WHMCS «Services»: active (all).
                TextColumn::make('active_services_count')
                    ->label(__('Services'))
                    ->formatStateUsing(fn (Client $record) => $record->getAttribute('active_services_count') . ' (' . $record->getAttribute('services_count') . ')')
                    ->sortable(),
                TextColumn::make('created_at')->label(__('Date Created'))->date()->sortable(),
            ])
            ->filters([
                // Accounts holding unused credit (the dashboard's «Clients with credit»): to staff who may see credits.
                TernaryFilter::make('with_credit')
                    ->label(__('Account credit'))
                    ->visible(fn () => (bool) auth()->user()?->hasPermission('admin.credits.viewAny'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('credits', fn (Builder $query) => $query->where('amount', '>', 0)),
                        false: fn (Builder $query) => $query->whereDoesntHave('credits', fn (Builder $query) => $query->where('amount', '>', 0)),
                    ),
                // WHMCS's «Client Group» search (owner 2026-11-01).
                SelectFilter::make('client_group_id')->label(__('Client group'))
                    ->options(fn () => ClientGroup::query()->orderBy('name')->pluck('name', 'id')->all() + ['none' => __('No group')])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'none' => $query->whereNull('client_group_id'),
                        default => $query->where('client_group_id', (int) $data['value']),
                    }),
                TernaryFilter::make('active_services')
                    ->label(__('Active services'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('services', fn (Builder $query) => $query->where('status', 'active')),
                        false: fn (Builder $query) => $query->whereDoesntHave('services', fn (Builder $query) => $query->where('status', 'active')),
                    ),
            ])
            ->recordUrl(fn (Client $record) => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make()->label(__('Summary')),
                EditAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            UsersRelationManager::class,
            InvitesRelationManager::class,
            ContactsRelationManager::class,
            PropertiesRelationManager::class,
            EmailLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ClientSummaryPage::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
            'services' => ShowServices::route('/{record}/services'),
            'domains' => ShowDomains::route('/{record}/domains'),
            'invoices' => ShowInvoices::route('/{record}/invoices'),
            'credits' => ShowCredits::route('/{record}/credits'),
            'credit-history' => ShowCreditHistory::route('/{record}/credit-history'),
            'tickets' => ShowTickets::route('/{record}/tickets'),
            'billing-agreements' => ShowBillingAgreements::route('/{record}/billing-agreements'),
            'notes' => ShowNotes::route('/{record}/notes'),
        ];
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            ClientSummaryPage::class,
            EditClient::class,
            ShowServices::class,
            ShowDomains::class,
            ShowInvoices::class,
            ShowCredits::class,
            ShowCreditHistory::class,
            ShowTickets::class,
            ShowBillingAgreements::class,
            ShowNotes::class,
        ]);
    }
}
