<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use Illuminate\Support\Carbon;

/**
 * A domain's life after its expiry (docs/domains.md D-8, D-18), as WHMCS's «Domain Expiry»: by the days past its expiry
 * and its TLD's (or its own) grace and redemption days. Never past what is known: no grace days, only expired; grace
 * but no redemption days, grace then expired; both, grace then redemption, then deleted only for a registrar without an
 * API (one with an API says so itself, through the sync). Back to active when its expiry moves ahead. Pending orders
 * and ended domains are left alone.
 */
class DomainLifecycle
{
    private const MOVING = [DomainStatus::Active, DomainStatus::Expired, DomainStatus::Grace, DomainStatus::Redemption];

    public function __construct(private readonly DomainSyncService $sync) {}

    /** The status its dates give, or null when they say nothing new. */
    public function next(Domain $domain, ?Carbon $today = null): ?DomainStatus
    {
        $today ??= Carbon::today();
        if ($domain->expires_at === null || !in_array($domain->status, self::MOVING, true)) {
            return null;
        }
        if ($domain->expires_at->gte($today)) {
            return $domain->status === DomainStatus::Active ? null : DomainStatus::Active; // renewed
        }
        $days = (int) $domain->expires_at->diffInDays($today);
        $grace = $domain->graceDays();
        $redemption = $domain->redemptionDays();
        $status = match (true) {
            $grace === null => DomainStatus::Expired,
            $days <= $grace => DomainStatus::Grace,
            $redemption === null => DomainStatus::Expired,
            $days <= $grace + $redemption => DomainStatus::Redemption,
            // Past both: the registrar with an API says «deleted» itself; without one, the dates do.
            $this->sync->isSyncable($domain) => DomainStatus::Redemption,
            default => DomainStatus::Deleted,
        };

        return $status === $domain->status ? null : $status;
    }

    /** Move it, audited; returns the new status or null. */
    public function advance(Domain $domain, ?Carbon $today = null): ?DomainStatus
    {
        $next = $this->next($domain, $today);
        if ($next !== null) {
            $domain->forceFill(['status' => $next])->save();
        }

        return $next;
    }
}
