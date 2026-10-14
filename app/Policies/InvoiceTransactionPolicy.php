<?php

namespace App\Policies;

use App\Models\InvoiceTransaction;
use App\Models\Staff;
use App\Models\User;

class InvoiceTransactionPolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.invoice_transactions.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, InvoiceTransaction $invoiceTransaction): bool
    {
        return $this->adminPermission($user, 'admin.invoice_transactions.viewAny');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.invoice_transactions.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, InvoiceTransaction $invoiceTransaction): bool
    {
        return $this->adminPermission($user, 'admin.invoice_transactions.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, InvoiceTransaction $invoiceTransaction): bool
    {
        return $user->hasPermission('admin.invoice_transactions.delete');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.invoice_transactions.deleteAny');
    }
}
