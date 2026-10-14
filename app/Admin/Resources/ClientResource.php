<?php

namespace App\Admin\Resources;

use App\Admin\Resources\ClientResource\Pages\CreateClient;
use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\ClientResource\Pages\ListClients;
use App\Admin\Resources\ClientResource\Pages\ShowBillingAgreements;
use App\Admin\Resources\ClientResource\Pages\ShowCreditHistory;
use App\Admin\Resources\ClientResource\Pages\ShowCredits;
use App\Admin\Resources\ClientResource\Pages\ShowInvoices;
use App\Admin\Resources\ClientResource\Pages\ShowServices;
use App\Admin\Resources\ClientResource\Pages\ShowTickets;
use App\Admin\Resources\ClientResource\RelationManagers\ContactsRelationManager;
use App\Admin\Resources\ClientResource\RelationManagers\InvitesRelationManager;
use App\Admin\Resources\ClientResource\RelationManagers\UsersRelationManager;
use App\Admin\Resources\Common\RelationManagers\PropertiesRelationManager;
use App\Admin\Schemas\ContactForm;
use App\Models\Client;
use App\Models\Credit;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
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

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-building-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-building-fill';

    protected static ?int $navigationSort = -1;

    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'primaryContact.first_name', 'primaryContact.last_name', 'primaryContact.company_name', 'primaryContact.email'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return '#' . $record->getKey() . ' ' . ($record instanceof Client ? $record->name : '');
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
                    ->schema(ContactForm::fields())
                    ->columns(2)
                    ->visibleOn('create'),
                Section::make(__('Details'))
                    ->relationship('primaryContact')
                    ->schema(ContactForm::fields())
                    ->columns(2)
                    ->visibleOn('edit'),
                // The customer's choice, also set by staff (MT-1e): credit pays open invoices on its own, or is kept.
                Toggle::make('credit_auto_apply')
                    ->label(__('Credit pays open invoices automatically'))
                    ->helperText(__('Off: the credit is kept until the customer or staff use it.'))
                    ->default(true),
                // WHMCS «Tax Exempt» (Δ1): its new invoice lines get no tax.
                Toggle::make('tax_exempt')
                    ->label(__('Tax exempt'))
                    ->helperText(__('New invoice lines of this account get no tax; issued invoices keep theirs.'))
                    ->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['primaryContact', 'ownerGrant.user', 'credits']))
            ->columns([
                TextColumn::make('id')->label('ID')->sortable()->searchable(),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('primaryContact', fn (Builder $query) => $query
                        ->where('first_name', 'like', "%$search%")->orWhere('last_name', 'like', "%$search%")->orWhere('company_name', 'like', "%$search%")))
                    ->description(function (Client $client) {
                        if (count($client->credits) <= 0) {
                            return null;
                        }

                        return 'Earnings - ' . implode(', ', $client->credits->map(fn (Credit $credit) => "$credit->currency_code: $credit->amount")->toArray());
                    }),
                TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('primaryContact', fn (Builder $query) => $query->where('email', 'like', "%$search%"))),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Filter::make('has_active_services')
                    ->label('Has Active Services')
                    ->query(fn ($query) => $query->whereHas('services', fn ($q) => $q->where('status', 'active'))),
            ])
            ->recordActions([
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
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'edit' => EditClient::route('/{record}/edit'),
            'services' => ShowServices::route('/{record}/services'),
            'invoices' => ShowInvoices::route('/{record}/invoices'),
            'credits' => ShowCredits::route('/{record}/credits'),
            'credit-history' => ShowCreditHistory::route('/{record}/credit-history'),
            'tickets' => ShowTickets::route('/{record}/tickets'),
            'billing-agreements' => ShowBillingAgreements::route('/{record}/billing-agreements'),
        ];
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            EditClient::class,
            ShowServices::class,
            ShowInvoices::class,
            ShowCredits::class,
            ShowCreditHistory::class,
            ShowTickets::class,
            ShowBillingAgreements::class,
        ]);
    }
}
