<?php

namespace App\Services\Licensing;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\License;
use App\Models\LicenseAccessLog;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * A service's license, as WHMCS's Licensing Addon keeps it (docs/history/blesta-modules/licensing.md §3.1):
 * issued when the service is created (key = prefix + hex, as today), and its status following the service's
 * (suspended → Suspended, unsuspended → Active, terminated or cancelled → Expired). A reissue clears the bindings: the
 * next check binds the license to the caller's IP again.
 */
class Licenses
{
    /** WHMCS addon defaults (nixpal: maxreissues 10, logprune 90). */
    public const MAX_REISSUES = 10;

    public const LOG_DAYS = 90;

    /**
     * The fields a license's product reports (its «custom_fields» setting, e.g. nixpal's «comments»), as the customer
     * filled them: [name => value], empty ones left out. Read from the service's relations (loaded once for a list).
     *
     * @return array<string, string>
     */
    public static function reportedFields(Service $service): array
    {
        if ($service->license === null || $service->product === null) {
            return [];
        }
        $names = MycsLicenseRepository::fieldNames((string) (ExtensionHelper::settingsToArray($service->product->settings)['custom_fields'] ?? ''));
        if ($names === []) {
            return [];
        }

        return $service->properties->whereIn('key', $names)->filter(fn ($property) => trim((string) $property->value) !== '')
            ->mapWithKeys(fn ($property) => [(string) ($property->name ?: $property->key) => (string) $property->value])->all();
    }

    /**
     * The service's license, made on its first creation (an existing one is kept, with its key, and made Active).
     *
     * @param  array<string, mixed>  $settings  the product's NixpalLicensing settings
     */
    public static function issue(Service $service, array $settings): License
    {
        return DB::transaction(function () use ($service, $settings) {
            $license = License::query()->where('service_id', $service->id)->lockForUpdate()->first();
            if ($license !== null) {
                // Made Active again only for a service that runs (review ADV-L2b): «Create» on a cancelled one revives nothing.
                if ($license->status === License::STATUS_SUSPENDED && $service->status === Service::STATUS_ACTIVE) {
                    $license->update(['status' => License::STATUS_ACTIVE]);
                }
                self::keyAsDomain($service, $license->license_key);

                return $license;
            }

            $license = License::query()->create([
                'service_id' => $service->id,
                'license_key' => self::newKey((string) ($settings['key_prefix'] ?? ''), (int) ($settings['key_length'] ?? 16)),
                'status' => License::STATUS_ACTIVE,
                'reissues' => 0,
            ]);
            self::keyAsDomain($service, $license->license_key);

            return $license;
        });
    }

    /**
     * The key is the service's domain, as WHMCS keeps it (1.340 of nixpal's 1.341 licenses): shown under the product in
     * every list, to staff and the customer, and found by the admin search. A domain the service has is kept.
     */
    private static function keyAsDomain(Service $service, string $key): void
    {
        if ($service->domain !== null && $service->domain !== '') {
            return;
        }
        Service::query()->whereKey($service->id)->where(fn ($q) => $q->whereNull('domain')->orWhere('domain', ''))->update(['domain' => $key]);
        $service->setAttribute('domain', $key);
        $service->syncOriginalAttribute('domain');
    }

    /** The license follows its service: Suspended, Active again, or Expired. Nothing when it has none. */
    public static function setStatus(Service $service, string $status): ?License
    {
        $license = License::query()->where('service_id', $service->id)->first();
        // Expired is final, and Active needs a running service (review ADV-L2): an «Unsuspend» of a cancelled service
        // does not revive its license.
        if ($license !== null && ($license->status === License::STATUS_EXPIRED
            || ($status === License::STATUS_ACTIVE && $service->fresh()?->status !== Service::STATUS_ACTIVE))) {
            return $license;
        }
        if ($license !== null && $license->status !== $status) {
            // An unsuspend of a license waiting to be bound again keeps it Reissued.
            if (!($status === License::STATUS_ACTIVE && $license->status === License::STATUS_REISSUED)) {
                $license->update(['status' => $status]);
            }
        }

        return $license;
    }

    /** Whether the customer may reissue it now (WHMCS: Active, the product allows it, under the limit). */
    public static function canReissue(License $license): bool
    {
        $settings = ExtensionHelper::settingsToArray($license->service?->product->settings ?? collect());

        return $license->status === License::STATUS_ACTIVE
            && filter_var($settings['allow_reissue'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && $license->reissues < self::MAX_REISSUES;
    }

    /**
     * Reissue: the bindings cleared, status Reissued, one more reissue; the next check binds it again (Zlic). Staff may
     * reissue whatever the limit ($byStaff).
     */
    public static function reissue(License $license, string $ip, bool $byStaff = false): void
    {
        DB::transaction(function () use ($license, $ip, $byStaff) {
            $locked = License::query()->whereKey($license->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('service', $license->service);
            if (!$byStaff && !self::canReissue($locked)) {
                throw new DisplayException(__('This license cannot be reissued.'));
            }
            if ($byStaff && !in_array($locked->status, [License::STATUS_ACTIVE, License::STATUS_REISSUED], true)) {
                throw new DisplayException(__('Only an active license can be reissued.'));
            }
            $locked->update([
                'status' => License::STATUS_REISSUED,
                'valid_domain' => null,
                'valid_ip' => null,
                'valid_directory' => null,
                'reissues' => $locked->reissues + 1,
            ]);
            LicenseAccessLog::query()->create(['license_id' => $locked->id, 'ip' => $ip, 'message' => 'License Reissued', 'created_at' => now()]);
            $license->setRawAttributes($locked->getAttributes(), true);
        });
        // Its failures from where it ran before (an IP mismatch in a loop) never hold back the bind that follows (#254).
        app(ZlicLimiter::class)->forgetKey((string) $license->license_key);
    }

    /** prefix + lowercase hex, as every key today (ZCAgent-, ZCNode-, ZCWeb-, ZCFirewall- + 16 hex), unique. */
    private static function newKey(string $prefix, int $length): string
    {
        $length = max(8, min(64, $length));
        do {
            $key = $prefix . substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
        } while (License::query()->where('license_key', $key)->exists());

        return $key;
    }

    /** The access log is kept LOG_DAYS (WHMCS's logprune). */
    public static function pruneLog(): int
    {
        return LicenseAccessLog::query()->where('created_at', '<', now()->subDays(self::LOG_DAYS))->delete();
    }
}
