<?php

namespace App\Admin\Resources\ServerResource\Pages;

use App\Admin\Resources\ServerResource;
use App\Helpers\ExtensionHelper;
use App\Models\Extension;
use App\Models\Server;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditServer extends EditRecord
{
    protected static string $resource = ServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->before(function (DeleteAction $action, Server $record) {
                ServerResource::refuseDeletingBusy($action, collect([$record]));
                ExtensionHelper::call($record, 'disabled', [$record], mayFail: true);
            }),
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
