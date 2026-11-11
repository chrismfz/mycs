<?php

namespace App\Admin\Resources\ServerResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\RedfishMachineResource;
use App\Admin\Resources\ServerResource;
use App\Helpers\ExtensionHelper;
use App\Models\Extension;
use App\Models\RedfishMachine;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use MyCS\Redfish\Machine;
use Throwable;

class EditServer extends EditRecord
{
    use TwoColumnForm;

    protected static string $resource = ServerResource::class;

    /** @var array<string, mixed>|null the BMC's answer, read once a request however often the modal is drawn */
    private ?array $hardwareRead = null;

    protected function getHeaderActions(): array
    {
        return [
            // A machine read through its BMC (Redfish): asked for, never on the page's load (a BMC takes seconds).
            Action::make('hardware')->label('Hardware')->icon('ri-cpu-line')->color('gray')
                ->visible(fn (Server $record) => rescue(fn () => ExtensionHelper::hasFunction($record, 'hardware'), false, false))
                ->modalHeading(fn (Server $record) => 'Hardware: ' . $record->name)
                ->modalContent(fn (Server $record) => view('admin.servers.hardware', $this->hardwareRead ??= self::hardware($record)))
                ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
            DeleteAction::make()->before(function (DeleteAction $action, Server $record) {
                ServerResource::refuseDeletingBusy($action, collect([$record]));
                ExtensionHelper::call($record, 'disabled', [$record], mayFail: true);
            }),
        ];
    }

    /**
     * The machine as its BMC says it now, with its saved settings (never the form's unsaved ones), and who has it.
     *
     * @return array{machine: ?Machine, error: ?string, services: list<array{id: int, label: string}>, own: bool, location: ?string, page: string}
     */
    public static function hardware(Server $server): array
    {
        $machine = $error = null;
        try {
            $machine = ExtensionHelper::getExtension('server', $server->extension, $server->settings)->hardware();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $given = RedfishMachine::givenTo($server);
        // Its page in Utilities → Machines (the hourly read, with its parts), once it has one.
        $row = RedfishMachine::query()->where('server_id', $server->id)->value('id');

        return ['machine' => $machine, 'error' => $error, 'services' => $given['services'], 'own' => $given['own'],
            'location' => $server->settings->firstWhere('key', 'location')?->value,
            'page' => $row === null ? RedfishMachineResource::getUrl('index') : RedfishMachineResource::getUrl('view', ['record' => $row])];
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

    /** A secret typed in leaves the page once saved (review R10), as the Settings page does (AUTH-12). */
    protected function afterSave(): void
    {
        $record = $this->getRecord();
        if ($record instanceof Extension) {
            foreach (ExtensionHelper::storedSecretKeys($record) as $key) {
                $this->data['settings'][$key] = null;
            }
        }
    }
}
