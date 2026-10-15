<?php

namespace App\Admin\Resources\ServiceResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Resources\ServiceResource;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\License;
use App\Models\Plan;
use App\Models\Service;
use App\Services\Licensing\Licenses;
use App\Services\Money\MoneyTrail;
use App\Services\Service\DeleteService;
use App\Services\ServiceUpgrade\ChangeCycle;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
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

    /** The change of cycle before it is made: what the customer pays from then, and now what is credited and billed. */
    private static function cyclePreview(Service $service, mixed $planId, string $mode): string
    {
        $plan = ChangeCycle::plans($service)->firstWhere('id', (int) $planId);
        if ($plan === null) {
            return 'Choose a plan.';
        }
        if (($why = ChangeCycle::refusal($service, $plan, $mode)) !== null) {
            return $why;
        }
        $quote = ChangeCycle::quote($service, $plan, $mode);
        $currency = $service->currency_code;
        if ((int) $plan->id === (int) $service->plan_id) {
            return 'Takes back the change set for the next renewal' . ($service->nextPlan ? " (to {$service->nextPlan->name})." : '.');
        }
        $text = "Recurring amount: {$quote['price']} {$currency} net per unit, {$quote['gross']} {$currency} with tax (now {$service->price} net).";
        if ($mode === ChangeCycle::NEXT_RENEWAL) {
            $text .= ' It applies when the next period is billed; an open renewal of the old cycle is cancelled and billed again.';
        }
        if ($mode === ChangeCycle::NOW) {
            $text .= " Credit for the unused days: {$quote['credit']} {$currency}. New period {$quote['start']?->toDateString()} – "
                . "{$quote['end']?->toDateString()}, billed {$quote['charge']} {$currency} with tax.";
        }

        return $text;
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
                    if ($record->isEnded() || $record->status === Service::STATUS_PENDING || $record->provisioningServer() === null) {
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
            // SVC-14b: another plan of its product (another cycle), now or from the next renewal, with what it does.
            Action::make('changeCycle')
                ->label('Change billing cycle')
                ->visible(fn (Service $record) => ChangeCycle::plans($record)->isNotEmpty())
                ->schema([
                    Select::make('plan_id')
                        ->label('New plan')
                        ->options(fn (Service $record) => ChangeCycle::plans($record)->mapWithKeys(fn (Plan $plan) => [$plan->id => $plan->name]))
                        ->required()
                        ->live(),
                    Radio::make('mode')
                        ->label('When')
                        ->options([
                            ChangeCycle::NEXT_RENEWAL => 'From the next renewal (nothing charged or credited now)',
                            ChangeCycle::NOW => 'Now (the unused days become credit; a new period starts today and is billed)',
                        ])
                        ->default(ChangeCycle::NEXT_RENEWAL)
                        ->required()
                        ->live(),
                    Placeholder::make('preview')
                        ->label('What it does')
                        ->content(fn (Get $get, Service $record) => self::cyclePreview($record, $get('plan_id'), (string) $get('mode'))),
                ])
                ->action(function (array $data, Service $record, Action $action): void {
                    $plan = ChangeCycle::plans($record)->firstWhere('id', (int) $data['plan_id']);
                    try {
                        if ($plan === null) {
                            throw new DisplayException('This billing cycle is not available for this service.');
                        }
                        $invoice = ChangeCycle::apply($record, $plan, $data['mode'], MoneyTrail::SOURCE_STAFF);
                    } catch (DisplayException $e) {
                        Notification::make()->title('Not changed')->body($e->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }
                    $record->refresh();
                    $this->refreshFormData(['plan_id', 'price', 'expires_at']);
                    $this->filledAttributes = $record->getAttributes();
                    Notification::make()->title('Billing cycle changed')
                        ->body($invoice ? "Invoice #{$invoice->id} bills the new period." : 'It applies when the next period is billed.')
                        ->success()->send();
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
                            'upgrade' => 'Change package (upgrade/downgrade)',
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

            // Its nixpal license (L2): staff reissue whatever the limit, and set bindings or status by hand (WHMCS's
            // licensing admin page).
            Action::make('reissueLicense')->label('Reissue license')->icon('ri-refresh-line')->color('gray')
                ->visible(fn (Service $record) => $record->license !== null)
                ->authorize('update')
                ->requiresConfirmation()
                ->modalDescription('Its bindings are cleared and the next server that checks it takes it (staff: whatever the limit).')
                ->action(function (Service $record) {
                    Licenses::reissue($record->license, (string) request()->ip(), byStaff: true);
                    Notification::make()->success()->title('License reissued')->send();
                    $this->refreshFormData(['license']);
                }),
            Action::make('editLicense')->label('Edit license')->icon('ri-key-2-line')->color('gray')
                ->visible(fn (Service $record) => $record->license !== null)
                ->authorize('update')
                ->fillForm(fn (Service $record) => $record->license->only(['status', 'valid_ip', 'valid_domain', 'valid_directory']))
                ->schema([
                    Select::make('status')->required()->options(array_combine($s = [License::STATUS_ACTIVE, License::STATUS_REISSUED, License::STATUS_SUSPENDED, License::STATUS_EXPIRED], $s)),
                    TextInput::make('valid_ip')->label('Valid IPs')->helperText('Comma separated. Empty: the next check binds it.')->maxLength(255),
                    TextInput::make('valid_domain')->label('Valid domains')->helperText('Information only (bound by IP).')->maxLength(255),
                    TextInput::make('valid_directory')->label('Valid directory')->maxLength(255),
                ])
                ->action(function (Service $record, array $data) {
                    $record->license->update(array_map(fn ($value) => $value === '' ? null : $value, $data)); // audited
                    Notification::make()->success()->title('License saved')->send();
                    $this->refreshFormData(['license']);
                }),
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
