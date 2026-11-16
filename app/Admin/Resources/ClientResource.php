<?php

namespace App\Admin\Resources;

use App\Admin\RelationManagers\EmailLogsRelationManager;
use App\Admin\Resources\ClientResource\Pages\ClientSummaryPage;
use App\Admin\Resources\ClientResource\Pages\CreateClient;
use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\ClientResource\Pages\ListClients;
use App\Admin\Resources\ClientResource\Pages\ShowBillingAgreements;
use App\Admin\Resources\ClientResource\Pages\ShowChats;
use App\Admin\Resources\ClientResource\Pages\ShowCreditHistory;
use App\Admin\Resources\ClientResource\Pages\ShowCredits;
use App\Admin\Resources\ClientResource\Pages\ShowDomains;
use App\Admin\Resources\ClientResource\Pages\ShowEmails;
use App\Admin\Resources\ClientResource\Pages\ShowInvoices;
use App\Admin\Resources\ClientResource\Pages\ShowNotes;
use App\Admin\Resources\ClientResource\Pages\ShowServices;
use App\Admin\Resources\ClientResource\Pages\ShowTickets;
use App\Admin\Resources\ClientResource\Pages\ShowTransactions;
use App\Admin\Resources\ClientResource\Pages\ViewChat;
use App\Admin\Resources\ClientResource\RelationManagers\ContactsRelationManager;
use App\Admin\Resources\ClientResource\RelationManagers\InvitesRelationManager;
use App\Admin\Resources\ClientResource\RelationManagers\UsersRelationManager;
use App\Admin\Resources\Common\RelationManagers\PropertiesRelationManager;
use App\Admin\Schemas\ContactForm;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Contact;
use App\Models\Credit;
use App\Models\Invoice;
use App\Services\Admin\ClientSummary;
use App\Support\Locales;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
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
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
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
        // …and its ΑΦΜ / VAT number and phone (owner 2026-11-16).
        return ['id', 'primaryContact.first_name', 'primaryContact.last_name', 'primaryContact.company_name', 'primaryContact.email',
            'primaryContact.tax_id', 'primaryContact.vat_number', 'primaryContact.phone'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return '#' . $record->getKey() . ' ' . ($record instanceof Client ? $record->name : '');
    }

    /**
     * The list's search (owner 2026-11-16: «καμιά φορά ψάχνουμε με ΑΦΜ»): the name, company, email and phone of any of
     * the client's contacts (the phone with or without its blanks and dashes), and their ΑΦΜ or VAT number, with or
     * without the country prefix (EL123456789 finds 123456789 and the other way round).
     */
    public static function searchContacts(Builder $query, string $search): Builder
    {
        $search = trim($search);
        $bare = preg_replace('/^[A-Za-z]{2}(?=[\s.\-]*\d)|[\s.\-]/', '', $search);

        return $query->whereHas('contacts', fn (Builder $contacts) => $contacts->where(function (Builder $contacts) use ($search, $bare) {
            // Filament searches word by word («el 099 887 766» is four words, each to be found), so a short number is
            // searched in the phones and ΑΦΜ too: a few more rows for an id, known (review of #873). Archived contacts
            // count: an old ΑΦΜ still finds its client.
            foreach (['first_name', 'last_name', 'company_name', 'email', 'phone', 'tax_id', 'vat_number'] as $column) {
                $contacts->orWhere($column, 'like', "%{$search}%");
            }
            if ($bare !== '' && $bare !== $search && ctype_digit($bare)) {
                $contacts->orWhere('tax_id', 'like', "%{$bare}%")->orWhere('vat_number', 'like', "%{$bare}%");
            }
            // A phone however it was typed: «210 555-0123» finds «+302105550123» and the other way round.
            $digits = preg_replace('/\D/', '', $search);
            if (strlen($digits) >= 6 && preg_match('/^[\d\s+().\-]+$/', $search)) {
                $contacts->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '.', ''), '(', ''), ')', '') like ?", ["%{$digits}%"]);
            }
        }));
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
                                $perks = filled($get('client_group_id')) ? (ClientGroup::query()->find($get('client_group_id'))?->perks() ?? []) : [];

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
                        // WHMCS «Closed» (WG-8): on, its logins cannot sign in (unless another account of theirs is open) and it takes no orders.
                        DateTimePicker::make('closed_at')
                            ->label(__('Closed'))
                            ->helperText(__('Set: its logins cannot sign in (unless another account of theirs is open) and it takes no new orders. End its open sessions under Sessions. Clear it to reopen.')),
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
                ->withCount(['services', 'services as active_services_count' => fn (Builder $query) => $query->where('status', 'active')])
                // Its current logins only, as its Summary counts them (review of #840): a removed login's is not its activity.
                ->withMax(['grants as last_login_at' => fn (Builder $grants) => $grants->whereNull('revoked_at')], 'last_login_at'))
            ->columns([
                // On its group's colour, as WHMCS marks a group's clients (owner 2026-11-01).
                TextColumn::make('id')->label('ID')->sortable()->searchable()
                    ->formatStateUsing(fn (Client $record, $state) => $record->group?->safeColour() !== null ? $record->group->badge((string) $state) : (string) $state)
                    ->html(),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable(query: fn (Builder $query, string $search) => self::searchContacts($query, $search))
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
                // The last time anyone signed in to it (its logins', WHMCS's imported with them): sorted to find the quiet
                // ones (owner 2026-11-13).
                TextColumn::make('last_login_at')->label(__('Last login'))->dateTime()->since()
                    ->dateTimeTooltip()->placeholder(__('Never'))->sortable()->toggleable(),
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
                // Owner 2026-11-16, the useful ones of WHMCS's «Advanced» search. What it owes: an unpaid invoice (an Add
                // Funds one is no debt), an overdue one (the reminders' rule), or nothing unpaid.
                SelectFilter::make('owes')->label(__('Owes'))
                    ->visible(fn () => InvoiceResource::canViewAny())
                    ->options(['unpaid' => __('Unpaid invoices'), 'overdue' => __('Overdue invoices'), 'none' => __('Nothing unpaid')])
                    ->query(function (Builder $query, array $data): Builder {
                        $unpaid = fn ($invoices) => $invoices->where('status', Invoice::STATUS_UNPAID)->whereNot(fn ($addFunds) => Invoice::whereAddFunds($addFunds));

                        return match ($data['value'] ?? null) {
                            'unpaid' => $query->whereHas('invoices', $unpaid),
                            'overdue' => $query->whereHas('invoices', fn ($invoices) => Invoice::whereOverdue($invoices)),
                            'none' => $query->whereDoesntHave('invoices', $unpaid),
                            default => $query,
                        };
                    }),
                // A saved card or PayPal of its own as its default (whether «Auto-charge» is on or the card stopped: the
                // client's page says that).
                TernaryFilter::make('payment_method')->label(__('Saved payment method'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('defaultBillingAgreement', fn (Builder $agreement) => $agreement->whereColumn('billing_agreements.client_id', 'clients.id')),
                        false: fn (Builder $query) => $query->whereDoesntHave('defaultBillingAgreement', fn (Builder $agreement) => $agreement->whereColumn('billing_agreements.client_id', 'clients.id')),
                    ),
                // Its main contact's country: only those the clients have.
                SelectFilter::make('country')->label(__('Country'))
                    ->options(function (): array {
                        $names = (array) config('app.countries');
                        $codes = Contact::query()->whereNotNull('primary_of_client_id')->whereNotNull('country')->where('country', '!=', '')
                            ->distinct()->pluck('country')->all();
                        $options = [];
                        foreach ($codes as $code) {
                            $options[$code] = (string) ($names[$code] ?? $code);
                        }
                        asort($options);

                        return $options;
                    })
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('primaryContact', fn (Builder $contact) => $contact->where('country', $data['value']))
                        : $query),
                // Its special settings, any of those picked: to find an exemption left on, its own or its group's.
                SelectFilter::make('settings')->label(__('Special settings'))->multiple()
                    ->options([
                        'tax_exempt' => __('Tax exempt'),
                        'dunning_exempt' => __('No automatic suspension or termination'),
                        'no_overdue_reminders' => __('No overdue reminders'),
                        'ignore_invoicing' => __('Ignore invoicing'),
                        'separate_invoices' => __('Separate invoices'),
                        'immediate_invoicing' => __('Immediate invoicing'),
                        'no_auto_charge' => __('No automatic charge'),
                    ])
                    ->query(fn (Builder $query, array $data) => ($data['values'] ?? []) === [] ? $query : $query->where(function (Builder $query) use ($data) {
                        foreach ($data['values'] as $setting) {
                            match ($setting) {
                                'no_auto_charge' => $query->orWhere('auto_charge', false),
                                // Its own setting or its group's, as the cron reads them (Client::dunningExempt and the rest).
                                'dunning_exempt', 'no_overdue_reminders', 'separate_invoices' => $query->orWhere($setting, true)
                                    ->orWhereHas('group', fn (Builder $group) => $group->where($setting, true)),
                                'tax_exempt', 'ignore_invoicing', 'immediate_invoicing' => $query->orWhere($setting, true),
                                default => null,
                            };
                        }
                    })),
                // New clients of a period.
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('created_from')->label(__('Signed up from')),
                        DatePicker::make('created_until')->label(__('Signed up until')),
                    ])
                    ->columns(2)->columnSpanFull()
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['created_from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date))),
            ], layout: FiltersLayout::AboveContentCollapsible)
            // On the page, folded, as the tickets' (owner 2026-11-16), not in a popup.
            ->filtersFormColumns(2)
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
            'transactions' => ShowTransactions::route('/{record}/transactions'),
            'credits' => ShowCredits::route('/{record}/credits'),
            'credit-history' => ShowCreditHistory::route('/{record}/credit-history'),
            'tickets' => ShowTickets::route('/{record}/tickets'),
            'chats' => ShowChats::route('/{record}/chats'),
            'chat' => ViewChat::route('/{record}/chats/{chat}'),
            'emails' => ShowEmails::route('/{record}/emails'),
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
            ShowTransactions::class,
            ShowCredits::class,
            ShowCreditHistory::class,
            ShowTickets::class,
            ShowChats::class,
            ShowEmails::class,
            ShowBillingAgreements::class,
            ShowNotes::class,
        ]);
    }
}
