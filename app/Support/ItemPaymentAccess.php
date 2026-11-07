<?php

namespace App\Support;

use App\Livewire\Services\Show as ServiceShow;
use App\Models\ClientUser;
use App\Models\Domain;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Where a service's or domain's own payment method (ItemPaymentMethod) is chosen in the client area, and by whom: its page
 * and the table on «Payment methods» (docs/pay-flow-ux.md, PR 6b) ask the same. Choosing which saved method charges it is
 * also the money permission's in «Login as client» (SA-5): each caller asks Actor::staffMayAs('pay') before it changes.
 */
final class ItemPaymentAccess
{
    /** It has a payment method to choose: a recurring service, active or suspended, not paid by a subscription; a domain billed for renewal. */
    public static function offersChoice(Service|Domain $item): bool
    {
        if ($item instanceof Domain) {
            return in_array($item->status, RenewalBilling::BILLED_DOMAINS, true);
        }

        return $item->plan?->type === 'recurring' && in_array($item->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)
            && (string) $item->subscription_id === '';
    }

    /**
     * A login that manages it (its policy) and pays its account's invoices. Staff in «Login as client»: a service, as
     * ServiceShow::loginMay (`invoices`); a domain, with `admin.domains.update`.
     */
    public static function mayChange(Service|Domain $item): bool
    {
        if (!Gate::allows('update', $item)) {
            return false;
        }
        if ($item instanceof Service) {
            return ServiceShow::loginMay($item, 'invoices');
        }
        if (Actor::impersonating()) {
            $actor = Actor::current();

            return $actor instanceof Staff && $actor->hasPermission('admin.domains.update');
        }
        $user = Auth::guard('web')->user();

        return $user instanceof User && (bool) ClientUser::liveGrant($user, $item->client_id)?->allows('invoices'); // never a closed account (ADV-A6-1)
    }
}
