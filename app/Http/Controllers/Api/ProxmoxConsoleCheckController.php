<?php

namespace App\Http\Controllers\Api;

use App\Admin\Resources\ServiceResource\ProxmoxPower;
use App\Helpers\ExtensionHelper;
use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Support\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MyCS\Extensions\Servers\Proxmox\Proxmox;
use MyCS\Proxmox\Console\ConsoleTicket;

/**
 * The console relay's question, every 30 seconds (docs/proxmox.md, «Σχέδιο: το relay στο cluster»): are these open
 * consoles still their customers'? Signed with a cluster's console key (X-Console-Signature over the whole body, which
 * carries its time); each console answered only for that cluster, and «no» once its service is not active, no longer
 * mapped to the same VM, or its login without `update` on it (ADV-506-4, 5). With the console off on the server (its
 * key kept), every answer is «no»: the open consoles close.
 */
class ProxmoxConsoleCheckController extends Controller
{
    /** A body this old (or this far ahead) is not answered: a replay. */
    public const MAX_AGE = 60;

    /** The relay holds 64 consoles; a body with more is not its. */
    private const MAX_CONSOLES = 256;

    private const MAX_BODY = 64 * 1024;

    public function __invoke(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $signature = (string) $request->header('X-Console-Signature');
        if ($signature === '' || strlen($body) > self::MAX_BODY) {
            return response()->json(['message' => 'Not signed.'], 403);
        }
        // Every cluster with the key that signed this body: its consoles, and no other's; «no» to all once its console
        // is off (the relay closes them now, not after three unanswered checks).
        $servers = [];
        foreach (Server::query()->where('extension', 'Proxmox')->get() as $server) {
            $extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);
            $key = $extension instanceof Proxmox ? $extension->consoleKey() : null;
            if ($key !== null && ConsoleTicket::checkSigned($body, $signature, [$key])) {
                $servers[$server->id] = $extension->consoleRelay() !== null;
            }
        }
        if ($servers === []) {
            return response()->json(['message' => 'Not signed by a cluster\'s console key.'], 403);
        }
        $data = json_decode($body, true);
        $ts = is_array($data) ? ($data['ts'] ?? null) : null;
        $consoles = is_array($data) ? ($data['consoles'] ?? null) : null;
        if (!is_int($ts) || abs(time() - $ts) > self::MAX_AGE || !is_array($consoles) || count($consoles) > self::MAX_CONSOLES) {
            return response()->json(['message' => 'Not a check, or too old.'], 422);
        }
        $allowed = [];
        foreach ($consoles as $console) {
            if (!is_array($console) || !is_int($console['id'] ?? null)) {
                continue;
            }
            $server = $console['server_id'] ?? null;
            $allowed[(string) $console['id']] = is_int($server) && ($servers[$server] ?? false) && self::allowed($console);
        }

        return response()->json(['allowed' => (object) $allowed])->header('Cache-Control', 'no-store');
    }

    /**
     * A console may stay open: its service on the same cluster and active, still mapped to the same VM, and its login
     * still allowed to power it (its grant on the account not revoked).
     *
     * @param  array<string, mixed>  $console
     */
    public static function allowed(array $console): bool
    {
        foreach (['server_id', 'service_id', 'user_id', 'vmid'] as $key) {
            if (!is_int($console[$key] ?? null)) {
                return false;
            }
        }
        $service = Service::query()->find($console['service_id']);
        $user = User::query()->find($console['user_id']);

        return $service !== null && $user !== null && $service->status === Service::STATUS_ACTIVE
            && $service->provisioningServer()?->id === $console['server_id']
            && ProxmoxPower::guest($service)?->vmid === $console['vmid']
            && (array_key_exists('staff_id', $console) ? self::staffMay($console['staff_id'], $user, $service) : $user->can('update', $service));
    }

    /**
     * A console staff opened in «Login as client» (its ticket names them): open while they may still log in as the
     * client and power the VPS there (active, `admin.users.impersonate`, `manageproducts` as SA-5) and the login they
     * look through still has its grant, the account closed or not (ADV-A6-1, review 6). The check has no session, so
     * owns() would ask about the customer's own login and close it.
     */
    private static function staffMay(mixed $staffId, User $user, Service $service): bool
    {
        $staff = is_int($staffId) ? Staff::query()->find($staffId) : null;
        if (!$staff instanceof Staff || !$staff->hasPermission('admin.users.impersonate')) {
            return false;
        }
        foreach ((array) Actor::STAFF_PERMISSION_AS['manageproducts'] as $admin) {
            if (!$staff->hasPermission($admin)) {
                return false;
            }
        }

        return ClientUser::query()->where('user_id', $user->id)->where('client_id', $service->client_id)->whereNull('revoked_at')->exists();
    }
}
