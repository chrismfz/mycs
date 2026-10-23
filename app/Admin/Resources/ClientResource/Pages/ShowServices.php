<?php

namespace App\Admin\Resources\ClientResource\Pages;

use App\Admin\Actions\InvoiceSelectedItemsAction;
use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Concerns\NamesTheClient;
use App\Admin\Resources\ServiceResource;
use App\Models\Service;
use App\Services\Admin\ClientSummary;
use App\Support\LicenseKeyMask;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ShowServices extends ManageRelatedRecords
{
    use NamesTheClient;

    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'services';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    public static function getNavigationLabel(): string
    {
        return __('Products/Services');
    }

    /** As WHMCS's client «Products/Services» tab, and as the Summary lists them (amount with tax, cycle, dates). */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            // The amount's tax reads the client's country (review #213): loaded with the rows, not per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'plan', 'currency', 'client.primaryContact', 'license']))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label(__('Product/Service'))
                    ->description(fn (Service $record) => LicenseKeyMask::domain($record))
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $match) => LicenseKeyMask::whereDomainLike($match, $search)
                        ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', "%{$search}%")))),
                TextColumn::make('quantity')
                    ->label(__('Quantity'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('formattedPrice')->label(__('Amount')),
                TextColumn::make('plan.name')
                    ->label(__('Billing Cycle'))
                    ->formatStateUsing(fn (Service $record) => ClientSummary::cycle($record->plan)),
                TextColumn::make('created_at')->label(__('Signup Date'))->date()->sortable(),
                TextColumn::make('expires_at')->label(__('Next Due Date'))->date()->sortable()->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Service::statusLabel($state))
                    ->color(fn (string $state): string => Service::statusColor($state))
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(Service::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make()->url(fn ($record) => ServiceResource::getUrl('edit', ['record' => $record])),
            ])
            // D7a: the ticked rows on one invoice per currency.
            ->toolbarActions([InvoiceSelectedItemsAction::bulk()]);
    }
}
