<?php

namespace Tests\Feature;

use App\Classes\PermissionGuard;
use App\Http\Middleware\Api\AdminApi;
use App\Http\Middleware\ImpersonateMiddleware;
use App\Models\ApiKey;
use App\Models\Role;
use App\Models\User;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** Nobody grants, or takes over, more than their own role carries (audit-security M5). */
class PermissionGuardTest extends TestCase
{
    use RefreshDatabase;

    private Staff $owner;

    private Staff $support;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $full = Role::create(['name' => 'Owner', 'permissions' => ['*']]);
        $support = Role::create(['name' => 'Support', 'permissions' => [
            'admin.users.viewAny', 'admin.users.update', 'admin.users.impersonate', 'admin.staff.viewAny', 'admin.staff.update',
            'admin.roles.update', 'admin.api_keys.create', 'admin.api_keys.update',
            'admin.tickets.viewAny', 'admin.tickets.update',
        ]]);
        $this->owner = Staff::factory()->create(['role_id' => $full->id]);
        $this->support = Staff::factory()->create(['role_id' => $support->id]);
        $this->client = User::factory()->create();
    }

    public function test_role_permissions_can_only_be_granted_by_who_holds_them(): void
    {
        $this->assertTrue(PermissionGuard::canGrant($this->support, ['admin.tickets.update']));
        $this->assertFalse(PermissionGuard::canGrant($this->support, ['admin.settings.update']));
        $this->assertFalse(PermissionGuard::canGrant($this->support, ['*']));
        $this->assertTrue(PermissionGuard::canGrant($this->owner, ['*', 'admin.settings.update']));
        $this->assertFalse(PermissionGuard::canGrant(null, ['admin.tickets.update']));
        // A customer holds nothing (docs/accounts.md, PR 1).
        $this->assertFalse($this->client->hasPermission('admin.tickets.update'));
    }

    public function test_api_permissions_map_to_role_permissions(): void
    {
        $this->assertTrue(PermissionGuard::canGrantApi($this->support, 'admin.tickets.viewAny'));
        $this->assertTrue(PermissionGuard::canGrantApi($this->support, 'admin.ticket_messages.create'));
        $this->assertFalse(PermissionGuard::canGrantApi($this->support, 'admin.invoices.create'));
        $this->assertFalse(PermissionGuard::canGrantApi($this->support, 'admin.some_extension.thing'));
        $this->assertTrue(PermissionGuard::canGrantApi($this->owner, 'admin.some_extension.thing'));
    }

    public function test_admin_accounts_are_protected_from_lesser_admins(): void
    {
        $this->assertTrue(Gate::forUser($this->support)->allows('update', $this->client));
        $this->assertTrue(Gate::forUser($this->support)->allows('update', $this->support));
        $this->assertFalse(Gate::forUser($this->support)->allows('update', $this->owner));
        $this->assertTrue(Gate::forUser($this->owner)->allows('update', $this->support));
        $this->assertFalse(Gate::forUser($this->support)->allows('update', $this->owner->role));
        $this->assertTrue(Gate::forUser($this->support)->allows('update', $this->support->role));
    }

    public function test_an_api_key_never_does_more_than_its_creator_can(): void
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create([
            'name' => 'old key', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'staff_id' => $this->support->id,
            'permissions' => ['admin.tickets.viewAny', 'admin.invoices.viewAny'],
        ]);
        $request = Request::create('/api/v1/admin/tickets', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        $seen = null;
        (new AdminApi)->handle($request, function (Request $r) use (&$seen) {
            $seen = $r->attributes->get('api_key_permissions');

            return new Response;
        });

        $this->assertSame(['admin.tickets.viewAny'], $seen);
    }

    /** «Login as client» (AUTH-19): customers only, with the permission; the staff member stays logged in as themselves. */
    public function test_login_as_client_needs_the_permission_and_reaches_customers_only(): void
    {
        $request = Request::create('/dashboard');
        $request->setLaravelSession(app('session.store'));
        $next = fn () => new Response;

        // Without the permission: nothing, and the marker goes.
        $plain = Staff::factory()->withPermissions(['admin.users.viewAny'])->create();
        Auth::guard('staff')->setUser($plain);
        session()->put('impersonating', $this->client->id);
        (new ImpersonateMiddleware)->handle($request, $next);
        $this->assertNull(Auth::guard('web')->user());
        $this->assertFalse(session()->has('impersonating'));

        // With it: the client area is the customer's, the staff member still themselves.
        Auth::guard('staff')->setUser($this->support);
        session()->put('impersonating', $this->client->id);
        (new ImpersonateMiddleware)->handle($request, $next);
        $this->assertSame($this->client->id, Auth::guard('web')->id());
        $this->assertTrue(Auth::guard('staff')->user()->is($this->support));
    }
}
