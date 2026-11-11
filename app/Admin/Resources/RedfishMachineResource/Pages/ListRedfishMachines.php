<?php

namespace App\Admin\Resources\RedfishMachineResource\Pages;

use App\Admin\Resources\RedfishMachineResource;
use App\Admin\Resources\ServerResource;
use App\Services\Redfish\MachineSync;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ListRedfishMachines extends ListRecords
{
    protected static string $resource = RedfishMachineResource::class;

    public function mount(): void
    {
        parent::mount();
        // A machine just added (Servers → Create server) is on the list before its first read.
        MachineSync::ensureRows();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')->label('Add a machine')->icon('ri-add-line')->color('gray')
                ->visible(fn () => ServerResource::canCreate())->url(ServerResource::getUrl('create')),
            // The cron's hourly read, now, of every machine: each within a few seconds, the slow ones cut short.
            Action::make('refresh')->label('Read all now')->icon('ri-refresh-line')
                ->visible(fn () => (bool) Auth::guard('staff')->user()?->hasPermission('admin.servers.update'))
                ->action(function () {
                    $servers = MachineSync::servers();
                    $failed = [];
                    // The whole page's request must end: the time is shared, a machine each at least a few seconds.
                    $budget = max(4.0, 40.0 / max(1, $servers->count()));
                    foreach ($servers as $server) {
                        try {
                            MachineSync::sync($server, $budget);
                        } catch (Throwable) {
                            $failed[] = $server->name;
                        }
                    }
                    $failed === []
                        ? Notification::make()->success()->title($servers->count() . ' machine(s) read')->send()
                        : Notification::make()->warning()->title(($servers->count() - count($failed)) . ' read, ' . count($failed) . ' not')
                            ->body(e(implode(', ', $failed)) . ': why is on each one\'s line.')->send();
                }),
        ];
    }
}
