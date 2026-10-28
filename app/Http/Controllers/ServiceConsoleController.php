<?php

namespace App\Http\Controllers;

use App\Admin\Resources\ServiceResource\ProxmoxPower;
use App\Helpers\ExtensionHelper;
use App\Models\Service;
use App\Services\Proxmox\ConsoleTickets;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use MyCS\Extensions\Servers\Proxmox\Proxmox;
use MyCS\Proxmox\ProxmoxException;
use OwenIt\Auditing\Events\AuditCustom;
use Throwable;

/**
 * A VPS's console for the customer (docs/proxmox.md, B6): the page (noVNC, in its own tab) and the ticket it asks for
 * each connection. Who may power the VPS may open its console (`can:update`), only while the service is active and the
 * VM running; each ticket is audited. The ticket opens the relay once, within seconds (ConsoleTickets).
 */
class ServiceConsoleController extends Controller
{
    /** Tickets a VPS a minute: a reconnect now and then, not a loop. */
    public const PER_MINUTE = 10;

    public function show(Service $service): View
    {
        abort_unless(ProxmoxPower::guest($service) !== null, 404);

        return view('services.console', ['service' => $service]);
    }

    public function ticket(Service $service): JsonResponse
    {
        $refuse = fn (string $key, int $status = 409) => response()->json(['message' => __('services.proxmox.console.' . $key)], $status);
        if ($service->fresh()?->status !== Service::STATUS_ACTIVE) {
            return $refuse('not_active');
        }
        $guest = ProxmoxPower::guest($service);
        $server = $service->provisioningServer();
        $extension = $guest === null || $server === null ? null : ExtensionHelper::getExtension('server', $server->extension, $server->settings);
        if (!$extension instanceof Proxmox) {
            return $refuse('not_available', 404);
        }
        if (RateLimiter::hit('proxmox-console:' . $service->id, 60) > self::PER_MINUTE) {
            return $refuse('too_soon', 429);
        }
        try {
            $console = $extension->lifecycle($service)->console($service);
        } catch (ProxmoxException $e) {
            // The cluster's answer, not the VM's state: the token without VM.Console, a node down. Staff see it in
            // the log; the customer is not told their running VM is stopped (review of #506).
            report($e);

            return $refuse('unavailable', 503);
        } catch (Throwable $e) {
            report($e);

            return $refuse('not_opened');
        }
        $token = ConsoleTickets::issue($server->id, $service->id, (int) auth('web')->id(), $console);
        $service->auditEvent = 'vm_console';
        $service->isCustomEvent = true;
        $service->auditCustomOld = [];
        $service->auditCustomNew = ['user_id' => auth('web')->id(), 'vmid' => $console['vmid']];
        event(new AuditCustom($service));
        $service->isCustomEvent = false;
        $base = preg_replace('#^http#', 'ws', rtrim((string) config('app.url'), '/'));

        return response()->json(['url' => $base . config('services.proxmox_console.path') . '?token=' . $token, 'password' => $console['ticket']])
            ->header('Cache-Control', 'no-store');
    }
}
