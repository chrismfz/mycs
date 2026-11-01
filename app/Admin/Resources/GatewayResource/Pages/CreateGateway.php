<?php

namespace App\Admin\Resources\GatewayResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\GatewayResource;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Services\Money\GatewayBalances;
use Arr;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGateway extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = GatewayResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = 'gateway';

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $data['enabled'] = true;
        $record = static::getModel()::create(Arr::except($data, ['settings']));

        if (!isset($data['settings'])) {
            return $record;
        }

        ExtensionHelper::saveSettings($record, $data['settings'], skipNull: true);

        ExtensionHelper::call($record, 'enabled', [$record], mayFail: true);
        if ($record instanceof Gateway) {
            GatewayBalances::readSoon($record); // Bank totals: seen now, not at the next hourly read
        }

        return $record;
    }
}
