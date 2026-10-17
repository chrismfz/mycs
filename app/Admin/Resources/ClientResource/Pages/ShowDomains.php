<?php

namespace App\Admin\Resources\ClientResource\Pages;

use App\Admin\Actions\InvoiceSelectedItemsAction;
use App\Admin\Resources\ClientResource;
use App\Admin\Resources\DomainResource;
use App\Models\Domain;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The client's domains (docs/domains.md, D1), beside its Products/Services, as WHMCS's «Domains» tab. */
class ShowDomains extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'domains';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-global-line';

    public static function getNavigationLabel(): string
    {
        return __('domains.domains');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('fqdn')
            ->defaultSort('expires_at')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('fqdn')->label(__('domains.domain'))->searchable()->sortable(),
                TextColumn::make('registered_at')->label(__('Registered'))->date()->placeholder('—'),
                TextColumn::make('expires_at')->label(__('Expires'))->date()->sortable()->placeholder('—'),
                IconColumn::make('auto_renew')->label(__('Auto renew'))->boolean(),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->recordActions([
                ViewAction::make()->url(fn (Domain $record) => DomainResource::getUrl('view', ['record' => $record])),
            ])
            // D7a: the ticked rows on one invoice per currency.
            ->toolbarActions([InvoiceSelectedItemsAction::bulk()]);
    }
}
