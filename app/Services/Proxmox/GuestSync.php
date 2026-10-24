<?php

namespace App\Services\Proxmox;

use App\Helpers\ExtensionHelper;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use MyCS\Extensions\Servers\Proxmox\Proxmox;
use MyCS\Proxmox\Guest;

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
        $guests = $extension->client()->guests(); // throws before anything is written: a failed read marks nothing gone

        $now = now();
        $new = 0;
        $seen = [];
        foreach ($guests as $guest) {
            /** @var Guest $guest */
            $row = ProxmoxGuest::query()->firstOrNew(['server_id' => $server->id, 'vmid' => $guest->vmid]);
            $new += $row->exists ? 0 : 1;
            $row->fill([
                'type' => $guest->type, 'node' => $guest->node, 'name' => $guest->name, 'status' => $guest->status,
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

    /** @return Collection<int, Server> */
    public static function servers()
    {
        return Server::query()->where('extension', 'Proxmox')->where('enabled', true)->with('settings')->get();
    }
}
