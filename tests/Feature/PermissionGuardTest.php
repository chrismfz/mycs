<?php

namespace Tests\Feature;

use App\Classes\PermissionGuard;
use App\Http\Middleware\Api\AdminApi;
use App\Http\Middleware\ImpersonateMiddleware;
use App\Models\ApiKey;
use App\Models\Role;
use App\Models\User;
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

    private User $owner;

    private User $support;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $full = Role::create(['name' => 'Owner', 'permissions' => ['*']]);
        $support = Role::create(['name' => 'Support', 'permissions' => [
            'admin.users.viewAny', 'admin.users.update', 'admin.users.impersonate',
            'admin.roles.update', 'admin.api_keys.create', 'admin.api_keys.update',
            'admin.tickets.viewAny', 'admin.tickets.update',
        ]]);
        $this->owner = User::factory()->create(['role_id' => $full->id]);
        $this->support = User::factory()->create(['role_id' => $support->id]);
        $this->client = User::factory()->create();
    }

    public function test_role_permissions_can_only_be_granted_by_who_holds_them(): void
    {
        $this->assertTrue(PermissionGuard::canGrant($this->support, ['admin.tickets.update']));
        $this->assertFalse(PermissionGuard::canGrant($this->support, ['admin.settings.update']));
        $this->assertFalse(PermissionGuard::canGrant($this->support, ['*']));
        $this->assertTrue(PermissionGuard::canGrant($this->owner, ['*', 'admin.settings.update']));
        $this->assertFalse(PermissionGuard::canGrant($this->client, ['admin.tickets.update']));
    }

    public function test_api_permissions_map_to_role_permissions(): void
    {
        $this->assertTrue(PermissionGuard::canGrantApi($this->support, 'admin.tickets.view'));
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
            'user_id' => $this->support->id,
            'permissions' => ['admin.tickets.view', 'admin.invoices.view'],
        ]);
        $request = Request::create('/api/v1/admin/tickets', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        $seen = null;
        (new AdminApi)->handle($request, function (Request $r) use (&$seen) {
            $seen = $r->attributes->get('api_key_permissions');

            return new Response;
        });

        $this->assertSame(['admin.tickets.view'], $seen);
    }

    public function test_a_lesser_admin_cannot_impersonate_a_greater_one(): void
    {
        Auth::login($this->support);
        $next = fn () => new Response;

        session()->put('impersonating', $this->owner->id);
        (new ImpersonateMiddleware)->handle(Request::create('/dashboard'), $next);
        $this->assertSame($this->support->id, Auth::id());
        $this->assertFalse(session()->has('impersonating'));

        session()->put('impersonating', $this->client->id);
        (new ImpersonateMiddleware)->handle(Request::create('/dashboard'), $next);
        $this->assertSame($this->client->id, Auth::id());
    }
}
