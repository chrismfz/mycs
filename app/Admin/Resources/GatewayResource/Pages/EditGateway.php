<?php

namespace App\Admin\Resources\GatewayResource\Pages;

use App\Admin\Resources\GatewayResource;
use App\Helpers\ExtensionHelper;
use App\Models\Extension;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditGateway extends EditRecord
{
    protected static string $resource = GatewayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->before(fn ($record) => ExtensionHelper::call($record, 'disabled', [$record], mayFail: true)),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Its stored secrets stay out of the page (sweep §6 #4): left empty, they are kept on save.
        $record = $this->getRecord();
        $data['settings'] = $record instanceof Extension ? ExtensionHelper::settingsForForm($record) : [];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->update(Arr::except($data, ['settings']));

        if (!isset($data['settings'])) {
            return $record;
        }

        ExtensionHelper::saveSettings($record, $record instanceof Extension ? ExtensionHelper::withStoredSecrets($record, $data['settings']) : $data['settings']);

        ExtensionHelper::call($record, 'updated', [$record], mayFail: true);

        // Maybe the extension changed the record, so we need to refresh it
        return $record->refresh();
    }
}
