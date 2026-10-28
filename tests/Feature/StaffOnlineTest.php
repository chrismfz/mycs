<?php

namespace Tests\Feature;

use App\Admin\Widgets\StaffOnline as StaffOnlineWidget;
use App\Enums\StaffPresenceStatus;
use App\Livewire\Admin\StaffHeartbeat;
use App\Livewire\Admin\StaffOnline;
use App\Models\Role;
use App\Models\Staff;
use App\Support\StaffPresence;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/** «Staff online» (docs/livechat.md §7β): who of the staff is in, in the sidebar and on the dashboard. */
class StaffOnlineTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $permissions = ['admin.staff_online.view'], array $attributes = []): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id] + $attributes);
    }

    private function actAs(Staff $staff): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($staff, 'staff');
    }

    public function test_a_heartbeat_keeps_a_member_online_for_90_seconds_then_shows_when_last_seen(): void
    {
        $staff = $this->member();
        StaffPresence::heartbeat($staff);

        $this->assertSame([$staff->id], array_map(fn ($m) => $m['staff']->id, StaffPresence::online()));

        $this->travel(StaffPresence::TTL + 1)->seconds();

        $this->assertSame([], StaffPresence::online());
        $member = StaffPresence::roster()[0];
        $this->assertFalse($member['online']);
        $this->assertNotNull($member['last_seen']);
    }

    public function test_last_seen_is_written_at_most_once_a_minute_without_an_audit_or_updated_at(): void
    {
        $staff = $this->member();
        $updatedAt = $staff->fresh()->updated_at;
        $audits = Audit::query()->count();

        StaffPresence::heartbeat($staff);
        $first = $staff->fresh()->last_seen_at;
        $this->travel(30)->seconds();
        StaffPresence::heartbeat($staff);
        $this->assertEquals($first, $staff->fresh()->last_seen_at, 'a beat within the minute writes nothing');

        $this->travel(31)->seconds();
        StaffPresence::heartbeat($staff);
        $this->assertTrue($staff->fresh()->last_seen_at->greaterThan($first));

        $this->assertEquals($updatedAt, $staff->fresh()->updated_at);
        $this->assertSame($audits, Audit::query()->count());
    }

    public function test_the_heartbeat_component_beats_for_the_staff_member_on_mount_and_on_poll(): void
    {
        $staff = $this->member([]);
        $this->actAs($staff);

        Livewire::test(StaffHeartbeat::class)->assertSeeHtml('wire:poll.30s.keep-alive="beat"');
        $this->assertNotNull($staff->fresh()->last_seen_at, 'a member without the list still counts as online');
        $this->assertSame([$staff->id], array_map(fn ($m) => $m['staff']->id, StaffPresence::online()));
    }

    public function test_the_admin_page_carries_the_heartbeat_and_the_sidebar_list(): void
    {
        $staff = $this->member(['*']);
        $this->actAs($staff);

        $this->withSession($this->loginUser($staff))->get('/sysadmin')->assertOk()
            ->assertSee('wire:poll.30s.keep-alive', false)
            ->assertSee('mycs-staff-online', false)
            ->assertSee(__('Staff online'));
    }

    public function test_inactive_members_and_members_without_a_role_are_not_listed(): void
    {
        $this->member();
        $inactive = $this->member(attributes: ['active' => false]);
        $noRole = Staff::factory()->create(['role_id' => null]);
        StaffPresence::heartbeat($inactive);
        StaffPresence::heartbeat($noRole);

        $ids = array_map(fn ($m) => $m['staff']->id, StaffPresence::roster());
        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($noRole->id, $ids);
    }

    public function test_a_member_sets_only_their_own_status_and_only_to_a_known_one(): void
    {
        $me = $this->member();
        $other = $this->member();
        $this->actAs($me);

        $component = Livewire::test(StaffOnline::class)->call('setStatus', 'busy');
        $this->assertSame(StaffPresenceStatus::Busy, StaffPresence::status($me->fresh()));
        $this->assertSame(StaffPresenceStatus::Available, StaffPresence::status($other->fresh()));

        $component->call('setStatus', 'admin');
        $this->assertSame(StaffPresenceStatus::Busy, StaffPresence::status($me->fresh()), 'an unknown status changes nothing');
    }

    public function test_the_status_is_never_changed_by_the_app(): void
    {
        $staff = $this->member();
        StaffPresence::setStatus($staff, StaffPresenceStatus::Away);

        StaffPresence::heartbeat($staff);
        $this->travel(2)->hours();
        StaffPresence::heartbeat($staff);

        $this->assertSame(StaffPresenceStatus::Away, StaffPresence::status($staff->fresh()));
    }

    public function test_without_the_permission_there_is_neither_the_list_nor_the_widget(): void
    {
        $staff = $this->member(['admin.tickets.viewAny']);
        $this->actAs($staff);
        StaffPresence::heartbeat($this->member());

        Livewire::test(StaffOnline::class)->assertDontSee(__('Staff online'));
        $this->assertFalse(StaffOnlineWidget::canView());
    }

    public function test_names_are_escaped_and_no_address_or_gravatar_is_shown(): void
    {
        $evil = $this->member(attributes: ['first_name' => '<img src=x onerror=alert(1)>', 'last_name' => 'X']);
        StaffPresence::heartbeat($evil);
        $viewer = $this->member();
        $this->actAs($viewer);

        foreach ([Livewire::test(StaffOnline::class), Livewire::test(StaffOnlineWidget::class)] as $component) {
            $html = $component->html();
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringContainsString('&lt;img src=x', $html);
            $this->assertStringNotContainsString('gravatar', strtolower($html));
            $this->assertStringNotContainsString('127.0.0.1', $html);
        }
    }

    public function test_the_widget_shows_now_for_the_online_and_when_last_seen_for_the_rest(): void
    {
        $gone = $this->member(attributes: ['first_name' => 'Gone']);
        StaffPresence::heartbeat($gone);
        $this->travel(10)->minutes();
        $here = $this->member(attributes: ['first_name' => 'Here']);
        StaffPresence::heartbeat($here);
        $this->member(attributes: ['first_name' => 'Never']);
        $this->actAs($here);

        Livewire::test(StaffOnlineWidget::class)
            ->assertSeeInOrder(['Here', __('Connected now'), 'Gone', '10 minutes ago', 'Never', __('Never')]);
    }
}
