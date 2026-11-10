<?php

namespace App\Support\Services;

use App\Models\Service;

/**
 * Another live service on the same server with the same account name (an imported second row of one account, a
 * username typed by staff): a module never suspends or removes an account from one service while another still lives on
 * it (sweep PROVISIONING-3; as Centova's and ngm's claimants). Names compared trimmed, in any case, as the service's
 * username or one of $keys among its properties; on the other's own server or, with none, its product's. A pending service
 * counts only by $keys, an account the module made for it: by its username alone it holds none (WHMCS names an order
 * from its domain when it is placed, so a never-created order carries the live account's name; review of the sweep).
 */
final class SharedAccount
{
    /**
     * @param  list<string>  $keys  the module's property keys that name its account
     * @param  string|null  $domain  only another service of this same domain (its own, or its «domain» property): an
     *                               account that holds several domains, one service each, is shared by design (mailcow)
     */
    public static function otherLive(Service $service, string $name, array $keys, ?string $domain = null): ?Service
    {
        $serverId = $service->provisioningServer()?->id;
        $name = strtolower(trim($name));
        if ($serverId === null || $name === '') {
            return null;
        }
        $same = fn (mixed $value) => is_string($value) && strtolower(trim($value)) === $name;

        return Service::query()->whereKeyNot($service->id)->whereNull('parent_service_id')
            ->whereNotIn('status', Service::ENDED_STATUSES)
            ->where(fn ($query) => $query->where('server_id', $serverId)
                ->orWhere(fn ($query) => $query->whereNull('server_id')->whereHas('product', fn ($product) => $product->where('server_id', $serverId))))
            ->with(['properties' => fn ($property) => $property->whereIn('key', [...$keys, 'domain'])])
            ->orderBy('id')->get()
            ->first(fn (Service $other) => (($other->status !== Service::STATUS_PENDING && $same($other->username))
                || $other->properties->contains(fn ($property) => in_array($property->key, $keys, true) && $same($property->value)))
                && ($domain === null || strtolower(trim((string) ($other->properties->firstWhere('key', 'domain')->value ?? $other->domain ?? ''))) === strtolower(trim($domain))));
    }
}
