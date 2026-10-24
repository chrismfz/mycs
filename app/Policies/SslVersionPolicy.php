<?php

namespace App\Policies;

use App\Models\SslVersion;
use App\Models\Staff;
use App\Models\User;

/** An SSL service's keys and certificates (docs/ssl-vault.md): listed with the service's view permission; its key needs SslVault::KEY_PERMISSION. */
class SslVersionPolicy extends BasePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.services.viewAny');
    }

    public function view(User|Staff $user, SslVersion $version): bool
    {
        return $this->adminPermission($user, 'admin.services.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function update(User|Staff $user, SslVersion $version): bool
    {
        return false;
    }

    public function delete(User|Staff $user, SslVersion $version): bool
    {
        return false;
    }

    public function deleteAny(User|Staff $user): bool
    {
        return false;
    }
}
