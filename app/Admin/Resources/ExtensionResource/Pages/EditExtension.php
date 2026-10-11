<?php

namespace App\Admin\Resources\ExtensionResource\Pages;

use App\Admin\Resources\ExtensionResource;
use App\Helpers\ExtensionHelper;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditExtension extends EditRecord
{
    protected static string $resource = ExtensionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('uninstall')
                ->requiresConfirmation()
                ->color('danger')
                ->label('Uninstall Extension')
                ->modalDescription('Are you sure you want to uninstall this extension? This will remove all its data and settings.')
                ->action(function (Model $record) {
                    // Call the extension's uninstalled method
                    ExtensionHelper::call($record, 'uninstalled', mayFail: true);
                    // Delete the record
                    $record->delete();

                    // Redirect to the list page
                    $this->redirect(ExtensionResource::getUrl('index'), true);
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach ($this->record->settings as $setting) {
            $data['settings'][$setting->key] = $setting->value;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // is the extension being enabled or disabled?
        if ($record->enabled != $data['enabled']) {
            // if the extension is being enabled, we need to run the extension's setup method
            if ($data['enabled']) {
                ExtensionHelper::call($record, 'enabled', [$record], mayFail: true);
            } else {
                ExtensionHelper::call($record, 'disabled', [$record], mayFail: true);
            }
        }

        $record->update(Arr::except($data, ['settings']));

        if (!isset($data['settings'])) {
            return $record;
        }

        ExtensionHelper::saveSettings($record, $data['settings']);

        ExtensionHelper::call($record, 'updated', [$record], mayFail: true);

        // Maybe the extension changed the record, so we need to refresh it
        return $record->refresh();
    }
}
