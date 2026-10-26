<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\User;
use App\Support\Actor;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Staff in «Login as client» do there only what they may do in the admin (security audit SA-5): seeing the account
 * stays open, acting on it (a service's password or panel, the customer's money, the account's details) needs the
 * admin permission the same change needs there.
 */
class ImpersonationPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
    }

    /** @param  list<string>  $permissions */
    private function impersonate(array $permissions): void
    {
        session(['impersonating' => $this->owner->id, CurrentClient::SESSION_KEY => $this->owner->client()->id, CurrentClient::IMPERSONATING_KEY => $this->owner->client()->id]);
        Auth::guard('staff')->setUser(Staff::factory()->withPermissions(array_merge(['admin.users.impersonate'], $permissions))->create());
        Auth::guard('web')->setUser($this->owner);
    }

    public function test_staff_with_only_the_impersonation_right_see_but_do_not_act(): void
    {
        $this->impersonate([]);

        foreach (['products', 'domains', 'invoices', 'tickets', 'emails'] as $seeing) {
            $this->assertTrue(CurrentClient::allows($seeing), $seeing);
        }
        foreach (['manageproducts', 'productsso', 'profile', 'contacts', 'orders', 'pay'] as $acting) {
            $this->assertFalse(Actor::staffMayAs($acting), $acting);
        }
        $this->assertFalse(CurrentClient::allows('manageproducts'));
        $this->assertFalse(Actor::staffMayAs('not-a-permission'));
    }

    public function test_staff_with_the_admin_permission_act_as_before(): void
    {
        $this->impersonate(['admin.services.update', 'admin.services.sso', 'admin.invoices.update', 'admin.clients.update']);

        $this->assertTrue(CurrentClient::allows('manageproducts'));
        $this->assertTrue(CurrentClient::allows('productsso'));
        $this->assertTrue(CurrentClient::allows('profile'));
        $this->assertTrue(Actor::staffMayAs('pay'));
    }

    public function test_a_customer_is_not_staff(): void
    {
        Auth::guard('web')->setUser($this->owner);

        $this->assertFalse(Actor::staffMayAs(null));
    }
}
