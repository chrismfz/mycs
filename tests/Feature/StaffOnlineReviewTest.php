<?php

namespace Tests\Feature;

use App\Admin\Resources\RoleResource\Pages\CreateRole;
use App\Models\Role;
use App\Models\Staff;
use App\Support\StaffPresence;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** Independent review of #362 («Staff online»). */
class StaffOnlineReviewTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $attributes = []): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['admin.staff_online.view']])->id] + $attributes);
    }

    private function staffSession(Staff $staff): array
    {
        return [Auth::guard('staff')->getName() => $staff->id];
    }

    /** @return array{string, string} the snapshots of the heartbeat and of the sidebar list on an admin page */
    private function snapshots(string $html): array
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $m);
        $found = [];
        foreach ($m[1] as $raw) {
            $decoded = html_entity_decode($raw, ENT_QUOTES);
            $found[json_decode($decoded, true)['memo']['name'] ?? ''] = $decoded;
        }

        return [$found['admin.staff-heartbeat'] ?? null, $found['admin.staff-online'] ?? null];
    }

    private function sidebarList(string $html): string
    {
        $start = strpos($html, 'mycs-staff-online');
        $this->assertNotFalse($start);

        return substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
    }

    /** R1: the sidebar renders before the BODY_END heartbeat mounts, so the first page does not list the member. */
    public function test_the_first_admin_page_lists_the_member_themself(): void
    {
        $me = $this->member(['first_name' => 'Zoe', 'last_name' => 'Self']);

        $html = $this->withSession($this->staffSession($me))->get('/sysadmin')->assertOk()->getContent();

        $this->assertStringContainsString('Zoe Self', $this->sidebarList($html), 'the member is not in their own «Staff online» until the next poll');
    }

    /** A deactivated member's open tab: the heartbeat poll must not keep writing for them. */
    public function test_a_deactivated_members_open_tab_does_not_beat(): void
    {
        $me = $this->member();
        $html = $this->withSession($this->staffSession($me))->get('/sysadmin')->assertOk()->getContent();
        [$heartbeat] = $this->snapshots($html);
        $this->assertNotNull($heartbeat);

        $me->forceFill(['active' => false])->save();
        app('auth')->forgetGuards();
        Cache::flush();
        Staff::query()->whereKey($me->id)->update(['last_seen_at' => null]);

        $this->withHeaders(['X-Livewire' => '1'])
            ->postJson('/mycs/update', ['components' => [['snapshot' => $heartbeat, 'updates' => new \stdClass, 'calls' => [['path' => '', 'method' => 'beat', 'params' => []]]]]]);

        $this->assertNull($me->fresh()->last_seen_at);
        $this->assertNull(Cache::get('staff-presence:' . $me->id));
    }

    /** Logged out (or the session expired), the same snapshot writes nothing. */
    public function test_a_logged_out_session_does_not_beat_or_set_a_status(): void
    {
        $me = $this->member();
        $html = $this->withSession($this->staffSession($me))->get('/sysadmin')->assertOk()->getContent();
        [$heartbeat, $list] = $this->snapshots($html);
        Cache::flush();
        Staff::query()->whereKey($me->id)->update(['last_seen_at' => null]);

        $this->flushSession();
        Auth::guard('staff')->logout();
        $this->withHeaders(['X-Livewire' => '1'])->postJson('/mycs/update', ['components' => [
            ['snapshot' => $heartbeat, 'updates' => new \stdClass, 'calls' => [['path' => '', 'method' => 'beat', 'params' => []]]],
            ['snapshot' => $list, 'updates' => new \stdClass, 'calls' => [['path' => '', 'method' => 'setStatus', 'params' => ['away']]]],
        ]]);

        $this->assertNull($me->fresh()->last_seen_at);
        $this->assertSame('available', $me->fresh()->presence_status);
    }

    /** Before 2FA is set up (mandatory), the member is not yet in the admin: they should not show as online. */
    public function test_a_member_who_has_not_set_up_2fa_is_not_online(): void
    {
        $me = Staff::factory()->withoutTwoFactor()->create(['role_id' => Role::create(['name' => 'R2', 'permissions' => ['*']])->id]);

        $this->withSession($this->staffSession($me))->get('/sysadmin/multi-factor-authentication/set-up')->assertOk()->assertDontSee('admin.staff-heartbeat', false);
        StaffPresence::heartbeat($me); // a poll from a tab opened before, too

        $this->assertSame([], StaffPresence::online(), 'listed online while still on the 2FA set-up page');
    }

    /** down() then up() again on the test database (SQLite). */
    public function test_the_migration_rolls_back_and_forward(): void
    {
        $role = Role::create(['name' => 'Plain', 'permissions' => ['admin.tickets.viewAny', 'admin.staff_online.view']]);
        $migration = require database_path('migrations/2026_12_27_000000_staff_presence.php');

        $migration->down();
        $this->assertSame(['admin.tickets.viewAny'], $role->fresh()->permissions);
        $migration->up();
        $this->assertSame(['admin.tickets.viewAny', 'admin.staff_online.view'], $role->fresh()->permissions);
        $this->assertSame('available', $this->member()->fresh()->presence_status);
    }

    /** R5: a role created after the migration has the permission ticked by default (docs/livechat.md §7β). */
    public function test_a_new_role_has_staff_online_ticked_by_default(): void
    {
        $admin = Staff::factory()->create(['role_id' => Role::create(['name' => 'Admin', 'permissions' => ['*']])->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'staff');

        Livewire::test(CreateRole::class)
            ->assertSchemaStateSet(['permissions' => ['admin.staff_online.view']]);
    }

    /** R3, R4: the sidebar list has a stable key and polls only while visible. */
    public function test_the_sidebar_list_has_a_stable_key_and_polls_only_while_visible(): void
    {
        $me = $this->member();

        $html = $this->withSession($this->staffSession($me))->get('/sysadmin')->assertOk()->getContent();

        $this->assertStringContainsString('wire:key="mycs-staff-online"', $html);
        $this->assertStringContainsString('wire:poll.30s.visible', $html);
    }
}
