<?php

namespace App\Support;

use App\Models\Service;
use App\Services\Ops\Scrub;
use Illuminate\Database\Eloquent\Builder;

/**
 * A license's key is its service's domain too (nearly every nixpal license: Licenses::keyAsDomain). Staff who see keys
 * masked (without admin.services.update: security audit SA-11) see such a domain masked as well, and find it by the
 * whole key only: a partial search would tell the hidden middle one character at a time (review of #368).
 */
final class LicenseKeyMask
{
    public static function shown(): bool
    {
        return (bool) auth()->user()?->hasPermission('admin.services.update');
    }

    /** The service's domain as the viewer may see it (load `license` with a list's rows). */
    public static function domain(Service $service): ?string
    {
        $domain = $service->domain;
        if ($domain === null || $domain === '' || self::shown()) {
            return $domain;
        }
        $key = $service->license?->license_key;

        return is_string($key) && strtolower($key) === strtolower($domain) ? Scrub::licenseKey($domain) : $domain;
    }

    /** `domain LIKE %search%`, except that a domain which is its license's key is only matched whole when masked. */
    public static function whereDomainLike(Builder $query, string $search, string $boolean = 'and'): Builder
    {
        if (self::shown()) {
            return $query->where('services.domain', 'like', "%{$search}%", $boolean);
        }

        return $query->where(fn (Builder $match) => $match->whereRaw('lower(services.domain) = ?', [mb_strtolower(trim($search))])
            ->orWhere(fn (Builder $part) => $part->where('services.domain', 'like', "%{$search}%")->whereDoesntHave('license', self::keyIsDomain(...))), boolean: $boolean);
    }

    private static function keyIsDomain(Builder $license): void
    {
        // Case aside, as the screen compares (SQLite's = is case-sensitive, MariaDB's collation is not).
        $license->whereRaw('lower(licenses.license_key) = lower(services.domain)');
    }
}
