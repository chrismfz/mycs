<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "Nobody grants what they do not have" on the admin API too (audit sweep KR-5, AUTH-1). The PermissionGuard work
 * covered the admin panel; through the API, staff with users/roles rights could give themselves the '*' role, or
 * change a full admin's password or email, or delete them.
 */
class AdminUserPermissionGuardTest extends TestCase
{
    use RefreshDatabase;

    private const STAFF_PERMISSIONS = ['admin.users.viewAny', 'admin.users.update', 'admin.users.create', 'admin.users.delete', 'admin.roles.update', 'admin.api_keys.create'];

    private const KEY_PERMISSIONS = ['admin.users.viewAny', 'admin.users.update', 'admin.users.create', 'admin.users.delete', 'admin.roles.update'];

    private Role $adminRole;

    private Role $staffRole;

    private User $admin;

    private User $staff;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::create(['name' => 'Test full admin', 'permissions' => ['*']]);
        $this->staffRole = Role::create(['name' => 'Test staff', 'permissions' => self::STAFF_PERMISSIONS]);
        $this->admin = User::factory()->create(['role_id' => $this->adminRole->id]);
        $this->staff = User::factory()->create(['role_id' => $this->staffRole->id]);
        $this->customer = User::factory()->create(['role_id' => null]);
    }

    private function key(?User $owner): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create([
            'name' => 'test', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'permissions' => self::KEY_PERMISSIONS, 'user_id' => $owner?->id,
        ]);

        return $token;
    }

    public function test_staff_cannot_give_themselves_the_full_admin_role(): void
    {
        $this->withToken($this->key($this->staff))
            ->putJson(route('api.v1.admin.users.update', $this->staff), ['role_id' => $this->adminRole->id])
            ->assertForbidden();

        $this->assertSame($this->staffRole->id, $this->staff->fresh()->role_id);
    }

    public function test_staff_cannot_change_a_full_admins_password(): void
    {
        $this->withToken($this->key($this->staff))
            ->putJson(route('api.v1.admin.users.update', $this->admin), ['password' => 'taken-over-123'])->assertForbidden();

        $this->assertFalse(Hash::check('taken-over-123', $this->admin->fresh()->password));
    }

    public function test_staff_cannot_change_a_full_admins_email(): void
    {
        $this->withToken($this->key($this->staff))
            ->putJson(route('api.v1.admin.users.update', $this->admin), ['email' => 'mine@example.test'])->assertForbidden();

        $this->assertNotSame('mine@example.test', $this->admin->fresh()->email);
    }

    public function test_staff_cannot_delete_a_full_admin(): void
    {
        $this->withToken($this->key($this->staff))
            ->deleteJson(route('api.v1.admin.users.destroy', $this->admin))->assertForbidden();

        $this->assertNotNull($this->admin->fresh());
    }

    public function test_staff_cannot_strip_a_full_admins_role(): void
    {
        $this->withToken($this->key($this->staff))
            ->putJson(route('api.v1.admin.users.update', $this->admin), ['role_id' => null])->assertForbidden();

        $this->assertSame($this->adminRole->id, $this->admin->fresh()->role_id);
    }

    public function test_a_role_id_that_is_not_an_id_is_refused_not_a_500(): void
    {
        $this->withToken($this->key($this->staff))
            ->putJson(route('api.v1.admin.users.update', $this->customer), ['role_id' => [$this->adminRole->id]])
            ->assertForbidden();

        $this->assertNull($this->customer->fresh()->role_id);
    }

    public function test_staff_cannot_create_a_full_admin(): void
    {
        $this->withToken($this->key($this->staff))
            ->postJson(route('api.v1.admin.users.store'), ['email' => 'new@example.test', 'password' => 'password123', 'role_id' => $this->adminRole->id])
            ->assertForbidden();

        $this->assertNull(User::query()->where('email', 'new@example.test')->first());
    }

    public function test_staff_still_manage_customers_and_grant_what_they_have(): void
    {
        $key = $this->key($this->staff);

        $this->withToken($key)->putJson(route('api.v1.admin.users.update', $this->customer), ['first_name' => 'Renamed'])->assertOk();
        $this->withToken($key)->putJson(route('api.v1.admin.users.update', $this->customer), ['role_id' => $this->staffRole->id])->assertOk();

        $this->assertSame($this->staffRole->id, $this->customer->fresh()->role_id);
    }

    public function test_a_full_admin_key_can_change_anyone(): void
    {
        $this->withToken($this->key($this->admin))
            ->putJson(route('api.v1.admin.users.update', $this->staff), ['role_id' => $this->adminRole->id])
            ->assertOk();

        $this->assertSame($this->adminRole->id, $this->staff->fresh()->role_id);
    }

    public function test_a_key_without_an_owner_acts_on_customers_only_and_grants_no_role(): void
    {
        $key = $this->key(null);

        $this->withToken($key)->putJson(route('api.v1.admin.users.update', $this->customer), ['first_name' => 'Renamed'])->assertOk();
        $this->withToken($key)->putJson(route('api.v1.admin.users.update', $this->staff), ['first_name' => 'Renamed'])->assertForbidden();
        $this->withToken($key)->putJson(route('api.v1.admin.users.update', $this->customer), ['role_id' => $this->staffRole->id])->assertForbidden();
        $this->withToken($key)->deleteJson(route('api.v1.admin.users.destroy', $this->admin))->assertForbidden();
        $this->withToken($key)->postJson(route('api.v1.admin.users.store'), ['email' => 'n@example.test', 'password' => 'password123', 'role_id' => $this->staffRole->id])->assertForbidden();

        $this->assertNull($this->customer->fresh()->role_id);
    }
}
