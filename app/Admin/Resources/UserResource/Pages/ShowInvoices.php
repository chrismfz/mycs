<?php

namespace App\Admin\Resources\UserResource\Pages;

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\UserResource;
use App\Models\Invoice;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ShowInvoices extends ManageRelatedRecords
{
    protected static string $resource = UserResource::class;

    protected static string $relationship = 'invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-receipt-line';

    public static function getNavigationLabel(): string
    {
        return 'Invoices';
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('formattedTotal')->label('Total'),
                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (string $state): string => Invoice::statusLabel($state))
                    ->badge()
                    ->color(fn (string $state): string => Invoice::statusColor($state)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Invoice::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make()->url(fn ($record) => InvoiceResource::getUrl(config('settings.immutable_invoices_enabled', false) ? 'view' : 'edit', ['record' => $record])),
            ]);
    }
}
