<?php

namespace App\Admin\Resources;

use App\Admin\Clusters\Services;
use App\Admin\Resources\LicenseResource\Pages\ListLicenses;
use App\Models\License;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The licenses console (L2, beyond WHMCS): every nixpal license with its signs of life, the active ones that went
 * silent and the expired or suspended ones still calling. A row opens its service (where staff reissue or edit it).
 */
class LicenseResource extends Resource
{
    protected static ?string $model = License::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-key-2-line';

    protected static ?string $cluster = Services::class;

    protected static ?int $navigationSort = 4;

    /** An active license that has not checked in for this long is «silent». */
    public const SILENT_DAYS = 7;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['service.product', 'service.client']))
            ->defaultSort('last_access', 'desc')
            ->columns([
                TextColumn::make('license_key')->label('Key')->searchable()->copyable()->fontFamily('mono'),
                TextColumn::make('service.client.name')->label('Client'),
                TextColumn::make('service.product.name')->label('Product'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    License::STATUS_ACTIVE => 'success', License::STATUS_EXPIRED => 'danger', default => 'warning',
                }),
                TextColumn::make('valid_ip')->label('Bound to')->placeholder('—')->searchable(),
                TextColumn::make('last_ok_at')->label('Last good check')->since()->dateTimeTooltip()->sortable()->placeholder('Never'),
                TextColumn::make('last_access')->label('Last call')->since()->dateTimeTooltip()->sortable()->placeholder('Never'),
                TextColumn::make('last_seen_ip')->label('From')->toggleable(),
                TextColumn::make('last_failure')->label('Last failure')->limit(40)->tooltip(fn (License $record) => $record->last_failure)->toggleable(),
                TextColumn::make('reissues')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine($s = [License::STATUS_ACTIVE, License::STATUS_REISSUED, License::STATUS_SUSPENDED, License::STATUS_EXPIRED], $s)),
            ])
            ->recordUrl(fn (License $record) => ServiceResource::getUrl('edit', ['record' => $record->service_id]));
    }

    public static function getPages(): array
    {
        return ['index' => ListLicenses::route('/')];
    }
}
