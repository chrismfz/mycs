<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use Illuminate\Support\Carbon;

/**
 * A domain's life after its expiry (docs/domains.md D-8, D-18, D5a), as WHMCS's «Domain Expiry»: by the end dates of its
 * grace and redemption, the registrar's when it reports them, else its expiry plus its TLD's (or its own) days. Never past what is known: no grace days, only expired; grace
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
        // By the end dates of its stages (D5a): the registrar's when it gave them, else its expiry plus the TLD's (or its
        // own) days; a stage of 0 days does not exist.
        $graceEnds = $domain->graceEndsOn();
        $redemptionEnds = $domain->redemptionEndsOn();
        $known = $domain->graceDays() !== null && $domain->redemptionDays() !== null;
        $status = match (true) {
            $graceEnds !== null && $today->lte($graceEnds) => DomainStatus::Grace,
            $redemptionEnds !== null && $today->lte($redemptionEnds) => DomainStatus::Redemption,
            // Past the stages it has: the registrar with an API says «deleted» itself (it may still restore by hand); without
            // one, the dates do, but only when both stages are known: never past what is known.
            $this->sync->isSyncable($domain) => $domain->status === DomainStatus::Redemption ? DomainStatus::Redemption : DomainStatus::Expired,
            $known || $domain->redemption_ends_on !== null => DomainStatus::Deleted,
            default => DomainStatus::Expired,
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
