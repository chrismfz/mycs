<?php

namespace App\Policies;

use App\Models\MailcowDomain;
use App\Models\Staff;
use App\Models\User;

/** A mailcow server's domains belong to it: the servers' permissions (mapping one changes nothing on the server). */
class MailcowDomainPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.servers.viewAny');
    }

    public function view(User|Staff $user, MailcowDomain $domain): bool
    {
        return $user->hasPermission('admin.servers.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return false; // read from the server only
    }

    public function update(User|Staff $user, MailcowDomain $domain): bool
    {
        return $user->hasPermission('admin.servers.update');
    }

    public function delete(User|Staff $user, MailcowDomain $domain): bool
    {
        return $user->hasPermission('admin.servers.update');
    }
}
