<?php

namespace Tests\Feature;

use App\Admin\Pages\Dashboard;
use App\Admin\Widgets\RecentActivity;
use App\Admin\Widgets\Support;
use App\Models\Audit;
use App\Models\Role;
use App\Models\Staff;
use App\Support\AuditSummary;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** The dashboard's «Recent activity» (2026-11-04): the Activity Log's last lines, with its permission. */
class DashboardRecentActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['first_name' => 'Maria', 'last_name' => 'Admin',
            'role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function audit(?Staff $by, string $type, array $new = ['name' => 'b'], string $ip = '203.0.113.7'): Audit
    {
        return Audit::query()->forceCreate([
            'user_type' => $by ? Staff::class : null, 'user_id' => $by?->id, 'event' => 'updated',
            'auditable_type' => $type, 'auditable_id' => 1,
            'old_values' => ['name' => 'a'], 'new_values' => $new,
            'url' => 'console', 'ip_address' => $ip, 'user_agent' => 'x',
        ]);
    }

    public function test_it_shows_the_newest_lines_as_the_log_writes_them(): void
    {
        $staff = $this->staff(['*']);
        $this->actingAs($staff, 'staff');
        $old = $this->audit(null, Staff::class);
        $audits = collect(range(1, RecentActivity::ROWS))->map(fn () => $this->audit($staff, Staff::class));

        Livewire::test(RecentActivity::class)
            ->assertCanSeeTableRecords($audits)
            ->assertCanNotSeeTableRecords([$old])
            ->assertSee(AuditSummary::line($audits->last()))
            ->assertSee('Maria Admin')
            ->assertSee('203.0.113.7')
            ->assertSee(__('View all'));
    }

    public function test_the_system_has_no_ip_and_says_system(): void
    {
        $this->actingAs($this->staff(['*']), 'staff');
        $this->audit(null, Staff::class, ip: '198.51.100.9');

        Livewire::test(RecentActivity::class)
            ->assertSee(__('System'))
            ->assertDontSee('198.51.100.9');
    }

    /** Who did each and the subjects are loaded with the rows: the same few queries for 8 rows by 4 staff as for 1. */
    public function test_the_rows_load_their_users_and_subjects_together(): void
    {
        $this->actingAs($this->staff(['*']), 'staff');
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(RecentActivity::class);
            $queries = collect(DB::getQueryLog())->filter(fn (array $q) => preg_match('/from "(audits|staff)"/', $q['query']))->count();
            DB::disableQueryLog();

            return $queries;
        };
        $this->audit($this->staff(['*']), Staff::class);
        $one = $count();
        foreach (range(1, 3) as $i) {
            $by = $this->staff(['*']);
            $this->audit($by, Staff::class);
            $this->audit($by, Staff::class);
        }

        $this->assertSame($one, $count(), 'no query per row');
    }

    public function test_the_card_polls(): void
    {
        $this->actingAs($this->staff(['*']), 'staff');

        $this->assertStringContainsString('wire:poll.60s', Livewire::test(RecentActivity::class)->html());
    }

    /** An audit of a class no longer there (an extension removed) does not take the dashboard down. */
    public function test_an_audit_of_a_gone_class_does_not_break_the_card(): void
    {
        $this->actingAs($this->staff(['*']), 'staff');
        $gone = $this->audit(null, 'Paymenter\\Extensions\\Gone\\Models\\Thing');

        Livewire::test(RecentActivity::class)->assertOk()->assertCanSeeTableRecords([$gone]);
    }

    /** The log's permission: without it, no card (and the right column goes on without it). */
    public function test_staff_without_the_log_do_not_see_it(): void
    {
        $this->actingAs($this->staff(['admin.widgets.support']), 'staff');
        $this->audit(null, Staff::class);

        $this->assertFalse(RecentActivity::canView());
        $this->assertNotContains(RecentActivity::class, (new Dashboard)->layout()['right']);
    }

    public function test_with_the_log_it_sits_under_support(): void
    {
        $this->actingAs($this->staff(['admin.widgets.support', 'admin.audit.viewAny']), 'staff');

        $right = (new Dashboard)->layout()['right'];

        $this->assertSame([Support::class, RecentActivity::class], array_slice($right, 0, 2));
    }
}
