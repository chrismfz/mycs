<?php

namespace App\Services\Cpanel;

use App\Models\CpanelAccount;
use App\Models\Service;
use MyCS\Extensions\Servers\CPanel\CPanel;

/**
 * Hosting Accounts, «does the account have what we bill» (ROADMAP, Admin; owner 2026-11-03: a client asks for «a little more
 * space for a while» and it is forgotten). Reads only what the last read of the server and our own records say; never calls a
 * server.
 *
 * Expected disk = the account's package's quota (WHM's getpkginfo at the last read) + the extra we bill (the option
 * «extra_disk_gb» of the mapped service, in steps of GB). The account's own limit is what listaccts said. Findings, worst first:
 *
 * - billed_not_applied: the server has less than package + billed extra (we bill a space the client does not have)
 * - extra_not_billed: more than package + billed extra (given, or given and forgotten)
 * - below_package: less than the package says, with nothing billed
 * - plan_differs: another package on the server than the service's (a «MyIP_Starter-20GB» for «MyIP_Starter»)
 * - bandwidth_differs: the account's bandwidth limit is not its package's
 *
 * Nothing to check (null): no live service on the account (not mapped, internal, ended, gone), or the package not read. A
 * package without a disk limit cannot be compared: «not_checked» when nothing else was found.
 */
final class AccountCheck
{
    public const OK = 'ok';

    public const BILLED_NOT_APPLIED = 'billed_not_applied';

    public const EXTRA_NOT_BILLED = 'extra_not_billed';

    public const BELOW_PACKAGE = 'below_package';

    public const PLAN_DIFFERS = 'plan_differs';

    public const BANDWIDTH_DIFFERS = 'bandwidth_differs';

    public const NOT_CHECKED = 'not_checked';

    /** Worst first. */
    public const FINDINGS = [self::BILLED_NOT_APPLIED, self::EXTRA_NOT_BILLED, self::BELOW_PACKAGE, self::PLAN_DIFFERS, self::BANDWIDTH_DIFFERS];

    public const LABELS = [
        self::OK => 'OK',
        self::BILLED_NOT_APPLIED => 'Billed, not applied',
        self::EXTRA_NOT_BILLED => 'Extra not billed',
        self::BELOW_PACKAGE => 'Below the package',
        self::PLAN_DIFFERS => 'Package differs',
        self::BANDWIDTH_DIFFERS => 'Bandwidth differs',
        self::NOT_CHECKED => 'Not checked',
    ];

    /** A difference smaller than this is rounding (WHM keeps whole MB), not a finding. */
    public const TOLERANCE_MB = 100;

    /**
     * @return array{status: string, mb: int|null}|null mb: the disk the server has more (+) or less (-) than expected, when that is the finding
     */
    public static function for(CpanelAccount $account): ?array
    {
        $service = $account->service;
        if ($account->gone_at !== null || $account->internal || $service === null
            || !in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true) || $account->plan_disk_mb === null) {
            return null;
        }

        $found = [];
        $package = self::package($service);
        if ($package !== '' && $account->plan !== null && !self::samePackage($account->plan, $package)) {
            $found[self::PLAN_DIFFERS] = null;
        }

        $comparable = $account->plan_disk_mb > 0;
        if ($comparable) {
            $expected = $account->plan_disk_mb + self::billedExtraGb($service) * 1024;
            // No limit on the server for a package that has one: more than anything billed.
            $diff = $account->disk_limit_mb === null ? null : $account->disk_limit_mb - $expected;
            if ($account->disk_limit_mb === null || $diff > self::TOLERANCE_MB) {
                $found[self::EXTRA_NOT_BILLED] = $diff;
            } elseif ($diff < -self::TOLERANCE_MB) {
                $found[self::shortfall($service)] = $diff;
            }
        }

        if ($account->bandwidth_used_mb !== null && $account->plan_bandwidth_mb !== null && self::bandwidthDiffers($account)) {
            $found[self::BANDWIDTH_DIFFERS] = null;
        }

        foreach (self::FINDINGS as $status) {
            if (array_key_exists($status, $found)) {
                return ['status' => $status, 'mb' => $found[$status]];
            }
        }

        return ['status' => $comparable ? self::OK : self::NOT_CHECKED, 'mb' => null];
    }

    /** Keeps what for() found with the account (null when there is nothing to check). */
    public static function apply(CpanelAccount $account): void
    {
        // The mapping may have changed since the service was loaded.
        $account->unsetRelation('service')->load(['service.properties', 'service.product.settings']);
        $result = self::for($account);
        $account->forceFill(['limits_check' => $result['status'] ?? null, 'limits_check_mb' => $result['mb'] ?? null,
            'limits_checked_at' => $result === null ? null : now()]);
    }

    /** «+10 GB more than its package and what we bill», for the list's hover; null when there is no finding to say. */
    public static function explain(CpanelAccount $account): ?string
    {
        $mb = $account->limits_check_mb;
        $gb = $mb === null ? null : round(abs($mb) / 1024, 1) . ' GB';

        return match ($account->limits_check) {
            self::EXTRA_NOT_BILLED => $gb === null ? 'No disk limit on the server, the package has one' : "{$gb} more disk than its package and what we bill",
            self::BILLED_NOT_APPLIED => "{$gb} less disk than its package and what we bill",
            self::BELOW_PACKAGE => "{$gb} less disk than its package",
            self::PLAN_DIFFERS => 'The package on the server is not the service\'s',
            self::BANDWIDTH_DIFFERS => 'The bandwidth limit is not its package\'s',
            self::NOT_CHECKED => 'The package has no disk limit: nothing to compare',
            default => null,
        };
    }

    /** Less disk than expected: billed and not applied when we bill an extra, else simply below the package. */
    private static function shortfall(Service $service): string
    {
        return self::billedExtraGb($service) > 0 ? self::BILLED_NOT_APPLIED : self::BELOW_PACKAGE;
    }

    /** The extra GB we bill: the service's «extra_disk_gb» option, as the module reads it. */
    public static function billedExtraGb(Service $service): int
    {
        return CPanel::gigabytes($service->properties->pluck('value', 'key')->get('extra_disk_gb') ?? 0);
    }

    /** The service's package name as stored: its own property, else the product's. */
    public static function package(Service $service): string
    {
        $own = $service->properties->pluck('value', 'key')->get('package');

        return trim((string) ($own ?? $service->product?->settings->pluck('value', 'key')->get('package') ?? ''));
    }

    /** A reseller's package is «reseller_package» on WHM and stored without the prefix on the service. */
    public static function samePackage(string $onServer, string $stored): bool
    {
        $onServer = strtolower(trim($onServer));
        $stored = strtolower(trim($stored));

        return $onServer === $stored || str_ends_with($onServer, '_' . $stored);
    }

    private static function bandwidthDiffers(CpanelAccount $account): bool
    {
        $package = $account->plan_bandwidth_mb;
        $limit = $account->bandwidth_limit_mb;
        if ($package === 0) {
            return $limit !== null;
        }

        return $limit === null || abs($limit - $package) > self::TOLERANCE_MB;
    }
}
