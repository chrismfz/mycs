<?php

namespace App\Services\Proxmox;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The customer's console tickets (docs/proxmox.md, B6): what the relay needs to open a guest's websocket, kept a few
 * seconds under a random token the browser gets once. Taken once: a second use, or one too late, opens nothing. The
 * token secret is never in it: the relay reads it from the server's settings.
 */
final class ConsoleTickets
{
    /** The browser opens the websocket at once; Proxmox's own ticket lives 10 seconds more. */
    public const TTL = 30;

    /**
     * @param  array{vmid: int, base: string, path: string, port: int, ticket: string}  $console
     */
    public static function issue(int $serverId, int $serviceId, int $userId, array $console): string
    {
        $token = Str::random(48);
        Cache::put(self::key($token), ['server_id' => $serverId, 'service_id' => $serviceId, 'user_id' => $userId] + $console, self::TTL);

        return $token;
    }

    /**
     * The ticket, once: the first to take it (a marker added atomically) gets it.
     *
     * @return array{server_id: int, service_id: int, user_id: int, vmid: int, base: string, path: string, port: int, ticket: string}|null
     */
    public static function take(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9]{48}$/', $token) !== 1 || !Cache::add(self::key($token) . '.taken', true, self::TTL)) {
            return null;
        }
        $ticket = Cache::pull(self::key($token));

        return is_array($ticket) ? $ticket : null;
    }

    private static function key(string $token): string
    {
        return 'proxmox-console.' . hash('sha256', $token);
    }
}
