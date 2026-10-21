<?php

namespace App\Admin\Resources\ClientResource\Pages;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Concerns\NamesTheClient;
use App\Admin\Resources\GatewayResource;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShowBillingAgreements extends ManageRelatedRecords
{
    use NamesTheClient;

    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'billingAgreements';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-bank-card-line';

    public static function getNavigationLabel(): string
    {
        return 'Billing Agreements';
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Name'),
                TextColumn::make('external_reference')
                    ->label('External Reference'),
                TextColumn::make('gateway.name')
                    ->url(fn ($record) => GatewayResource::getUrl('edit', ['record' => $record->gateway_id]))
                    ->label('Gateway'),
            ]);
    }
}
