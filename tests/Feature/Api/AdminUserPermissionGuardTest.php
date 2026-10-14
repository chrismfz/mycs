<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "Nobody grants what they do not have" on the admin API too (audit sweep KR-5, AUTH-1). Since staff have their own
 * table (docs/accounts.md, PR 1) the users API is about customers only: no role is given through it, and no staff
 * member is reached through it.
 */
class AdminUserPermissionGuardTest extends TestCase
{
    use RefreshDatabase;

    private const STAFF_PERMISSIONS = ['admin.users.viewAny', 'admin.users.update', 'admin.users.create', 'admin.users.delete', 'admin.roles.update', 'admin.api_keys.create'];

    private const KEY_PERMISSIONS = ['admin.users.viewAny', 'admin.users.update', 'admin.users.create', 'admin.users.delete', 'admin.roles.update'];

    private Role $adminRole;

    private Role $staffRole;

    private Staff $admin;

    private Staff $staff;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::create(['name' => 'Test full admin', 'permissions' => ['*']]);
        $this->staffRole = Role::create(['name' => 'Test staff', 'permissions' => self::STAFF_PERMISSIONS]);
        $this->admin = Staff::factory()->create(['role_id' => $this->adminRole->id]);
        $this->staff = Staff::factory()->create(['role_id' => $this->staffRole->id]);
        $this->customer = User::factory()->create();
    }

    private function key(?Staff $owner, array $permissions = self::KEY_PERMISSIONS): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create([
            'name' => 'test', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'permissions' => $permissions, 'staff_id' => $owner?->id,
        ]);

        return $token;
    }

    public function test_no_role_is_given_to_a_customer_through_the_api(): void
    {
        $this->withToken($this->key($this->admin))
            ->putJson(route('api.v1.admin.users.update', $this->customer), ['role_id' => $this->adminRole->id])
            ->assertUnprocessable();
        $this->withToken($this->key($this->admin))
            ->postJson(route('api.v1.admin.users.store'), ['email' => 'new@example.test', 'password' => 'password123', 'role_id' => $this->adminRole->id])
            ->assertUnprocessable();

        $this->assertNull(User::query()->where('email', 'new@example.test')->first());
        $this->assertFalse(array_key_exists('role_id', $this->customer->fresh()->getAttributes()));
    }

    public function test_the_users_api_never_reaches_a_member_of_staff(): void
    {
        // A staff id with no customer of that id: not found, the staff member untouched.
        $loner = Staff::factory()->create(['role_id' => $this->adminRole->id]);
        User::query()->whereKey($loner->id)->delete();

        $key = $this->key($this->admin);
        $this->withToken($key)->putJson("/api/v1/admin/users/{$loner->id}", ['password' => 'taken-over-123'])->assertNotFound();
        $this->withToken($key)->deleteJson("/api/v1/admin/users/{$loner->id}")->assertNotFound();

        $this->assertFalse(Hash::check('taken-over-123', $loner->fresh()->password));
    }

    public function test_staff_still_manage_customers(): void
    {
        $this->withToken($this->key($this->staff))
            ->putJson(route('api.v1.admin.users.update', $this->customer), ['first_name' => 'Renamed'])->assertOk();

        $this->assertSame('Renamed', $this->customer->fresh()->first_name);
    }

    public function test_a_key_does_no_more_than_its_owner_can_today(): void
    {
        $key = $this->key($this->staff, ['admin.users.update', 'admin.invoices.update']);

        $this->withToken($key)->putJson(route('api.v1.admin.users.update', $this->customer), ['first_name' => 'Renamed'])->assertOk();
        // Not the owner's: dropped from the key.
        $this->assertFalse($this->staff->hasPermission('admin.invoices.update'));
    }

    public function test_a_disabled_members_key_does_nothing(): void
    {
        $key = $this->key($this->staff);
        $this->staff->update(['active' => false]);

        $this->withToken($key)->putJson(route('api.v1.admin.users.update', $this->customer), ['first_name' => 'Renamed'])->assertForbidden();

        $this->assertNotSame('Renamed', $this->customer->fresh()->first_name);
    }

    public function test_a_members_keys_go_with_them(): void
    {
        $this->key($this->staff);
        $this->staff->delete();

        $this->assertSame(0, ApiKey::query()->count());
    }

    public function test_a_key_without_an_owner_acts_on_customers(): void
    {
        $this->withToken($this->key(null))
            ->putJson(route('api.v1.admin.users.update', $this->customer), ['first_name' => 'Renamed'])->assertOk();

        $this->assertSame('Renamed', $this->customer->fresh()->first_name);
    }
}
