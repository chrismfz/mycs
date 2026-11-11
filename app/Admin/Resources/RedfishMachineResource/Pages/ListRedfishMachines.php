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

    /** How long «Read all now» reads before it leaves the rest to the hourly read. */
    public const READ_ALL_SECONDS = 30.0;

    /** @var float|null another deadline, for the tests */
    public static ?float $readAllSeconds = null;

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
                    $failed = $late = [];
                    // The page's request must end (review of #798): one deadline for them all. Each machine gets a few
                    // seconds for its parts; those not reached by the deadline wait for the hourly read.
                    $until = microtime(true) + (self::$readAllSeconds ?? self::READ_ALL_SECONDS);
                    foreach ($servers as $server) {
                        $left = $until - microtime(true);
                        if ($left <= 0) {
                            $late[] = $server->name;

                            continue;
                        }
                        try {
                            MachineSync::sync($server, min(8.0, $left));
                        } catch (Throwable) {
                            $failed[] = $server->name;
                        }
                    }
                    $read = $servers->count() - count($failed) - count($late);
                    $failed === [] && $late === []
                        ? Notification::make()->success()->title($read . ' machine(s) read')->send()
                        : Notification::make()->warning()->title($read . ' read, ' . (count($failed) + count($late)) . ' not')
                            ->body(e(trim(($failed === [] ? '' : implode(', ', $failed) . ': not read (why is on its line, unless it was being read already). ')
                                . ($late === [] ? '' : implode(', ', $late) . ': not reached in time, the hourly read will.'))))->send();
                }),
        ];
    }
}
