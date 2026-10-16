<?php

namespace App\Services\Whmcs;

use App\Models\Role;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * A WHMCS installation's admins as staff (`app:import-whmcs-admins`, docs/importer.md «Admins»), into a MyCS that is
 * already live: the full importer's rules (Full Administrator → `admin`, `*`; another role → a role of its name with
 * no permissions yet; disabled stays disabled), but matched by email, never by id, and never over staff already here
 * (their password, role and status stay). A WHMCS bcrypt hash is kept as it is, so they log in with their WHMCS
 * password; anything else leaves no password («Forgot password»). All in one transaction; a dry run rolls it back.
 */
final class AdminImport
{
    /** @var array{added: int, existing: int, without_password: int, roles: int} */
    private array $counts = ['added' => 0, 'existing' => 0, 'without_password' => 0, 'roles' => 0];

    /** @var list<string> */
    private array $notes = [];

    /** @var array<string, int> email => the WHMCS admin that brought it in this run */
    private array $seen = [];

    /**
     * @param  list<array<string, mixed>>  $admins  tbladmins rows joined with their role's name (`rolename`)
     * @return array{counts: array<string, int>, notes: list<string>}
     */
    public function run(array $admins, bool $dryRun = false): array
    {
        DB::beginTransaction();
        try {
            foreach ($admins as $admin) {
                $this->import($admin);
            }
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    /** @param  array<string, mixed>  $admin */
    private function import(array $admin): void
    {
        $email = mb_strtolower(trim((string) ($admin['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->notes[] = "Admin #{$admin['id']}: no valid email, skipped.";

            return;
        }
        if (isset($this->seen[$email])) {
            $this->notes[] = "Admin #{$admin['id']}: shares its email with admin #{$this->seen[$email]} in WHMCS, skipped.";

            return;
        }
        $this->seen[$email] = (int) $admin['id'];
        if (Staff::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $this->counts['existing']++;
            $this->notes[] = "Admin #{$admin['id']} ({$email}): already staff here, left as it is.";

            return;
        }

        $staff = new Staff;
        $staff->forceFill([
            'first_name' => (string) ($admin['firstname'] ?? ''),
            'last_name' => (string) ($admin['lastname'] ?? ''),
            'email' => $email,
            'role_id' => $this->role((int) ($admin['roleid'] ?? 0), (string) ($admin['rolename'] ?? '')),
            'active' => (int) ($admin['disabled'] ?? 0) !== 1,
            // Not null in the table: an unusable one until the WHMCS hash replaces it, or «Forgot password» does.
            'password' => Str::random(64),
        ])->save(); // audited, without the password
        $this->counts['added']++;

        // The hash as WHMCS keeps it, written past the model's `hashed` cast (it would re-check its cost): bcrypt only, as
        // $2y$ (PHP's own; $2a$/$2b$ are the same hash, but Laravel would refuse them at login: review).
        $hash = (string) ($admin['password'] ?? '');
        if (preg_match('/^\$2[ab]\$/', $hash) === 1) {
            $hash = '$2y$' . substr($hash, 4);
        }
        if (preg_match('/^\$2y\$(0[4-9]|[12]\d|3[01])\$[.\/A-Za-z0-9]{53}\z/', $hash) === 1) {
            DB::table('staff')->where('id', $staff->id)->update(['password' => $hash]);
        } else {
            $this->counts['without_password']++;
            $this->notes[] = "Admin #{$admin['id']} ({$email}): no bcrypt password in WHMCS, «Forgot password» sets one.";
        }
        if (trim((string) ($admin['authmodule'] ?? '')) !== '') {
            $this->notes[] = "Admin #{$admin['id']} ({$email}): had two-factor login in WHMCS, to set up again here.";
        }
    }

    private function role(int $whmcsRoleId, string $name): int
    {
        if ($whmcsRoleId === 1) {
            $role = Role::query()->firstOrCreate(['name' => 'admin'], ['permissions' => ['*']]);
            if ($role->wasRecentlyCreated) {
                $this->counts['roles']++;
                $this->notes[] = 'Role «admin»: added with all permissions (WHMCS Full Administrator).';
            }

            return (int) $role->id;
        }
        $name = trim($name) !== '' ? trim($name) : 'WHMCS role ' . $whmcsRoleId;
        $existing = Role::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        // Never another role's power by its name (review): one called «admin», or one here with «*», is not reused.
        if ($existing !== null && (mb_strtolower($name) === 'admin' || in_array('*', $existing->permissions ?? [], true))) {
            $name .= ' (WHMCS)';
            $existing = Role::query()->where('name', $name)->first();
        }
        if ($existing !== null) {
            $this->notes[] = "Role «{$existing->name}»: already here, used with its " . count($existing->permissions ?? []) . ' permission(s).';

            return (int) $existing->id;
        }
        $role = Role::query()->create(['name' => $name, 'permissions' => []]);
        $this->counts['roles']++;
        $this->notes[] = "Role «{$name}»: added with no permissions, give it its permissions under Roles.";

        return (int) $role->id;
    }
}
