<?php

namespace App\Admin\Resources\ProxmoxGuestResource\Pages;

use App\Admin\Pages\ProxmoxNodes;
use App\Admin\Resources\ProxmoxGuestResource;
use App\Services\Proxmox\GuestSync;
use App\Support\Redact;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ListProxmoxGuests extends ListRecords
{
    protected static string $resource = ProxmoxGuestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nodes')->label('Nodes')->icon('ri-server-line')->color('gray')->url(ProxmoxNodes::getUrl()),
            // The cron's read, now: a guest just made shows without waiting the 15 minutes.
            Action::make('refresh')->label('Read the clusters now')->icon('ri-refresh-line')
                ->visible(fn () => (bool) Auth::guard('staff')->user()?->hasPermission('admin.servers.update'))
                ->action(function () {
                    foreach (GuestSync::servers() as $server) {
                        try {
                            $result = GuestSync::sync($server);
                            Notification::make()->success()->title($server->name)
                                ->body("{$result['seen']} guest(s), {$result['new']} new, {$result['gone']} gone")->send();
                        } catch (Throwable $e) {
                            report($e);
                            Notification::make()->danger()->title($server->name)->body(Redact::text($e->getMessage()))->send();
                        }
                    }
                }),
        ];
    }
}
