<?php

namespace Tests\Feature;

use App\Admin\Resources\FailedJobResource\Pages\ListFailedJobs;
use App\Models\Audit;
use App\Models\FailedJob;
use App\Models\Role;
use App\Models\User;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Permissions (audit sweep AUTH-2, AUTH-3). ~24 names the code checked were not in config/permissions.php, so no role
 * but «*» could have them (the audit log, templates, single records of a dozen resources). And Filament allows what a
 * policy does not define: audits could be deleted (the money trail is append-only), failed jobs retried by anyone who
 * could see them.
 */
class PermissionNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_permission_the_code_checks_is_defined(): void
    {
        $defined = array_keys(Arr::dot(config('permissions.role')));
        $missing = [];
        foreach ((new Finder)->in(app_path())->name('*.php')->files() as $file) {
            preg_match_all("/(?:hasPermission\\(\\s*|'has-permission',\\s*)'([a-z_]+(?:\\.[a-zA-Z_]+)+)'/", $file->getContents(), $matches);
            foreach ($matches[1] as $name) {
                if ($name !== '*' && !in_array($name, $defined, true)) {
                    $missing[$name] = $file->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $missing, 'checked but not defined: no role could be given them');
    }

    private function staff(array $permissions): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Staff' . uniqid(), 'permissions' => $permissions])->id]);
    }

    public function test_audits_are_never_edited_or_deleted_even_by_the_owner(): void
    {
        $owner = $this->staff(['*']);
        $audit = Audit::query()->create(['event' => 'updated', 'auditable_type' => User::class, 'auditable_id' => $owner->id, 'old_values' => [], 'new_values' => []]);

        foreach (['update', 'delete', 'forceDelete', 'restore'] as $ability) {
            $this->assertFalse($owner->can($ability, $audit), $ability);
        }
        $this->assertFalse($owner->can('deleteAny', Audit::class));
        $this->assertTrue($owner->can('viewAny', Audit::class));
    }

    public function test_seeing_failed_jobs_is_not_retrying_them(): void
    {
        $viewer = $this->staff(['admin.failed_jobs.viewAny']);
        DB::table('failed_jobs')->insert(['uuid' => 'u-1', 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Jobs\\Server\\CreateJob', 'data' => []]), 'exception' => 'x', 'failed_at' => now()]);

        Livewire::actingAs($viewer)->test(ListFailedJobs::class)
            ->assertTableActionHidden('retry', FailedJob::query()->first())
            ->assertTableActionHidden('delete', FailedJob::query()->first());

        $this->assertNotNull(FailedJob::query()->first());
    }

    public function test_roles_with_the_old_names_get_the_ones_the_code_checks(): void
    {
        $role = Role::create(['name' => 'Editor' . uniqid(), 'permissions' => ['admin.email_templates.update', 'admin.audits.viewAny', 'admin.users.viewAny']]);
        $migration = require database_path('migrations/2026_10_24_000000_rename_dead_permission_names_in_roles.php');

        $migration->up();

        $this->assertEqualsCanonicalizing(['admin.notification_templates.update', 'admin.audit.viewAny', 'admin.users.viewAny'], $role->fresh()->permissions);
    }
}
