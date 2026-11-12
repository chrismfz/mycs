<?php

namespace App\Policies;

use App\Models\GatewayTransaction;
use App\Models\Staff;
use App\Models\User;

/**
 * «PayPal transactions» is written by the daily comparison (gateways:reconcile), never by staff: they only mark a
 * difference known, with a note (update).
 */
class GatewayTransactionPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.gateway_transactions.viewAny');
    }

    public function view(User|Staff $user, GatewayTransaction $transaction): bool
    {
        return $this->viewAny($user);
    }

    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function update(User|Staff $user, GatewayTransaction $transaction): bool
    {
        return $this->viewAny($user) && $user->hasPermission('admin.gateway_transactions.update');
    }

    public function delete(User|Staff $user, GatewayTransaction $transaction): bool
    {
        return false;
    }
}
