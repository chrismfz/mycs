<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\Staff;
use App\Models\User;

class InvoicePolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.invoices.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, Invoice $invoice): bool
    {
        // A draft is the staff's until it is issued: the customer neither lists, opens nor downloads it (AUTH-6, as WHMCS).
        return $this->adminPermission($user, 'admin.invoices.viewAny', $this->owns($user, $invoice->user_id) && $invoice->status !== Invoice::STATUS_DRAFT);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.invoices.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, ?Invoice $invoice = null): bool
    {
        if ($invoice === null) {
            return $this->adminPermission($user, 'admin.invoices.update');
        }

        $canEdit = $this->adminPermission($user, 'admin.invoices.update', $this->owns($user, $invoice->user_id));

        if (!config('settings.immutable_invoices_enabled', false)) {
            return $canEdit;
        }

        return $canEdit && $invoice->status === Invoice::STATUS_DRAFT;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, Invoice $invoice): bool
    {
        return $user->hasPermission('admin.invoices.delete');
    }

    /**
     * Determine whether the user can delete any models.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.invoices.deleteAny');
    }
}
