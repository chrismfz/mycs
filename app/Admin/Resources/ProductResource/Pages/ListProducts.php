<?php

namespace App\Admin\Resources\ProductResource\Pages;

use App\Admin\Resources\ProductResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('sslCatalogue')->label(__('SSL catalogue & pricing'))->icon('heroicon-o-lock-closed')->color('gray')
                ->url(ProductResource::getUrl('ssl'))->visible(fn () => ImportSsl::canAccess()),
        ];
    }
}
