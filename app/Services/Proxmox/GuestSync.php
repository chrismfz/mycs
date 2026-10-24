<?php

namespace App\Services\Proxmox;

use App\Helpers\ExtensionHelper;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use MyCS\Extensions\Servers\Proxmox\Proxmox;
use MyCS\Proxmox\Guest;
use RuntimeException;

/**
 * A cluster's guests read into proxmox_guests: new ones added unmapped, known ones refreshed (their mapping kept), the
 * ones no longer listed marked gone (kept, with their mapping: a guest moved to another cluster or deleted is staff's to
 * look at). Reads only.
 */
final class GuestSync
{
    /** @return array{seen: int, new: int, gone: int} */
    public static function sync(Server $server): array
    {
        $extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);
        if (!$extension instanceof Proxmox) {
            throw new \InvalidArgumentException("{$server->name} is not a Proxmox server.");
        }
        $lock = Cache::lock("proxmox.sync.{$server->id}", 1800);
        if (!$lock->get()) {
            throw new RuntimeException("{$server->name} is being read already: try again in a minute.");
        }
        try {
            return self::read($server, $extension);
        } finally {
            $lock->release();
        }
    }

    /** @return array{seen: int, new: int, gone: int} */
    private static function read(Server $server, Proxmox $extension): array
    {
        $guests = $extension->client()->guests(); // throws before anything is written: a failed read marks nothing gone
        // An empty list where there were guests is a token that sees nothing (its ACL removed), not a cluster emptied:
        // nothing marked gone (review of #446).
        // A token that sees its nodes but no guest is a cluster really emptied: then they go.
        if ($guests === [] && ProxmoxGuest::query()->where('server_id', $server->id)->whereNull('gone_at')->exists()
            && $extension->client()->nodes() === []) {
            throw new RuntimeException("{$server->name} listed no guest: check the token's permissions (PVEAuditor on /). Nothing marked gone.");
        }

        $now = now();
        $new = 0;
        $seen = [];
        foreach ($guests as $guest) {
            /** @var Guest $guest */
            $row = ProxmoxGuest::query()->firstOrNew(['server_id' => $server->id, 'vmid' => $guest->vmid]);
            $new += $row->exists ? 0 : 1;
            // A VMID reused by another machine (Proxmox gives the lowest free one): not the guest that was mapped.
            if ($row->exists && self::anotherMachine($row, $guest)) {
                $row->fill(['service_id' => null, 'internal' => false, 'note' => null, 'macs' => null, 'ips' => null]);
            }
            $row->fill([
                'type' => $guest->type, 'node' => $guest->node, 'pool' => $guest->pool, 'name' => $guest->name, 'status' => $guest->status,
                'template' => $guest->template, 'cpus' => $guest->cpus, 'max_memory' => $guest->maxMemory, 'max_disk' => $guest->maxDisk,
                'uptime' => $guest->uptime, 'tags' => $guest->tags, 'last_seen_at' => $now, 'gone_at' => null,
            ]);
            // What could not be read this time (an agent not answering) keeps what was read before.
            if ($guest->macs !== []) {
                $row->macs = $guest->macs;
            }
            if ($guest->ips !== []) {
                $row->ips = $guest->ips;
            }
            $row->save();
            $seen[] = $guest->vmid;
        }
        $gone = ProxmoxGuest::query()->where('server_id', $server->id)->whereNull('gone_at')->whereNotIn('vmid', $seen ?: [0])
            ->update(['gone_at' => $now, 'status' => 'gone']);

        return ['seen' => count($seen), 'new' => $new, 'gone' => $gone];
    }

    /**
     * Another machine under the same VMID: its MACs all different from the ones known, or, with no MAC to compare, a
     * guest that was gone coming back under another name.
     */
    private static function anotherMachine(ProxmoxGuest $row, Guest $guest): bool
    {
        $known = (array) $row->macs;
        if ($known !== [] && $guest->macs !== []) {
            return array_intersect($known, $guest->macs) === [];
        }

        return $row->gone_at !== null && (string) $row->name !== $guest->name;
    }

    /** @return Collection<int, Server> */
    public static function servers()
    {
        return Server::query()->where('extension', 'Proxmox')->where('enabled', true)->with('settings')->get();
    }
}
