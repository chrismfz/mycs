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
 * Staff's word on it (owner 2026-11-04): a temporary extra (+N GB until a date) is expected until its date and is
 * «temporary_extra» while the account has it; after the date the space still there is «extra_to_take_back» (a reminder: MyCS
 * never changes the server), and once it is taken back the temporary extra is forgotten. A finding approved with a note is
 * «approved» while what was approved stays the same (the limits, the packages, what we bill: approvedState()); anything else
 * changing brings the finding back and drops the approval.
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

    public const EXTRA_TO_TAKE_BACK = 'extra_to_take_back';

    public const TEMPORARY_EXTRA = 'temporary_extra';

    public const APPROVED = 'approved';

    /** Worst first. */
    public const FINDINGS = [self::BILLED_NOT_APPLIED, self::EXTRA_NOT_BILLED, self::EXTRA_TO_TAKE_BACK, self::BELOW_PACKAGE, self::PLAN_DIFFERS, self::BANDWIDTH_DIFFERS];

    /** What staff may approve: a temporary extra that ended is taken back or given again, not approved. */
    public const APPROVABLE = [self::BILLED_NOT_APPLIED, self::EXTRA_NOT_BILLED, self::BELOW_PACKAGE, self::PLAN_DIFFERS, self::BANDWIDTH_DIFFERS];

    public const LABELS = [
        self::OK => 'OK',
        self::BILLED_NOT_APPLIED => 'Billed, not applied',
        self::EXTRA_NOT_BILLED => 'Extra not billed',
        self::BELOW_PACKAGE => 'Below the package',
        self::PLAN_DIFFERS => 'Package differs',
        self::BANDWIDTH_DIFFERS => 'Bandwidth differs',
        self::EXTRA_TO_TAKE_BACK => 'Extra to take back',
        self::TEMPORARY_EXTRA => 'Temporary extra',
        self::APPROVED => 'Approved',
        self::NOT_CHECKED => 'Not checked',
    ];

    /** A difference smaller than this is rounding (WHM keeps whole MB), not a finding. */
    public const TOLERANCE_MB = 100;

    /**
     * @return array{status: string, mb: int|null}|null mb: the disk the server has more (+) or less (-) than expected, when that is the finding
     */
    public static function for(CpanelAccount $account): ?array
    {
        return self::evaluate($account)['result'];
    }

    /**
     * @return array{result: array{status: string, mb: int|null}|null, approved: bool, take_back: bool}
     */
    private static function evaluate(CpanelAccount $account): array
    {
        $service = $account->service;
        if ($account->gone_at !== null || $account->internal || $service === null
            || !in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true) || $account->plan_disk_mb === null) {
            return ['result' => null, 'approved' => false, 'take_back' => false];
        }

        $found = [];
        $package = self::package($service);
        if ($package !== '' && $account->plan !== null && !self::samePackage($account->plan, $package, $account->owner)) {
            $found[self::PLAN_DIFFERS] = null;
        }

        // An approved free extra is an amount, expected as what we bill is (review of #645: a temporary extra beside it, or the
        // limit raised for one, is not a reason to drop it); null approved «no limit» while the server has none.
        $approvedState = $account->approved_check !== null && hash_equals((string) $account->approved_state, self::approvedState($account, $account->approved_check));
        $freeExtra = $approvedState && $account->approved_check === self::EXTRA_NOT_BILLED;
        $freeMb = $freeExtra ? $account->approved_mb : 0;
        if ($freeExtra && $freeMb === null && $account->disk_limit_mb !== null) {
            $freeExtra = false;
            $freeMb = 0;
        }

        $comparable = $account->plan_disk_mb > 0;
        $temporaryInUse = false;
        if ($comparable) {
            $billed = $account->plan_disk_mb + self::billedExtraGb($service) * 1024;
            if ($freeExtra && $freeMb !== null && $account->disk_limit_mb !== null && $account->disk_limit_mb - $billed < $freeMb - self::TOLERANCE_MB) {
                // The free extra was taken back (some or all): nothing left to approve.
                $freeExtra = false;
                $freeMb = 0;
            }
            $base = $billed + (int) $freeMb;
            $temporary = self::temporaryExtraMb($account);
            $ended = self::temporaryExtraEnded($account) ? (int) $account->temporary_extra_mb : 0;
            if ($account->disk_limit_mb === null) {
                // No limit on the server for a package that has one: more than anything billed, unless that was approved.
                if (!$freeExtra) {
                    $found[self::EXTRA_NOT_BILLED] = null;
                }
                if ($ended > 0) {
                    $found[self::EXTRA_TO_TAKE_BACK] = $ended;
                }
            } else {
                $over = $account->disk_limit_mb - $base;
                if ($over - $temporary > self::TOLERANCE_MB) {
                    // An ended temporary extra is to take back up to what was lent; anything more than that was never said.
                    $back = min($over, $ended);
                    if ($back > self::TOLERANCE_MB) {
                        $found[self::EXTRA_TO_TAKE_BACK] = $back;
                    }
                    if ($over - $temporary - $back > self::TOLERANCE_MB) {
                        $found[self::EXTRA_NOT_BILLED] = $over - $temporary - $back;
                    }
                } elseif ($account->disk_limit_mb - $billed < -self::TOLERANCE_MB) {
                    // Measured from what we bill: a temporary extra not given yet (or taken back early) is not a shortfall.
                    $found[self::shortfall($service)] = $account->disk_limit_mb - $billed;
                } else {
                    $temporaryInUse = $temporary > 0 && $over > self::TOLERANCE_MB;
                }
            }
        }

        if ($account->bandwidth_used_mb !== null && $account->plan_bandwidth_mb !== null && self::bandwidthDiffers($account)) {
            $found[self::BANDWIDTH_DIFFERS] = null;
        }

        // Another finding is quiet while nothing it was approved on changed and it is still found; any other one still shows.
        $approved = $freeExtra || ($approvedState && $account->approved_check !== self::EXTRA_NOT_BILLED && array_key_exists($account->approved_check, $found));
        $takeBack = array_key_exists(self::EXTRA_TO_TAKE_BACK, $found);
        foreach (self::FINDINGS as $status) {
            // A free extra is already in what is expected: an extra not billed beside it is more than was approved.
            if (array_key_exists($status, $found) && !($approved && !$freeExtra && $status === $account->approved_check)) {
                return ['result' => ['status' => $status, 'mb' => $found[$status]], 'approved' => $approved, 'take_back' => $takeBack];
            }
        }

        $status = match (true) {
            $temporaryInUse => self::TEMPORARY_EXTRA,
            $approved => self::APPROVED,
            $comparable => self::OK,
            default => self::NOT_CHECKED,
        };
        $mb = match ($status) {
            self::APPROVED => $freeExtra ? $freeMb : $found[$account->approved_check],
            default => null,
        };

        return ['result' => ['status' => $status, 'mb' => $mb], 'approved' => $approved, 'take_back' => $takeBack];
    }

    /** The temporary extra still expected (its date is today or later), in MB. */
    public static function temporaryExtraMb(CpanelAccount $account): int
    {
        return $account->temporary_extra_mb > 0 && $account->temporary_extra_until !== null && !$account->temporary_extra_until->lt(today())
            ? (int) $account->temporary_extra_mb : 0;
    }

    private static function temporaryExtraEnded(CpanelAccount $account): bool
    {
        return $account->temporary_extra_mb > 0 && $account->temporary_extra_until !== null && $account->temporary_extra_until->lt(today());
    }

    /**
     * What an approval is given on: the service, the packages and what we bill, and the limit the finding is about (the disk
     * for a shortfall, the bandwidth for its own; a free extra is its amount, approved_mb, not the limit). A change in any of
     * them is something new to look at.
     */
    public static function approvedState(CpanelAccount $account, string $check): string
    {
        $service = $account->service;
        $limit = match ($check) {
            self::BILLED_NOT_APPLIED, self::BELOW_PACKAGE => $account->disk_limit_mb,
            self::BANDWIDTH_DIFFERS => $account->bandwidth_limit_mb,
            default => null,
        };

        return sha1(json_encode([$check, $account->service_id, $account->plan, $account->plan_disk_mb, $account->plan_bandwidth_mb, $limit,
            $service === null ? null : self::package($service), $service === null ? null : self::billedExtraGb($service)]));
    }

    /** Keeps what for() found with the account (null when there is nothing to check). */
    public static function apply(CpanelAccount $account): void
    {
        // The service the mapping says now (it may have changed since it was loaded), with what the check reads: nothing to load for
        // an account that has none, and what is loaded already is kept (the read goes through many accounts: review of #622).
        if ($account->service_id === null) {
            $account->setRelation('service', null);
        } else {
            if ($account->relationLoaded('service') && $account->service?->id !== $account->service_id) {
                $account->unsetRelation('service');
            }
            $account->loadMissing(['service.properties', 'service.product.settings']);
        }
        // Another service is another client: what staff said of this one's is not theirs (the sync remaps by itself too: review of #645).
        if ($account->exists && $account->isDirty('service_id') && $account->getOriginal('service_id') !== null) {
            self::forget($account);
        }
        ['result' => $result, 'approved' => $approved, 'take_back' => $takeBack] = self::evaluate($account);
        $account->forceFill(['limits_check' => $result['status'] ?? null, 'limits_check_mb' => $result['mb'] ?? null,
            'limits_checked_at' => $result === null ? null : now()]);
        // Until it changes (owner 2026-11-04): an approval that no longer holds is dropped, not kept for a day it might again.
        if ($account->approved_check !== null && $result !== null && !$approved) {
            self::forget($account, temporary: false);
        }
        // An ended temporary extra the account no longer has was taken back: nothing left to remind.
        if ($result !== null && self::temporaryExtraEnded($account) && !$takeBack) {
            self::forget($account, approval: false);
        }
    }

    /** Staff approve what the account shows now (the check's finding), with a note: kept while it holds. */
    public static function approve(CpanelAccount $account, string $note, ?int $staffId): void
    {
        self::apply($account);
        if (!in_array($account->limits_check, self::APPROVABLE, true)) {
            return;
        }
        $account->forceFill(['approved_check' => $account->limits_check, 'approved_state' => self::approvedState($account, $account->limits_check),
            'approved_mb' => $account->limits_check === self::EXTRA_NOT_BILLED ? $account->limits_check_mb : null, 'approved_note' => $note, 'approved_by' => $staffId, 'approved_at' => now()]);
        self::apply($account);
    }

    /** What staff said about an account's check (an approval, a temporary extra), dropped: another mapping is another client. */
    public static function forget(CpanelAccount $account, bool $approval = true, bool $temporary = true): void
    {
        if ($approval) {
            $account->forceFill(['approved_check' => null, 'approved_state' => null, 'approved_mb' => null, 'approved_note' => null, 'approved_by' => null, 'approved_at' => null]);
        }
        if ($temporary) {
            $account->forceFill(['temporary_extra_mb' => null, 'temporary_extra_until' => null, 'temporary_extra_note' => null, 'temporary_extra_by' => null]);
        }
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
            self::EXTRA_TO_TAKE_BACK => "{$gb} of a temporary extra still there: it ended "
                . $account->temporary_extra_until?->addDay()->format('d/m/Y') . self::note($account->temporary_extra_note),
            self::TEMPORARY_EXTRA => '+' . round((int) $account->temporary_extra_mb / 1024, 1) . ' GB until ' . $account->temporary_extra_until?->format('d/m/Y')
                . self::note($account->temporary_extra_note),
            self::APPROVED => 'Approved' . ($account->approvedBy ? ' by ' . $account->approvedBy->name : '') . ' on ' . $account->approved_at?->format('d/m/Y')
                . ': ' . (self::LABELS[$account->approved_check] ?? $account->approved_check) . ($gb === null ? '' : ' ' . $gb) . self::note($account->approved_note),
            self::NOT_CHECKED => 'The package has no disk limit: nothing to compare',
            default => null,
        };
    }

    private static function note(?string $note): string
    {
        return filled($note) ? ' · ' . $note : '';
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

    /**
     * A reseller's package is «reseller_package» on WHM and stored without the prefix on the service: the prefix is the account's
     * owner's, never another (root's «Premium_Starter» is not «Starter»: review of #622).
     */
    public static function samePackage(string $onServer, string $stored, ?string $owner = null): bool
    {
        $onServer = strtolower(trim($onServer));
        $stored = strtolower(trim($stored));
        $owner = strtolower(trim((string) $owner));

        return $onServer === $stored || ($owner !== '' && $owner !== 'root' && $onServer === $owner . '_' . $stored);
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
