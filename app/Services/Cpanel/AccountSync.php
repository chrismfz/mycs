<?php

namespace App\Services\Cpanel;

use App\Helpers\ExtensionHelper;
use App\Models\CpanelAccount;
use App\Models\Server;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MyCS\Extensions\Servers\CPanel\CPanel;
use RuntimeException;

/**
 * A WHM server's accounts read into cpanel_accounts (owner 2026-10-28: «what goes on in each server», as Mail Domains):
 * new ones added, mapped at once when exactly one live service on this server has that cPanel username; known ones
 * refreshed (their mapping kept, given to the new live service when theirs ended); the ones no longer listed marked gone
 * (kept, with their mapping). Reads only.
 */
final class AccountSync
{
    /** @return array{seen: int, new: int, gone: int} */
    public static function sync(Server $server): array
    {
        $extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);
        if (!$extension instanceof CPanel) {
            throw new \InvalidArgumentException("{$server->name} is not a cPanel server.");
        }
        $lock = Cache::lock("cpanel.sync.{$server->id}", 600);
        if (!$lock->get()) {
            throw new RuntimeException("{$server->name} is being read already: try again in a minute.");
        }
        try {
            // Stamped as the read began, not as it ended: a service changed while the server answered is newer than the
            // read (review of «Differs»).
            // A second earlier: timestamps are kept to the second, and one in the read's own second is newer (round 2).
            $readAt = now()->subSecond();

            return self::read($server, $extension->inventory(), $readAt);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @return array{seen: int, new: int, gone: int}
     */
    private static function read(Server $server, array $accounts, ?Carbon $readAt = null): array
    {
        if ($accounts === [] && CpanelAccount::query()->where('server_id', $server->id)->whereNull('gone_at')->exists()) {
            throw new RuntimeException("{$server->name} listed no account: check its token. Nothing marked gone.");
        }

        return DB::transaction(function () use ($server, $accounts, $readAt) {
            $now = $readAt ?? now();
            $new = 0;
            $seen = [];
            foreach ($accounts as $data) {
                $row = CpanelAccount::query()->with('service')->firstOrNew(['server_id' => $server->id, 'user' => $data['user']]);
                $row->setRelation('server', $server); // its login read once, not per row (round 3 of #495)
                $new += $row->exists ? 0 : 1;
                $fill = $data;
                unset($fill['user'], $fill['created_at']);
                $fill['suspended_at'] = $data['suspended_at'] ? Carbon::createFromTimestamp($data['suspended_at']) : null;
                $fill['created_on_server_at'] = $data['created_at'] ? Carbon::createFromTimestamp($data['created_at']) : null;
                // What could not be read this time (bandwidth, resellers) keeps what was read before.
                foreach (['bandwidth_used_mb', 'reseller'] as $key) {
                    if ($data[$key] === null && $row->exists) {
                        unset($fill[$key], $fill[$key === 'bandwidth_used_mb' ? 'bandwidth_limit_mb' : 'reseller']);
                    }
                }
                $row->fill($fill + ['last_seen_at' => $now, 'gone_at' => null]);
                if ($row->service_id === null && !$row->internal) {
                    $row->service_id = $row->suggestedServiceId();
                } elseif ($row->service_id !== null && !in_array($row->service?->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) {
                    // Its service ended and another live one now has the account (a username made again): that one's.
                    $row->service_id = $row->suggestedServiceId() ?? $row->service_id;
                }
                $row->save();
                $seen[] = $data['user'];
            }
            $gone = CpanelAccount::query()->where('server_id', $server->id)->whereNull('gone_at')->whereNotIn('user', $seen ?: [''])
                ->update(['gone_at' => $now]);

            return ['seen' => count($seen), 'new' => $new, 'gone' => $gone];
        });
    }

    /** @return Collection<int, Server> */
    public static function servers()
    {
        return Server::query()->where('extension', 'CPanel')->where('enabled', true)->with('settings')->get();
    }

    /**
     * The live services on cPanel servers whose account is not on their server (a service billed for nothing, or an
     * account under another name): for staff to look at. Only servers read at least once (one never read lists nothing
     * yet: review of #495); the accounts looked up in memory, not one query each.
     *
     * @return Collection<int, Service>
     */
    public static function servicesWithoutAccount()
    {
        $servers = CpanelAccount::query()->whereIn('server_id', self::servers()->pluck('id'))->distinct()->pluck('server_id')->all();
        if ($servers === []) {
            return new Collection;
        }
        $accounts = CpanelAccount::query()->whereIn('server_id', $servers)->whereNull('gone_at')->get(['server_id', 'user', 'service_id']);
        $live = $accounts->mapWithKeys(fn (CpanelAccount $account) => [$account->server_id . '|' . $account->user => true]);
        // One staff mapped by hand, under another name, has its account (round 2 of #495).
        $mapped = $accounts->pluck('service_id')->filter()->flip();

        return Service::query()->with(['product', 'client', 'properties'])
            ->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])
            ->where(fn ($q) => $q->whereIn('server_id', $servers)
                ->orWhere(fn ($q) => $q->whereNull('server_id')->whereHas('product', fn ($q) => $q->whereIn('server_id', $servers))))
            ->get()
            ->filter(function (Service $service) use ($live, $mapped) {
                if (isset($mapped[$service->id])) {
                    return false;
                }
                $user = strtolower((string) ($service->properties->firstWhere('key', 'cpanel_username')->value ?? $service->username));

                return $user === '' || !isset($live[($service->server_id ?? $service->product?->server_id) . '|' . $user]);
            })
            ->values();
    }
}
