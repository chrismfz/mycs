<?php

namespace App\Services\Service;

use App\Models\Product;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * The server a new account is created on (WHMCS): the product's group picks one by its fill rule, among its enabled
 * servers of the product's module with room under their max accounts; without a group (or none with room) the
 * product's own server. Least full: the lowest share of max accounts (or fewest accounts where there is no limit).
 * In order: the first with room.
 */
class ServerSelector
{
    public static function forNewAccount(Product $product): ?Server
    {
        $group = $product->serverGroup;
        if ($group === null) {
            return $product->server;
        }
        $module = $product->server?->extension;
        $candidates = $group->servers->filter(fn (Server $server) => $server->enabled
            && ($module === null || $server->extension === $module) && $server->hasRoom())->values();
        if ($candidates->isEmpty()) {
            return $product->server;
        }
        if ($group->fill_type === ServerGroup::FILL_IN_ORDER) {
            return $candidates->first();
        }

        return $candidates->sortBy(fn (Server $server) => $server->max_accounts
            ? $server->accountsCount() / max(1, (int) $server->max_accounts)
            : $server->accountsCount())->first();
    }

    /**
     * The service's server, chosen on its first creation and kept (under the service's lock: two creates at once agree).
     */
    public static function assign(Service $service): ?Server
    {
        if ($service->server_id !== null) {
            return $service->server;
        }

        return DB::transaction(function () use ($service) {
            $locked = Service::query()->whereKey($service->id)->lockForUpdate()->first();
            if ($locked === null) {
                return null;
            }
            if ($locked->server_id === null && $locked->product !== null) {
                $server = self::forNewAccount($locked->product);
                if ($server !== null) {
                    $locked->forceFill(['server_id' => $server->id])->save();
                }
            }
            $service->setRawAttributes($locked->getAttributes(), true);
            $service->unsetRelation('server');

            return $service->server;
        });
    }
}
