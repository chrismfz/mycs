<?php

namespace App\Admin\Resources\ServiceResource;

use App\Helpers\ExtensionHelper;
use App\Models\ProxmoxGuest;
use App\Models\Service;
use App\Support\Redact;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use MyCS\Extensions\Servers\Proxmox\Proxmox;
use Throwable;

/**
 * The power buttons of a service's Proxmox VM (owner 2026-10-24), on the service's page. Start and reboot are not offered
 * while the service is suspended: its unsuspension starts it. The task is started, not waited for.
 */
final class ProxmoxPower
{
    private const LABELS = ['start' => 'Start', 'shutdown' => 'Shut down', 'reboot' => 'Reboot', 'stop' => 'Stop (hard)'];

    private const ICONS = ['start' => 'ri-play-line', 'shutdown' => 'ri-shut-down-line', 'reboot' => 'ri-restart-line', 'stop' => 'ri-stop-line'];

    public static function guest(Service $service): ?ProxmoxGuest
    {
        $server = $service->provisioningServer();
        if ($server === null || $server->extension !== 'Proxmox') {
            return null;
        }

        return ProxmoxGuest::query()->where('server_id', $server->id)->where('service_id', $service->id)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'destroyed'))->first();
    }

    public static function group(Service $service): ActionGroup
    {
        $guest = self::guest($service);

        return ActionGroup::make(array_map(fn (string $power) => self::action($power), array_keys(self::LABELS)))
            ->label('VM ' . ($guest->vmid ?? ''))->icon('ri-instance-line')->button()->color('gray')
            ->visible($guest !== null && (bool) Auth::guard('staff')->user()?->hasPermission('admin.services.update'));
    }

    private static function action(string $power): Action
    {
        return Action::make('vm_' . $power)->label(self::LABELS[$power])->icon(self::ICONS[$power])
            ->visible(fn (Service $record) => (!in_array($power, ['start', 'reboot'], true) || $record->status === Service::STATUS_ACTIVE)
                && self::guest($record) !== null && (bool) Auth::guard('staff')->user()?->hasPermission('admin.services.update'))
            ->requiresConfirmation()
            ->modalDescription(fn (Service $record) => self::LABELS[$power] . ' VM ' . self::guest($record)?->vmid . ' (' . self::guest($record)?->name . ') on the cluster now.')
            ->action(function (Service $record) use ($power) {
                try {
                    $server = $record->provisioningServer();
                    $extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);
                    if (!$extension instanceof Proxmox) {
                        return;
                    }
                    $extension->lifecycle($record)->power($record, $power);
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title('Not done')->body(e(Redact::text($e->getMessage())))->send();

                    return;
                }
                Notification::make()->success()->title(self::LABELS[$power] . ': sent')->body('The cluster does it now; the list shows it on its next read.')->send();
            });
    }
}
