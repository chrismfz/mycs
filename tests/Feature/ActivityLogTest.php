<?php

namespace Tests\Feature;

use App\Admin\Logs;
use App\Admin\Pages\CronStats;
use App\Admin\Resources\Audits\AuditResource;
use App\Admin\Resources\Audits\Pages\ListAudits;
use App\Admin\Resources\ClientResource;
use App\Admin\Resources\DomainResource;
use App\Admin\Resources\EmailLogResource;
use App\Admin\Resources\EmailLogResource\Pages\ListEmailLogs;
use App\Admin\Resources\StaffResource;
use App\Models\Audit;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Role;
use App\Models\Staff;
use App\Support\Actor;
use App\Support\AuditSummary;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Activity Log as WHMCS's (owner 2026-10-23): what changed in words, the client, who did it (the system's own
 * changes as «System»), and all the logs on its left.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->create(['first_name' => 'Maria', 'last_name' => 'Admin',
            'role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
    }

    public function test_a_change_by_the_system_reads_as_whmcs_writes_it(): void
    {
        $client = Client::factory()->ownedBy()->create();
        $domain = Domain::factory()->create(['client_id' => $client->id, 'sld' => 'cdnet', 'fqdn' => 'cdnet.test', 'expires_at' => '2026-10-31']);
        // The domain sync from the cron: no one logged in, the console audited (SyncDomains).
        Config::set('audit.console', true);
        $domain->forceFill(['expires_at' => '2027-10-31', 'status' => 'expired'])->save();
        $audit = Audit::query()->where('auditable_type', Domain::class)->where('event', 'updated')->sole();

        $this->actingAs($this->staff, 'staff');
        Livewire::test(ListAudits::class)
            ->assertTableColumnStateSet('entry', "Modified Domain cdnet.test (#{$domain->id}) — Status: active → expired; Expires at: 2026-10-31 → 2027-10-31", $audit)
            ->assertTableColumnStateSet('client', $client->fresh()->name, $audit)
            ->assertTableColumnStateSet('user_id', 'System', $audit)
            ->assertSee(DomainResource::getUrl('view', ['record' => $domain]), false)
            ->assertSee(ClientResource::getUrl('view', ['record' => $client]), false);
    }

    public function test_a_staff_change_names_the_staff_member_not_a_client_with_the_same_id(): void
    {
        Config::set('audit.console', true);
        $this->actingAs($this->staff, 'staff');
        Auth::shouldUse('staff');
        $this->assertSame($this->staff->id, Actor::current()?->id);
        $client = Client::factory()->ownedBy()->create();
        $client->forceFill(['credit_auto_apply' => false])->save();
        $audit = Audit::query()->where('auditable_type', Client::class)->where('event', 'updated')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(Staff::class, $audit->user_type);

        Livewire::test(ListAudits::class)
            ->assertTableColumnStateSet('user_id', 'Maria Admin', $audit)
            ->assertTableColumnStateSet('client', $client->fresh()->name, $audit)
            ->assertSee(StaffResource::getUrl('edit', ['record' => $this->staff]), false);
    }

    public function test_the_filters_take_the_systems_changes_apart_from_staffs(): void
    {
        $domain = Domain::factory()->create(['sld' => 'sync', 'fqdn' => 'sync.test']);
        Config::set('audit.console', true);
        $domain->forceFill(['expires_at' => '2030-01-01'])->save();
        $system = Audit::query()->where('auditable_type', Domain::class)->where('event', 'updated')->sole();
        $this->actingAs($this->staff, 'staff');
        Auth::shouldUse('staff');
        $domain->forceFill(['auto_renew' => false])->save();
        $staff = Audit::query()->where('auditable_type', Domain::class)->where('event', 'updated')->latest('id')->first();
        $this->assertNotSame($system->id, $staff->id);

        Livewire::test(ListAudits::class)
            ->filterTable('actor', 'system')->assertCanSeeTableRecords([$system])->assertCanNotSeeTableRecords([$staff])
            ->filterTable('actor', 'staff:' . $this->staff->id)->assertCanSeeTableRecords([$staff])->assertCanNotSeeTableRecords([$system])
            ->filterTable('actor', 'client')->assertCanNotSeeTableRecords([$system, $staff]);
    }

    public function test_a_deleted_subject_keeps_its_name_without_a_link(): void
    {
        Config::set('audit.console', true);
        $domain = Domain::factory()->create(['sld' => 'gone', 'fqdn' => 'gone.test']);
        $id = $domain->id;
        $domain->delete();
        $audit = Audit::query()->where('auditable_type', Domain::class)->where('event', 'deleted')->sole();

        $this->actingAs($this->staff, 'staff');
        $line = Livewire::test(ListAudits::class)->instance()->getTable()->getColumn('entry')->record($audit)->getState();
        $this->assertStringStartsWith("Deleted Domain gone.test (#{$id})", $line);
        $this->assertNull(AuditSummary::subjectUrl($audit));
    }

    public function test_the_log_pages_show_all_the_logs_on_their_left_as_the_staff_may_see_them(): void
    {
        $this->actingAs($this->staff, 'staff');
        $this->get(AuditResource::getUrl('index'))->assertOk()
            ->assertSee(EmailLogResource::getUrl('index'), false)->assertSee(CronStats::getUrl(), false);
        Livewire::test(ListEmailLogs::class)->assertSee(AuditResource::getUrl('index'), false);
    }

    public function test_one_who_may_see_only_the_activity_log_is_shown_only_it(): void
    {
        $auditor = Staff::factory()->create(['role_id' => Role::create(['name' => 'Audit', 'permissions' => ['admin.audit.viewAny']])->id]);
        $this->actingAs($auditor, 'staff');
        $this->assertSame([__('Activity Log'), __('Login History')], array_map(fn ($item) => $item->getLabel(), Logs::navigation()));
        $this->get(AuditResource::getUrl('index'))->assertOk();
    }

    public function test_the_log_is_append_only(): void
    {
        $this->actingAs($this->staff, 'staff');
        $this->assertFalse(AuditResource::canDeleteAny());
        Livewire::test(ListAudits::class)->assertTableBulkActionDoesNotExist('delete');
    }

    /** A page of the log reads its subjects and their clients once, not per row (review of #402). */
    public function test_a_page_of_the_log_reads_its_clients_once(): void
    {
        Config::set('audit.console', true);
        foreach (range(1, 10) as $n) {
            $domain = Domain::factory()->create(['client_id' => Client::factory()->ownedBy()->create()->id, 'sld' => "n{$n}", 'fqdn' => "n{$n}.test"]);
            $domain->forceFill(['auto_renew' => false])->save();
        }
        $this->actingAs($this->staff, 'staff');
        DB::enableQueryLog();
        Livewire::test(ListAudits::class)->assertOk();
        $contacts = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'from "contacts"'))->count();
        $this->assertLessThanOrEqual(5, $contacts); // one per kind of subject, not one per row
    }

    /** A switch turned off reads 0, not empty (owner 2026-10-23). */
    public function test_a_switch_turned_off_reads_0(): void
    {
        $audit = new Audit(['event' => 'updated', 'auditable_type' => Domain::class, 'auditable_id' => 1,
            'old_values' => ['auto_renew' => true], 'new_values' => ['auto_renew' => false]]);
        $audit->setRelation('auditable', null);

        $this->assertSame('Auto renew: 1 → 0', AuditSummary::changes($audit));
    }
}
