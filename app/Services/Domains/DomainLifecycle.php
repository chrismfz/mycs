<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use Illuminate\Support\Carbon;

/**
 * A domain's life after its expiry (docs/domains.md D-8, D-9), as WHMCS's «Domain Expiry»: by the days past its expiry
 * and its TLD's (or its own) grace and redemption days: expired → grace → redemption → deleted. Back to active when its
 * expiry moves ahead (a renewal, the registrar's or by hand). Pending orders and staff's ends are left alone; without
 * known days a lapsed domain is only «expired» until the registrar says more.
 */
class DomainLifecycle
{
    private const MOVING = [DomainStatus::Active, DomainStatus::Expired, DomainStatus::Grace, DomainStatus::Redemption, DomainStatus::Deleted];

    /** The status its dates give, or null when they say nothing new. */
    public function next(Domain $domain, ?Carbon $today = null): ?DomainStatus
    {
        $today ??= Carbon::today();
        if ($domain->expires_at === null || !in_array($domain->status, self::MOVING, true)) {
            return null;
        }
        if ($domain->expires_at->gte($today)) {
            // Renewed: a lapsed domain is active again (deleted only by the registrar's own word, through the sync).
            return in_array($domain->status, [DomainStatus::Expired, DomainStatus::Grace, DomainStatus::Redemption], true) ? DomainStatus::Active : null;
        }
        if ($domain->status === DomainStatus::Deleted) {
            return null;
        }
        $days = (int) $domain->expires_at->diffInDays($today);
        $grace = $domain->graceDays();
        $redemption = $domain->redemptionDays();
        $status = match (true) {
            $grace !== null && $days <= $grace => $grace > 0 ? DomainStatus::Grace : DomainStatus::Expired,
            $redemption !== null && $days <= (int) $grace + $redemption => DomainStatus::Redemption,
            $grace !== null || $redemption !== null => DomainStatus::Deleted,
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
