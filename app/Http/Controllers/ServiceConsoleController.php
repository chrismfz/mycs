<?php

namespace App\Http\Controllers;

use App\Admin\Resources\ServiceResource\ProxmoxPower;
use App\Helpers\ExtensionHelper;
use App\Models\Service;
use App\Support\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use MyCS\Extensions\Servers\Proxmox\Proxmox;
use MyCS\Proxmox\Console\ConsoleTicket;
use MyCS\Proxmox\ProxmoxException;
use OwenIt\Auditing\Events\AuditCustom;
use Throwable;

/**
 * A VPS's console for the customer (docs/proxmox.md, B6): the page (noVNC, in its own tab) and the ticket it asks for
 * each connection. Who may power the VPS may open its console (`can:update`), only while the service is active and the
 * VM running; each ticket is audited. The ticket, signed with the cluster's console key, opens the relay next to the
 * cluster once, within seconds (ConsoleTicket; docs/proxmox.md, «Σχέδιο: το relay στο cluster»). With the console off
 * on the server, neither the page nor a ticket exists.
 */
class ServiceConsoleController extends Controller
{
    /** Tickets a VPS a minute: a reconnect now and then, not a loop. */
    public const PER_MINUTE = 10;

    public function show(Service $service): View
    {
        abort_unless(ProxmoxPower::guest($service) !== null && self::relay($service) !== null, 404);

        return view('services.console', ['service' => $service]);
    }

    /**
     * The service's console relay (its URL and key), or null when its cluster has the console off or not set up.
     *
     * @return array{url: string, key: string}|null
     */
    public static function relay(Service $service): ?array
    {
        $server = $service->provisioningServer();
        $extension = $server === null ? null : ExtensionHelper::getExtension('server', $server->extension, $server->settings);

        return $extension instanceof Proxmox ? $extension->consoleRelay() : null;
    }

    /**
     * Staff in «Login as client» opening it: named in the ticket, so the relay's check (no session) asks about them, not
     * the customer's login (ADV-A6-1, review 6: a closed account's console stays open for staff only).
     */
    private static function staffId(): ?int
    {
        $staff = Actor::impersonating() ? Auth::guard('staff')->id() : null;

        return $staff === null ? null : (int) $staff;
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
        if (!$extension instanceof Proxmox || ($relay = $extension->consoleRelay()) === null) {
            return $refuse('not_available', 404);
        }
        if (RateLimiter::hit('proxmox-console:' . $service->id, 60) > self::PER_MINUTE) {
            return $refuse('too_soon', 429);
        }
        try {
            $console = $extension->lifecycle($service)->console($service);
        } catch (ProxmoxException $e) {
            // The cluster's answer, not the VM's state: a node down, the token refused. Staff see it in
            // the log; the customer is not told their running VM is stopped (review of #506).
            report($e);

            return $refuse('unavailable', 503);
        } catch (Throwable $e) {
            report($e);

            return $refuse('not_opened');
        }
        $parts = (array) parse_url((string) config('app.url'));
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $token = ConsoleTicket::sign(['origin' => $origin, 'server_id' => $server->id, 'service_id' => $service->id,
            'user_id' => (int) auth('web')->id(), 'staff_id' => self::staffId()] + $console, $relay['key']);
        $service->auditEvent = 'vm_console';
        $service->isCustomEvent = true;
        $service->auditCustomOld = [];
        $service->auditCustomNew = ['user_id' => auth('web')->id(), 'vmid' => $console['vmid']];
        event(new AuditCustom($service));
        $service->isCustomEvent = false;

        // The VNC password comes from the relay, as the websocket's first frame: the page never had it from here.
        return response()->json(['url' => $relay['url'] . '?t=' . $token])->header('Cache-Control', 'no-store');
    }
}
