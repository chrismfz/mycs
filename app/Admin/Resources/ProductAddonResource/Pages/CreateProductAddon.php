<?php

namespace App\Admin\Resources\ProductAddonResource\Pages;

use App\Admin\Resources\ProductAddonResource;
use App\Admin\Resources\ProductResource\Pages\CreateProduct;

class CreateProductAddon extends CreateProduct
{
    protected static string $resource = ProductAddonResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // An addon: no module of its own, never in the shop (docs/addons.md).
        return [...$data, 'is_addon' => true, 'server_id' => null];
    }
}
