<?php

namespace App\Policies;

use App\Models\PaymentGroup;
use App\Models\Staff;
use App\Models\User;

class PaymentGroupPolicy extends BasePolicy
{
    /** The account's own Mass Pay group (PR F2), for a login that may pay its invoices. */
    public function view(User|Staff $user, PaymentGroup $paymentGroup): bool
    {
        return $this->adminPermission($user, 'admin.invoices.viewAny', $this->owns($user, $paymentGroup->client_id, 'invoices'));
    }
}
