<?php

namespace App\Admin\Resources\ProxmoxGuestResource\Pages;

use App\Admin\Pages\ProxmoxNodes;
use App\Admin\Resources\ProxmoxGuestResource;
use App\Models\ProxmoxGuest;
use App\Services\Proxmox\GuestSync;
use App\Support\Redact;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Throwable;

class ListProxmoxGuests extends ListRecords
{
    protected static string $resource = ProxmoxGuestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nodes')->label('Nodes')->icon('ri-server-line')->color('gray')->url(ProxmoxNodes::getUrl()),
            // The guests mapped to a service but outside MyCS's pool: the command that puts them in, for staff to run on
            // the cluster (MyCS's token never changes pools).
            Action::make('poolCommand')->label('Pool command')->icon('ri-terminal-box-line')->color('gray')
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn () => new HtmlString(self::poolCommands())),
            // The cron's read, now: a guest just made shows without waiting the 15 minutes.
            Action::make('refresh')->label('Read the clusters now')->icon('ri-refresh-line')
                ->visible(fn () => (bool) Auth::guard('staff')->user()?->hasPermission('admin.servers.update'))
                ->action(function () {
                    foreach (GuestSync::servers() as $server) {
                        try {
                            $result = GuestSync::sync($server);
                            Notification::make()->success()->title($server->name)
                                ->body("{$result['seen']} guest(s), {$result['new']} new, {$result['gone']} gone, {$result['mapped']} mapped from WHMCS")->send();
                        } catch (Throwable $e) {
                            report($e);
                            Notification::make()->danger()->title($server->name)->body(e(Redact::text($e->getMessage())))->send();
                        }
                    }
                }),
        ];
    }

    private static function poolCommands(): string
    {
        $html = '';
        foreach (GuestSync::servers() as $server) {
            $pool = trim((string) $server->settings->firstWhere('key', 'pool')?->value) ?: 'mycs';
            $vmids = ProxmoxGuest::query()->where('server_id', $server->id)->whereNotNull('service_id')->whereNull('gone_at')
                ->where(fn ($q) => $q->whereNull('pool')->orWhere('pool', '!=', $pool))->orderBy('vmid')->pluck('vmid');
            $html .= '<p class="text-sm font-medium mt-2">' . e($server->name) . '</p>';
            $html .= $vmids->isEmpty()
                ? '<p class="text-sm text-gray-500">Every mapped guest is in the pool «' . e($pool) . '».</p>'
                : '<pre class="text-xs p-2 rounded bg-gray-100 dark:bg-white/5 overflow-x-auto">pvesh set /pools --poolid ' . e($pool) . ' --vms ' . e($vmids->implode(',')) . '</pre>';
        }

        return $html ?: '<p class="text-sm text-gray-500">No Proxmox server.</p>';
    }
}
