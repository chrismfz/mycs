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
use App\Policies\ServicePolicy;
use App\Support\Actor;
use App\Support\StaffSessions;
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
        $memo = []; // one body's lookups, asked once (review 2, A7)
        foreach ($consoles as $console) {
            if (!is_array($console) || !is_int($console['id'] ?? null)) {
                continue;
            }
            $server = $console['server_id'] ?? null;
            $allowed[(string) $console['id']] = is_int($server) && ($servers[$server] ?? false) && self::allowed($console, $memo);
        }

        return response()->json(['allowed' => (object) $allowed])->header('Cache-Control', 'no-store');
    }

    /**
     * A console may stay open: its service on the same cluster and active, still mapped to the same VM, and its login
     * still allowed to power it (its grant on the account not revoked). $memo keeps one body's lookups (staff, logins,
     * grants, staff sessions: up to 256 consoles a body every 30 s, review 2, A7).
     *
     * @param  array<string, mixed>  $console
     * @param  array<string, mixed>  $memo
     */
    public static function allowed(array $console, array &$memo = []): bool
    {
        foreach (['server_id', 'service_id', 'user_id', 'vmid'] as $key) {
            if (!is_int($console[$key] ?? null)) {
                return false;
            }
        }
        $service = Service::query()->find($console['service_id']);
        $user = self::remember($memo, 'user:' . $console['user_id'], fn () => User::query()->find($console['user_id']));

        return $service !== null && $user instanceof User && $service->status === Service::STATUS_ACTIVE
            && $service->provisioningServer()?->id === $console['server_id']
            && ProxmoxPower::guest($service)?->vmid === $console['vmid']
            && (array_key_exists('staff_id', $console) ? self::staffMay($console, $user, $service, $memo) : $user->can('update', $service));
    }

    /**
     * A console staff opened in «Login as client» (its ticket names them): open while they may still log in as the
     * client and power the VPS there (active, `admin.users.impersonate`, and what ServicePolicy::update takes in
     * «Login as client», Actor::staffMayAsStaff, SA-5), the login they look through still has its grant, the account
     * closed or not (ADV-A6-1, review 6), and that «Login as client» is still on: the staff login's row not ended, its
     * session still impersonating the same user with the ticket's nonce (review 2, A1: Leave, logout or an ended
     * session close it). A console without the binding (a ticket from before it, a relay not redeployed) is refused.
     * The check has no session, so owns() would ask about the customer's own login and close it.
     *
     * @param  array<string, mixed>  $console
     * @param  array<string, mixed>  $memo
     */
    private static function staffMay(array $console, User $user, Service $service, array &$memo): bool
    {
        $staffId = $console['staff_id'];
        $ulid = $console['staff_session'] ?? null;
        $nonce = $console['impersonation'] ?? null;
        if (!is_int($staffId) || !is_string($ulid) || !is_string($nonce)) {
            return false;
        }
        $staff = self::remember($memo, 'staff:' . $staffId, fn () => Staff::query()->with('role')->find($staffId));
        if (!$staff instanceof Staff) {
            return false;
        }
        $may = self::remember($memo, 'may:' . $staffId, fn () => $staff->hasPermission('admin.users.impersonate')
            && Actor::staffMayAsStaff($staff, ServicePolicy::UPDATE_PERMISSION, acting: true));

        return $may === true
            && self::remember($memo, "grant:{$user->id}:{$service->client_id}", fn () => ClientUser::liveGrant($user, (int) $service->client_id, includeClosed: true) !== null) === true
            && self::remember($memo, "session:{$staffId}:{$ulid}:{$user->id}:{$nonce}", fn () => StaffSessions::stillImpersonating($staff, $ulid, $user->id, $nonce)) === true;
    }

    /**
     * @param  array<string, mixed>  $memo
     * @param  callable(): mixed  $fetch
     */
    private static function remember(array &$memo, string $key, callable $fetch): mixed
    {
        if (!array_key_exists($key, $memo)) {
            $memo[$key] = $fetch();
        }

        return $memo[$key];
    }
}
