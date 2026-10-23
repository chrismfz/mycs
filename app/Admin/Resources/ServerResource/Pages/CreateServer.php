<?php

namespace App\Admin\Resources\ServerResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\ServerResource;
use App\Helpers\ExtensionHelper;
use Arr;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateServer extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = ServerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = 'server';

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

        return $record;
    }
}
