<?php

namespace App\Admin\Resources\DomainTldResource\Pages;

use App\Admin\Resources\DomainTldResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDomainTlds extends ListRecords
{
    protected static string $resource = DomainTldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')->label(__('TLD Import & Pricing Sync'))->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->url(DomainTldResource::getUrl('import'))->visible(fn () => ImportTlds::canAccess()),
            CreateAction::make(),
        ];
    }
}
