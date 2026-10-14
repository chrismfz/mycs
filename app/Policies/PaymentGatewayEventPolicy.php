<?php

namespace App\Policies;

use App\Models\PaymentGatewayEvent;
use App\Models\Staff;
use App\Models\User;

/** The gateway log is read only (docs/money-trail.md §4): written by GatewayLog, never by staff. */
class PaymentGatewayEventPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.gateway_events.viewAny');
    }

    public function view(User|Staff $user, PaymentGatewayEvent $event): bool
    {
        return $this->viewAny($user);
    }

    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function update(User|Staff $user, PaymentGatewayEvent $event): bool
    {
        return false;
    }

    public function delete(User|Staff $user, PaymentGatewayEvent $event): bool
    {
        return false;
    }
}
