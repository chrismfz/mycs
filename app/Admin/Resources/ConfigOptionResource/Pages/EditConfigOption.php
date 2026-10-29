<?php

namespace App\Admin\Resources\ConfigOptionResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\ConfigOptionResource;
use App\Exceptions\DisplayException;
use App\Models\ConfigOption;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditConfigOption extends EditRecord
{
    use TwoColumnForm;

    protected static string $resource = ConfigOptionResource::class;

    /** The save in one transaction: a value refused (used meanwhile) rolls back the rest too. */
    protected ?bool $hasDatabaseTransactions = true;

    /** A value services use is refused with a message, not an error page (DATA-8). */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (DisplayException $e) {
            Notification::make()->danger()->title(__('Option not saved'))->body(e($e->getMessage()))->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // Show warning when there are child config options
            DeleteAction::make('Delete')
                ->requiresConfirmation()
                ->modalDescription(
                    fn (ConfigOption $record) => $record->serviceConfigs()->exists()
                        ? 'This config option has services connected to it. Deleting it will also delete it from the services it is associated with. Are you sure you want to delete this config option?'
                        : 'Are you sure you want to delete this config option?',
                )
                ->action(function () {
                    $this->record->serviceConfigs()->delete();
                    $this->record->delete();

                    return redirect()->to(ConfigOptionResource::getUrl());
                }),
        ];
    }
}
