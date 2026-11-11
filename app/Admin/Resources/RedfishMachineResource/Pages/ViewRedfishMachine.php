<?php

namespace App\Admin\Resources\RedfishMachineResource\Pages;

use App\Admin\Resources\RedfishMachineResource;
use App\Admin\Resources\RedfishMachineResource\MachineActionButtons;
use App\Admin\Resources\ServerResource;
use App\Models\RedfishMachine;
use App\Services\Redfish\MachineActions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One machine as last read: its summary and its parts (fans, temperatures, power supplies, processors, memory,
 * drives). What a customer who rents the machine may be shown later is this same kept read, never a call to the BMC.
 */
class ViewRedfishMachine extends ViewRecord
{
    protected static string $resource = RedfishMachineResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->machineRecord()->server->name ?? 'Machine';
    }

    private function machineRecord(): RedfishMachine
    {
        /** @var RedfishMachine $record */
        $record = $this->getRecord();

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            ...array_map(fn ($action) => $action->record($this->getRecord()), MachineActionButtons::make()),
            RedfishMachineResource::readAction()->record($this->getRecord()),
            Action::make('server')->label('Server settings')->icon('ri-settings-3-line')->color('gray')
                ->visible(fn () => ServerResource::canEdit($this->machineRecord()->server))
                ->url(fn () => ServerResource::getUrl('edit', ['record' => $this->machineRecord()->server_id])),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            View::make('admin.redfish.machine')->viewData(fn () => [
                'row' => $this->machineRecord(),
                'machine' => $this->machineRecord()->machine(),
                'given' => RedfishMachine::givenTo($this->machineRecord()->server),
                'location' => $this->machineRecord()->server->settings->firstWhere('key', 'location')?->value,
                'actions' => MachineActions::offered($this->machineRecord()->server),
            ])->columnSpanFull(),
        ]);
    }
}
