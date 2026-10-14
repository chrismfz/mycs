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
 * product's own server, whose module the product's settings are for: a product without one gets none (review F3).
 * Least full: the lowest share of max accounts, a server with no limit counting as empty, then the fewest accounts.
 * In order: the first with room.
 *
 * Under the service's lock (assign), not the server's: two new accounts at once may both take a server's last place,
 * as on WHMCS. A pending service holds its place, as WHMCS counts it.
 */
class ServerSelector
{
    public static function forNewAccount(Product $product): ?Server
    {
        $group = $product->serverGroup;
        $module = $product->server?->extension;
        if ($group === null || $module === null) {
            return $product->server;
        }
        $candidates = $group->servers->filter(fn (Server $server) => $server->enabled
            && $server->extension === $module && $server->hasRoom())->values();
        if ($candidates->isEmpty()) {
            return $product->server;
        }
        if ($group->fill_type === ServerGroup::FILL_IN_ORDER) {
            return $candidates->first();
        }

        // Shares and counts are not the same unit (review F5): the share first, the count only between equal shares.
        $scored = $candidates->map(fn (Server $server) => [$server, $server->accountsCount()]);

        return $scored->sort(function (array $a, array $b) {
            $share = fn (array $scored) => $scored[0]->max_accounts ? $scored[1] / (int) $scored[0]->max_accounts : 0.0;

            return [$share($a), $a[1]] <=> [$share($b), $b[1]];
        })->first()[0];
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
