<?php

namespace App\Policies;

use App\Models\EkdosiDocument;
use App\Models\Staff;
use App\Models\User;

/**
 * The fiscal documents Ekdosi wrote back (docs/ekdosi-bridge.md, E2): seen by whoever sees invoices; written only by the
 * bridge (Ekdosi owns them), never by staff.
 */
class EkdosiDocumentPolicy extends BasePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.invoices.viewAny');
    }

    public function view(User|Staff $user, EkdosiDocument $document): bool
    {
        return $this->adminPermission($user, 'admin.invoices.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return false;
    }

    public function update(User|Staff $user, EkdosiDocument $document): bool
    {
        return false;
    }

    public function delete(User|Staff $user, EkdosiDocument $document): bool
    {
        return false;
    }

    public function deleteAny(User|Staff $user): bool
    {
        return false;
    }
}
