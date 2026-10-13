<?php

namespace App\Policies;

use App\Models\PaymentGatewayEvent;
use App\Models\User;

/** The gateway log is read only (docs/money-trail.md §4): written by GatewayLog, never by staff. */
class PaymentGatewayEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admin.gateway_events.viewAny');
    }

    public function view(User $user, PaymentGatewayEvent $event): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PaymentGatewayEvent $event): bool
    {
        return false;
    }

    public function delete(User $user, PaymentGatewayEvent $event): bool
    {
        return false;
    }
}
