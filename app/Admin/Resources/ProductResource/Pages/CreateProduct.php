<?php

namespace App\Admin\Resources\ProductResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\ProductResource;
use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Support\ProductSettings;
use Arr;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = ProductResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $record = static::getModel()::create(Arr::except($data, ['settings']));

        if (!isset($data['settings'])) {
            return $record;
        }

        $product_config = ExtensionHelper::getProductConfig(Server::findOrFail($data['server_id']), $data['settings']);

        ProductSettings::save($record, $product_config, $data['settings']);

        return $record;
    }
}
