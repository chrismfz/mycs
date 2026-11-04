<?php

namespace Tests\Feature;

use App\Admin\Resources\RoleResource;
use App\Console\Commands\ImportFromWhmcs;
use App\Models\Role;
use App\Models\Staff;
use App\Services\Whmcs\AdminImport;
use App\Services\Whmcs\RolePermissions;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * WG-5: a WHMCS admin role comes with its permissions as MyCS's, where the importer gave every role but the Full
 * Administrator none (nixpal's Sales Operator and myip's Support Operator could open nothing). The roles' permids are
 * the live ones (2026-11-04), not personal data.
 */
class WhmcsRolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const NIXPAL_SALES = '1,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,49,50,51,52,71,73,85,98,99,101,104,105,110,120,123,124,125,126,128,129,145,149,158,159';

    private const MYIP_SUPPORT = '1,3,4,5,6,7,8,9,10,11,12,13,14,15,16,18,19,21,22,24,25,26,27,28,29,30,32,33,34,35,36,38,39,40,41,42,43,44,46,49,50,51,58,60,61,62,63,67,70,71,72,73,74,77,80,82,84,89,90,92,93,95,96,97,98,99,102,103,104,105,108,109,110,111,112,113,114,115,117,119,120,121,122,124,125,128,129,132,137,138,149,150,151,152,158,159,160,161,162';

    public function test_every_whmcs_permission_is_mapped_or_named_and_every_key_exists(): void
    {
        $keys = array_keys(Arr::dot(config('permissions.role')));
        $all = RolePermissions::map(array_diff(range(1, 163), [48])); // WHMCS has no 48

        $this->assertSame([], array_values(array_diff($all['permissions'], $keys)), 'only MyCS\'s own permissions');
        $this->assertNotContains('*', $all['permissions']);
        $this->assertCount(count(RolePermissions::UNMAPPED), $all['unmapped'], 'every unmapped one by its WHMCS name');
        $this->assertSame(['WHMCS permission #48'], RolePermissions::map([48])['unmapped'], 'an unknown id is still said');
    }

    public function test_the_live_roles_get_what_they_had(): void
    {
        $sales = RolePermissions::map(explode(',', self::NIXPAL_SALES));
        foreach (['admin.invoices.update', 'admin.invoice_transactions.create', 'admin.credits.create', 'admin.domains.epp', 'admin.users.impersonate', 'admin.tickets.update'] as $permission) {
            $this->assertContains($permission, $sales['permissions']);
        }
        foreach (['admin.settings.update', 'admin.staff.update', 'admin.roles.update', 'admin.gateways.update', 'admin.audit.viewAny'] as $permission) {
            $this->assertNotContains($permission, $sales['permissions'], 'nothing the role did not have');
        }
        $this->assertSame([], array_values(array_diff(RoleResource::DEFAULT_PERMISSIONS, $sales['permissions'])), 'MyCS\'s defaults');
        $this->assertCount(15, $sales['unmapped']);
        $this->assertContains('Delete Clients Domains', $sales['unmapped']);

        $support = RolePermissions::map(explode(',', self::MYIP_SUPPORT));
        $this->assertContains('admin.settings.update', $support['permissions'], 'Configure General Settings, as in WHMCS (owner, 2026-11-04)');
        $this->assertNotContains('admin.staff.update', $support['permissions']);
        $this->assertCount(25, $support['unmapped']);
    }

    public function test_the_full_import_gives_a_role_its_permissions_and_says_what_is_left(): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tbladminroles (id INTEGER PRIMARY KEY, name TEXT)');
        $whmcs->exec('CREATE TABLE tbladminperms (roleid INTEGER, permid INTEGER)');
        $whmcs->exec("INSERT INTO tbladminroles VALUES (2, 'Sales Operator'), (5, 'Admin'), (6, 'Empty')");
        $whmcs->exec('INSERT INTO tbladminperms VALUES (2, 35), (2, 120), (2, 49), (5, 33)');
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        $roleId = new ReflectionMethod($command, 'staffRoleId');

        $sales = Role::query()->findOrFail($roleId->invoke($command, 2));
        $staff = Staff::factory()->create(['role_id' => $sales->id]);
        $this->assertTrue($staff->hasPermission('admin.invoices.update'));
        $this->assertTrue($staff->hasPermission('admin.users.impersonate'));
        $this->assertFalse($staff->hasPermission('admin.invoices.delete'));
        $this->assertStringContainsString('Role «Sales Operator»: 1 WHMCS permission(s) MyCS has nothing for: Calendar.', $output->fetch());

        Role::query()->firstOrCreate(['name' => 'admin'], ['permissions' => ['*']]);
        $admin = Role::query()->findOrFail($roleId->invoke($command, 5));
        $this->assertSame('Admin (WHMCS)', $admin->name, 'never the Full Administrator\'s role by its name');
        $this->assertNotContains('*', $admin->permissions);

        $defaults = RoleResource::DEFAULT_PERMISSIONS;
        sort($defaults);
        $this->assertSame($defaults, Role::query()->findOrFail($roleId->invoke($command, 6))->permissions, 'a role with none: MyCS\'s defaults only');
    }

    public function test_admins_into_a_live_mycs_bring_their_roles_permissions_when_the_file_has_them(): void
    {
        $row = fn (int $id, string $role, mixed $permids) => array_filter(['id' => $id, 'firstname' => 'A', 'lastname' => 'B', 'email' => "a{$id}@example.test",
            'password' => '', 'roleid' => $id, 'rolename' => $role, 'disabled' => 0, 'permids' => $permids], fn ($value) => $value !== null);

        $result = (new AdminImport)->run([$row(2, 'Sales Operator', '35,129,49'), $row(3, 'Support Operator', [42, 105]), $row(4, 'Old File', null)]);

        $role = fn (string $name) => Role::query()->where('name', $name)->sole()->permissions;
        $this->assertContains('admin.credits.update', $role('Sales Operator'));
        $this->assertContains('admin.tickets.update', $role('Support Operator'));
        $this->assertSame([], $role('Old File'), 'a file without permids: none, as before');
        $this->assertNotEmpty(array_filter($result['notes'], fn (string $note) => str_contains($note, 'Role «Sales Operator»') && str_contains($note, 'MyCS has nothing for: Calendar.')));
    }
}
