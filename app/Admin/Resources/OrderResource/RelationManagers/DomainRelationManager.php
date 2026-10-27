<?php

namespace App\Admin\Resources\OrderResource\RelationManagers;

use App\Admin\Resources\DomainResource;
use App\Models\Domain;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The domains an order registers or transfers (D8b): the operation, years, owner and, for a transfer, the EPP code the
 * customer gave (owner 2026-10-17: staff see it on the order, as in WHMCS, to tell a wrong one), for who may see EPP
 * codes (admin.domains.epp).
 */
class DomainRelationManager extends RelationManager
{
    protected static string $relationship = 'domains';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('fqdn')
            ->columns([
                TextColumn::make('fqdn')->label(__('Domain')),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('years')->label(__('Period')),
                TextColumn::make('registrant')->label(__('Owner'))->state(fn (Domain $record) => $record->registrantContact?->label()),
                TextColumn::make('epp_code')->label(__('EPP code'))->placeholder('—')->fontFamily('mono')->copyable()
                    ->visible(fn () => (bool) auth()->user()?->hasPermission('admin.domains.epp'))
                    ->state(fn (Domain $record) => $record->epp_code),
            ])
            ->recordUrl(fn (Domain $record) => DomainResource::canView($record) ? DomainResource::getUrl('view', ['record' => $record]) : null)
            ->paginated(false);
    }
}
