<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Staff;
use App\Services\Whmcs\AdminImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WHMCS admins into a live MyCS (app:import-whmcs-admins): matched by email, staff already here untouched, the WHMCS
 * bcrypt hash logs in as it is, roles as the full importer makes them, a dry run writes nothing. Fixtures only.
 */
class WhmcsAdminImportTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string, mixed>> */
    private function admins(): array
    {
        return [
            ['id' => 1, 'firstname' => 'Owner', 'lastname' => 'Here', 'email' => 'Owner@Example.test', 'password' => password_hash('whmcs-owner', PASSWORD_BCRYPT, ['cost' => 10]), 'roleid' => 1, 'rolename' => 'Full Administrator', 'disabled' => 0],
            ['id' => 2, 'firstname' => 'Ada', 'lastname' => 'Admin', 'email' => 'ada@example.test', 'password' => password_hash('whmcs-ada', PASSWORD_BCRYPT, ['cost' => 10]), 'roleid' => 1, 'rolename' => 'Full Administrator', 'disabled' => 0],
            ['id' => 6, 'firstname' => 'Sam', 'lastname' => 'Sales', 'email' => 'sam@example.test', 'password' => password_hash('whmcs-sam', PASSWORD_BCRYPT, ['cost' => 10]), 'roleid' => 2, 'rolename' => 'Sales Operator', 'disabled' => 0],
            ['id' => 7, 'firstname' => 'Old', 'lastname' => 'Md5', 'email' => 'old@example.test', 'password' => md5('x'), 'roleid' => 3, 'rolename' => 'Support Operator', 'disabled' => 1],
        ];
    }

    public function test_admins_land_with_their_whmcs_password_and_staff_here_are_untouched(): void
    {
        $admin = Role::query()->firstOrCreate(['name' => 'admin'], ['permissions' => ['*']]);
        $owner = Staff::factory()->create(['email' => 'owner@example.test', 'password' => 'mine-here', 'role_id' => $admin->id]);
        config(['audit.console' => true]);

        $result = (new AdminImport)->run($this->admins());

        $this->assertSame(['added' => 3, 'existing' => 1, 'without_password' => 1, 'roles' => 2], $result['counts']);
        $this->assertTrue(Hash::check('mine-here', $owner->fresh()->password), 'staff already here keep their password');
        $ada = Staff::query()->where('email', 'ada@example.test')->sole();
        $this->assertTrue(Hash::check('whmcs-ada', $ada->password), 'logs in with the WHMCS password');
        $this->assertSame($admin->id, $ada->role_id);
        $sam = Staff::query()->where('email', 'sam@example.test')->sole();
        $this->assertSame(['Sales Operator', []], [$sam->role->name, $sam->role->permissions]);
        $old = Staff::query()->where('email', 'old@example.test')->sole();
        $this->assertFalse($old->active);
        $this->assertFalse(Hash::check('x', $old->password), 'no md5 login');
        $this->assertStringNotContainsString('$2y$', json_encode(DB::table('audits')->get()) . implode(' ', $result['notes']), 'no hash in audits or notes');
    }

    public function test_a_dry_run_writes_nothing_and_a_rerun_adds_nobody_twice(): void
    {
        $before = Staff::query()->count();
        $this->assertSame(4, (new AdminImport)->run($this->admins(), true)['counts']['added']);
        $this->assertSame($before, Staff::query()->count());

        (new AdminImport)->run($this->admins());
        $again = (new AdminImport)->run($this->admins());

        $this->assertSame([0, 4], [$again['counts']['added'], $again['counts']['existing']]);
    }

    public function test_the_command_never_prints_a_hash(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'admins');
        file_put_contents($file, json_encode($this->admins()));

        $this->artisan('app:import-whmcs-admins', ['file' => $file, '--dry-run' => true])
            ->doesntExpectOutputToContain('$2y$')->assertSuccessful();
        @unlink($file);
    }

    /** Review: a WHMCS role named like ours (or «admin») never takes a role here with «*». */
    public function test_a_role_name_never_brings_full_access(): void
    {
        Role::query()->firstOrCreate(['name' => 'admin'], ['permissions' => ['*']]);
        $rows = [['id' => 9, 'firstname' => 'Eve', 'lastname' => 'X', 'email' => 'eve@example.test', 'password' => password_hash('p', PASSWORD_BCRYPT, ['cost' => 10]), 'roleid' => 5, 'rolename' => 'Admin', 'disabled' => 0]];

        (new AdminImport)->run($rows);

        $role = Staff::query()->where('email', 'eve@example.test')->sole()->role;
        $this->assertSame(['Admin (WHMCS)', []], [$role->name, $role->permissions]);
    }

    /** Review: $2a$/$2b$ log in (stored as $2y$), at production's cost, through the staff login and its rehash. */
    public function test_a_whmcs_hash_logs_in_through_the_staff_guard(): void
    {
        config(['hashing.bcrypt.rounds' => 12]);
        $hash = password_hash('whmcs-pass', PASSWORD_BCRYPT, ['cost' => 10]);
        $rows = [
            ['id' => 2, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'ab@example.test', 'password' => '$2b$' . substr($hash, 4), 'roleid' => 1, 'rolename' => 'Full Administrator', 'disabled' => 0],
            ['id' => 3, 'firstname' => 'C', 'lastname' => 'D', 'email' => 'AB@example.test', 'password' => $hash, 'roleid' => 1, 'rolename' => 'Full Administrator', 'disabled' => 0],
        ];

        $result = (new AdminImport)->run($rows);

        $this->assertSame(1, $result['counts']['added']);
        $this->assertNotEmpty(array_filter($result['notes'], fn ($n) => str_contains($n, 'shares its email with admin #2')));
        $this->assertTrue(Auth::guard('staff')->attempt(['email' => 'ab@example.test', 'password' => 'whmcs-pass']));
        $this->assertStringStartsWith('$2y$12$', Staff::query()->where('email', 'ab@example.test')->value('password'), 'rehashed at our cost on login');
    }

    public function test_a_dry_run_leaves_roles_and_audits_too(): void
    {
        config(['audit.console' => true]);
        $before = [Role::query()->count(), DB::table('audits')->count()];

        (new AdminImport)->run($this->admins(), true);

        $this->assertSame($before, [Role::query()->count(), DB::table('audits')->count()]);
    }
}
