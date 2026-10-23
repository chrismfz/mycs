<?php

namespace MyCS\Extensions\Others\Affiliates\Policies;

use App\Models\Staff;
use App\Models\User;
use App\Policies\BasePolicy;
use MyCS\Extensions\Others\Affiliates\Models\AffiliateOrder;

/**
 * An affiliate's orders, on the affiliate's page: seen with the affiliate, removed by whoever may change it (the admin's
 * strict authorization needs a policy for every model it shows: review of #368).
 */
class AffiliateOrderPolicy extends BasePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.affiliates.view');
    }

    public function view(User|Staff $user, AffiliateOrder $order): bool
    {
        return $user->hasPermission('admin.affiliates.view');
    }

    public function delete(User|Staff $user, AffiliateOrder $order): bool
    {
        return $user->hasPermission('admin.affiliates.update');
    }

    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.affiliates.update');
    }
}
