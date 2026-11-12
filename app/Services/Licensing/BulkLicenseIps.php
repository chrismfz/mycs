<?php

namespace App\Services\Licensing;

use App\Exceptions\DisplayException;
use App\Models\License;
use App\Models\LicenseIp;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * A bulk license's list of IPs (docs/zonecloud-bulk-license.md §4.2, §8.2): added and removed by staff (and later the
 * customer), never by a check. Each change is a row's added_at or removed_at, so what was on the list on any day is
 * known; who made it, the audit.
 */
final class BulkLicenseIps
{
    public const LABEL_LENGTH = 100;

    /**
     * Adds the addresses in $text, one an entry (new lines or commas between them), each with an optional name after a
     * space: «198.51.100.7 web1». All or nothing: a bad entry, or an address another bulk license has, adds none.
     * Already on this list: left as it is.
     *
     * @return array{added: list<string>, kept: list<string>, warnings: list<string>}
     */
    public static function add(License $license, string $text): array
    {
        $entries = self::parse($text);
        if ($entries === []) {
            throw new DisplayException(__('No IP address given.'));
        }

        return DB::transaction(function () use ($license, $entries) {
            // One add of the product at a time: the check that no other bulk license of it has the IP holds until we insert
            // (review of #821: two licenses' own locks let both in). The product first, then the license, always.
            Product::query()->whereKey($license->service?->product_id)->lockForUpdate()->first();
            $locked = self::lockBulk($license);
            $active = $locked->ips()->active()->pluck('ip')->all();
            $added = $kept = $warnings = [];
            foreach ($entries as $ip => $label) {
                if (in_array($ip, $active, true)) {
                    $kept[] = $ip;

                    continue;
                }
                // On another live bulk license of this product (anyone's): it would be billed twice, or answer for someone
                // else's key. An ended one's IPs are free; another product (a Node next to an Agent) has its own list.
                $other = LicenseIp::query()->active()->where('ip', $ip)->where('license_id', '!=', $locked->id)
                    ->whereHas('license', fn ($query) => $query->where('status', '!=', License::STATUS_EXPIRED)
                        ->whereHas('service', fn ($service) => $service->where('product_id', $locked->service?->product_id)))
                    ->with('license.service')->first();
                if ($other !== null) {
                    throw new DisplayException($other->license?->service?->client_id === $locked->service?->client_id
                        ? __(':ip is already on another bulk license of this client (service #:service).', ['ip' => $ip, 'service' => $other->license->service_id])
                        : __(':ip is already in use by another license. Please contact support.', ['ip' => $ip]));
                }
                $added[] = $ip;
                $warnings = array_merge($warnings, self::singlesOf($locked, $ip));
            }
            $now = now();
            foreach ($added as $ip) {
                $locked->ips()->create(['ip' => $ip, 'label' => $entries[$ip], 'added_at' => $now]); // audited
            }

            return ['added' => $added, 'kept' => $kept, 'warnings' => $warnings];
        });
    }

    /**
     * Takes the given rows off the list now (rows of another license, or already off it, are ignored).
     *
     * @param  list<int>  $ids
     * @return int how many were removed
     */
    public static function remove(License $license, array $ids): int
    {
        // Also from a license whose product is no longer bulk: staff free what it held.
        return DB::transaction(function () use ($license, $ids) {
            $locked = License::query()->whereKey($license->id)->lockForUpdate()->firstOrFail();
            $removed = 0;
            foreach ($locked->ips()->active()->whereIn('id', $ids)->get() as $row) {
                $row->update(['removed_at' => now()]); // audited, one by one
                $removed++;
            }

            return $removed;
        });
    }

    /**
     * «IP name» entries, the IPs as stored; a repeated one keeps the first name it is given.
     *
     * @return array<string, string|null> ip => label
     */
    public static function parse(string $text): array
    {
        $entries = [];
        foreach (preg_split('/[\r\n,;]+/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2) ?: [$line];
            $ip = LicenseIp::normalize($parts[0]);
            if ($ip === null) {
                throw new DisplayException(__('«:entry» is not an IP address.', ['entry' => mb_substr($parts[0], 0, 64)]));
            }
            $label = trim($parts[1] ?? '');
            $entries[$ip] ??= $label === '' ? null : mb_substr($label, 0, self::LABEL_LENGTH);
        }

        return $entries;
    }

    private static function lockBulk(License $license): License
    {
        $locked = License::query()->whereKey($license->id)->lockForUpdate()->firstOrFail();
        $locked->setRelation('service', $license->service);
        if (!Licenses::isBulk($locked->service)) {
            throw new DisplayException(__('This license is not a bulk license.'));
        }

        return $locked;
    }

    /**
     * The same client's single licenses bound to $ip (§8.2): allowed, but both would be paid for. Compared as addresses
     * (a WHMCS import may spell an IPv6 one otherwise), on the client's licenses that have bindings at all.
     *
     * @return list<string>
     */
    private static function singlesOf(License $bulk, string $ip): array
    {
        $clientId = $bulk->service?->client_id;
        if ($clientId === null) {
            return [];
        }

        return License::query()->where('id', '!=', $bulk->id)->whereNotNull('valid_ip')
            ->whereHas('service', fn ($query) => $query->where('client_id', $clientId))
            ->whereIn('status', [License::STATUS_ACTIVE, License::STATUS_REISSUED, License::STATUS_SUSPENDED])
            ->with('service.product.settings')->get()
            ->filter(fn (License $single) => !Licenses::isBulk($single->service)
                && in_array($ip, array_map(fn ($one) => LicenseIp::normalize($one), preg_split('/[\s,]+/', (string) $single->valid_ip) ?: []), true))
            ->map(fn (License $single) => __(':ip is also on license :service (service #:id): both are paid for until it ends.',
                ['ip' => $ip, 'service' => $single->license_key, 'id' => $single->service_id]))
            ->values()->all();
    }
}
