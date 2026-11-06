<?php

namespace Tests\Feature;

use App\Models\InvoiceTransaction;
use App\Models\Role;
use App\Models\Staff;
use App\Services\Whmcs\RolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Review of #649 (WG-5): a WHMCS role must never come out of RolePermissions with more power than it had in WHMCS.
 * Each case is a WHMCS permission mapped to a MyCS key that also guards something WHMCS kept under another permission.
 */
class Review649RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    /** nixpal's live Sales Operator (as in WhmcsRolePermissionsTest): has 30 «Edit Transaction», not 92 «Refund». */
    private const NIXPAL_SALES = '1,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,49,50,51,52,71,73,85,98,99,101,104,105,110,120,123,124,125,126,128,129,145,149,158,159';

    private function staffWith(array $permIds): Staff
    {
        $role = Role::query()->create(['name' => 'R' . uniqid(), 'permissions' => RolePermissions::map($permIds)['permissions']]);

        return Staff::factory()->create(['role_id' => $role->id]);
    }

    /**
     * R649-1: `admin.invoice_transactions.update` is the refund permission in MyCS (the Refund action on an invoice's
     * payments, «cancel with refund» on an order); MyCS has no edit of a transaction. «Edit Transaction» (30) maps to
     * it, so the live nixpal Sales Operator, who has 30 but not «Refund Invoice Payments» (92), can refund through the
     * gateway: money out that WHMCS did not let the role send.
     */
    public function test_edit_transaction_does_not_give_refunds(): void
    {
        $this->assertNotContains('admin.invoice_transactions.update', RolePermissions::map([30])['permissions']);

        $sales = $this->staffWith(explode(',', self::NIXPAL_SALES));
        $this->assertFalse($sales->can('update', new InvoiceTransaction), 'the live Sales Operator never had «Refund Invoice Payments»');
    }

    /**
     * R649-2: `admin.users.update`/`create` set a client login's email, password and 2FA (UserResource), WHMCS 8's
     * «Manage Users» (151). «Edit Clients Details» (9) / «Add New Client» (8) map to them, so the live nixpal Sales
     * Operator (8, 9, no 151) can take over a customer's login by its password.
     */
    public function test_client_details_do_not_give_the_client_logins(): void
    {
        $sales = RolePermissions::map(explode(',', self::NIXPAL_SALES))['permissions'];
        $this->assertNotContains('admin.users.update', $sales, '«Edit Clients Details» is not «Manage Users» (151)');
        $this->assertNotContains('admin.users.create', $sales, '«Add New Client» is not «Manage Users» (151)');
    }

    /**
     * R649-3: `admin.widgets.bank_totals` is the bank, Stripe and PayPal balances, a permission of its own (owner
     * 2026-10-24) that WHMCS has as «View Gateway Balances» (154, mapped to it). «View Income Totals» (94) gives it too.
     */
    public function test_income_totals_do_not_give_the_gateway_balances(): void
    {
        $this->assertNotContains('admin.widgets.bank_totals', RolePermissions::map([94])['permissions']);
    }

    /**
     * R649-4: `admin.credits.create` is the admin API's «create a credit balance» (CreateCreditRequest: any amount, no
     * payment), not an Add Funds invoice the customer pays. «Create Add Funds Invoice» (149) alone must not give free
     * credit (an API key with it can be handed out by a role that also has «API Access»).
     */
    public function test_add_funds_invoice_does_not_give_free_credit(): void
    {
        $this->assertNotContains('admin.credits.create', RolePermissions::map([149])['permissions']);
    }
}
