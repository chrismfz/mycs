<?php

namespace App\Admin\Resources\ServiceResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Resources\ServiceResource;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Service;
use App\Services\Service\DeleteService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    /** The record as the form was filled from it: a save writes only what staff changed since. */
    #[Locked]
    public array $filledAttributes = [];

    protected function afterFill(): void
    {
        $this->filledAttributes = $this->getRecord()->getAttributes();
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Service $record */
        // Livewire reloads the record on every request: diff against the form as it was filled, so a
        // payment or the cron that ran while the page was open is not written back.
        $record->applyStaffChanges($data, $this->filledAttributes ?: null);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        /** @var Service $record */
        $record = $this->getRecord();

        return [
            DeleteAction::make()
                ->form(function (DeleteAction $action) use ($record) {
                    // A record of an account already gone (terminated straight on the server): untick to delete only it.
                    if ($record->isEnded() || $record->status === Service::STATUS_PENDING || $record->product?->server_id === null) {
                        return [];
                    }

                    return [
                        Checkbox::make('deleteExtensionServer')
                            ->label('Also trigger deletion of server')
                            ->default(true),
                    ];
                })
                ->action(function (array $data, Service $record): void {
                    try {
                        (new DeleteService)->handle($record, (bool) ($data['deleteExtensionServer'] ?? false));
                    } catch (DisplayException $e) {
                        Notification::make('Error')->title(__('Service not deleted'))->body($e->getMessage())->danger()->send();

                        return;
                    } catch (Exception $e) {
                        report($e);

                        Notification::make('Error')
                            ->title('Error occured while deleting the related server:')
                            ->body($e->getMessage() . ' The service was not deleted (untick the server deletion to delete it anyway).')
                            ->danger()
                            ->send();

                        // Kept: deleting it would orphan the account on the server and lose its username.
                        return;
                    }
                    $this->redirect(ServiceResource::getUrl());
                }),
            Action::make('changeStatus')
                ->label('Trigger Extension Action')
                ->schema([
                    Select::make('action')
                        ->label('Action')
                        ->options([
                            'create' => 'Create server',
                            'suspend' => 'Suspend server',
                            'unsuspend' => 'Unsuspend server',
                            'terminate' => 'Terminate server',
                            'upgrade' => 'Upgrade server',
                        ])->required(),
                    Checkbox::make('sendNotification')
                        ->label('Send Notification')
                        ->default(false),
                ])
                ->action(function (array $data, Service $record, Action $action): void {
                    try {
                        switch ($data['action']) {
                            case 'create':
                                $sdata = ExtensionHelper::createServer($record);
                                if ($data['sendNotification']) {
                                    // The service's user (order is null for services made by staff or the API); adapters
                                    // return true or the data for the email.
                                    NotificationHelper::serverCreatedNotification($record->client, $record, is_array($sdata) ? $sdata : []);
                                }
                                break;
                            case 'suspend':
                                // A manual hold, saved before the adapter runs (pending/cancelled keep their status).
                                $record->placeOnHold();
                                $sdata = ExtensionHelper::suspendServer($record);
                                break;
                            case 'unsuspend':
                                $sdata = ExtensionHelper::unsuspendServer($record);
                                // Only suspended -> active: a pending or cancelled service is not revived.
                                if ($record->status === Service::STATUS_SUSPENDED) {
                                    $record->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);
                                    if ($data['sendNotification']) {
                                        // WHMCS's «Service Unsuspension Notification» on a manual unsuspend too.
                                        NotificationHelper::serverUnsuspendedNotification($record->client, $record, is_array($sdata) ? $sdata : []);
                                    }
                                }
                                break;
                            case 'terminate':
                                $sdata = ExtensionHelper::terminateServer($record);
                                // Only once the server is gone (it threw otherwise).
                                foreach ($record->markTerminated() as $left) {
                                    Notification::make()->title('Left open for staff')->body($left->getMessage())->warning()->persistent()->send();
                                }
                                break;
                            case 'upgrade':
                                $sdata = ExtensionHelper::upgradeServer($record);
                                break;
                        }
                    } catch (Exception $e) {
                        if (config('app.debug')) {
                            throw $e;
                        }
                        report($e);
                        Notification::make('Error')
                            ->title('Error occured while triggering the action:')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                        $action->halt();
                    }
                    Notification::make('Success')
                        ->title('Action triggered successfully')
                        ->body('The action has been triggered successfully')
                        ->success()
                        ->send();
                })
                ->color('primary')
                ->modalSubmitActionLabel('Trigger'),

            AuditAction::make()->auditChildren([
                'order',
                'invoices',
                'properties',
                'configs',
                'invoiceItems',
            ]),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        /** @var Service $record */
        $record = $this->getRecord();
        if (!$record->cancellation()->exists()) {
            return [];
        }

        return [
            ServiceResource\Widgets\CancellationOverview::class,
        ];
    }
}
