<?php

namespace App\Policies;

use App\Models\MoneyEvent;
use App\Models\Staff;
use App\Models\User;

/**
 * The money journal is read only (docs/money-trail.md §1): staff who see credits or transactions may read it, nobody
 * writes it through a policy. Without this policy Filament would let any staff member in (MT-1d code review).
 */
class MoneyEventPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.credits.viewAny') || $user->hasPermission('admin.invoice_transactions.viewAny');
    }

    public function view(User|Staff $user, MoneyEvent $event): bool
    {
        return $this->viewAny($user);
    }

    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function update(User|Staff $user, MoneyEvent $event): bool
    {
        return false;
    }

    public function delete(User|Staff $user, MoneyEvent $event): bool
    {
        return false;
    }
}
