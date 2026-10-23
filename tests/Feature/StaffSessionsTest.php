<?php

namespace Tests\Feature;

use App\Admin\Resources\StaffResource;
use App\Livewire\Admin\StaffSessionList;
use App\Models\Staff;
use App\Models\StaffSession;
use App\Support\AuthCookies;
use App\Support\StaffSessions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * A member of staff's sessions (owner 2026-10-23, a lost or shared laptop): each login is listed, with its browser,
 * address and when it was last seen; the member ends their others, an admin with admin.staff.sessions ends a member's.
 * Ending one logs it out at once and ends «remember me» on every browser of the member. The staff «remember me»
 * cookie carries the __Host- prefix.
 */
class StaffSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function member(array $attributes = []): Staff
    {
        return Staff::factory()->admin()->create($attributes + ['password' => Hash::make('Right-Pass-123'), 'remember_token' => 'old-token']);
    }

    /** A session row of $staff in another browser. */
    private function otherSession(Staff $staff, array $attributes = []): StaffSession
    {
        return StaffSession::query()->create($attributes + ['staff_id' => $staff->id, 'session_id' => 'other-session-id', 'ip_address' => '198.51.100.9',
            'user_agent' => 'Other Browser', 'remembered' => false, 'created_at' => now()->subHour(), 'last_seen_at' => now()->subMinute()]);
    }

    public function test_the_staff_remember_cookie_is_host_prefixed_on_https(): void
    {
        config(['session.secure' => true, 'session.domain' => null]);

        $this->assertStringStartsWith(AuthCookies::PREFIX . 'remember_staff_', Auth::guard('staff')->getRecallerName());

        config(['session.secure' => false]);
        $this->assertStringStartsWith('remember_staff_', Auth::guard('staff')->getRecallerName());
    }

    public function test_a_login_writes_its_session_and_a_logout_ends_it(): void
    {
        $staff = $this->member(['email' => 'ada@example.test']);

        // As the login page does once the password (and 2FA) are right.
        Auth::guard('staff')->login($staff, true);

        $this->assertAuthenticatedAs($staff, 'staff');
        $this->assertNotNull($staff->fresh()->last_login_at);
        $row = StaffSession::query()->sole();
        $this->assertSame($staff->id, $row->staff_id);
        $this->assertTrue($row->remembered);
        $this->assertSame($row->ulid, session(StaffSessions::SESSION_KEY));

        Auth::guard('staff')->logout();
        $this->assertNotNull($row->fresh()->ended_at);
    }

    public function test_a_session_from_before_the_rows_gets_one_on_its_next_request(): void
    {
        $staff = $this->member();

        $this->actingAs($staff, 'staff')->get(Filament::getPanel('admin')->getUrl())->assertOk();

        $this->assertSame(1, StaffSession::query()->where('staff_id', $staff->id)->count());
    }

    public function test_an_ended_session_is_logged_out_on_its_next_request(): void
    {
        $staff = $this->member();
        $this->actingAs($staff, 'staff')->get(Filament::getPanel('admin')->getUrl())->assertOk();
        $row = StaffSession::query()->sole();

        $row->forceFill(['ended_at' => now()])->save();

        $this->get(Filament::getPanel('admin')->getUrl())->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
        $this->assertGuest('staff');
    }

    public function test_the_member_ends_another_session_of_theirs(): void
    {
        $staff = $this->member();
        $this->actingAs($staff, 'staff')->get(Filament::getPanel('admin')->getUrl())->assertOk();
        $other = $this->otherSession($staff);

        Livewire::actingAs($staff, 'staff')->test(StaffSessionList::class, ['staffId' => $staff->id])
            ->assertSee('Other Browser')->assertSee('198.51.100.9')
            ->call('end', $other->ulid);

        $this->assertNotNull($other->fresh()->ended_at);
        $this->assertSame($staff->id, $other->fresh()->ended_by_staff_id);
        $this->assertNotSame('old-token', $staff->fresh()->remember_token, 'every remembered browser logs in again');
        $this->assertTrue(Audit::query()->where('auditable_type', Staff::class)->where('auditable_id', $staff->id)->where('event', 'sessions_ended')->exists());
    }

    public function test_end_all_others_keeps_this_browser(): void
    {
        $staff = $this->member();
        $this->actingAs($staff, 'staff')->get(Filament::getPanel('admin')->getUrl())->assertOk();
        $mine = StaffSession::query()->sole();
        $a = $this->otherSession($staff);
        $b = $this->otherSession($staff, ['session_id' => 'third', 'remembered' => true, 'last_seen_at' => now()->subDays(20)]);

        Livewire::actingAs($staff, 'staff')->test(StaffSessionList::class, ['staffId' => $staff->id])->call('endOthers');

        $this->assertNotNull($a->fresh()->ended_at);
        $this->assertNotNull($b->fresh()->ended_at);
        $this->assertNull($mine->fresh()->ended_at);
        $this->get(Filament::getPanel('admin')->getUrl())->assertOk();
    }

    public function test_another_members_sessions_need_the_permission(): void
    {
        $member = $this->member();
        $other = $this->otherSession($member);
        $plain = Staff::factory()->withPermissions(['admin.staff.viewAny', 'admin.staff.update'])->create();

        Livewire::actingAs($plain, 'staff')->test(StaffSessionList::class, ['staffId' => $member->id])->assertForbidden();

        $this->assertNull($other->fresh()->ended_at);
    }

    public function test_an_admin_with_the_permission_ends_all_of_a_members_sessions(): void
    {
        $member = $this->member();
        $a = $this->otherSession($member);
        $b = $this->otherSession($member, ['session_id' => 'x2', 'remembered' => true]);
        $admin = Staff::factory()->withPermissions(['admin.staff.viewAny', 'admin.staff.sessions'])->create();

        Livewire::actingAs($admin, 'staff')->test(StaffSessionList::class, ['staffId' => $member->id])->assertSee('Other Browser')->call('endOthers');

        $this->assertNotNull($a->fresh()->ended_at);
        $this->assertNotNull($b->fresh()->ended_at);
        $this->assertSame($admin->id, $a->fresh()->ended_by_staff_id);
        $this->assertNotSame('old-token', $member->fresh()->remember_token);
    }

    public function test_a_session_of_another_member_cannot_be_ended_through_ones_own_list(): void
    {
        $me = $this->member();
        $victim = $this->member();
        $theirs = $this->otherSession($victim);

        Livewire::actingAs($me, 'staff')->test(StaffSessionList::class, ['staffId' => $me->id])->call('end', $theirs->ulid);

        $this->assertNull($theirs->fresh()->ended_at);
    }

    public function test_ending_destroys_the_session_in_the_store(): void
    {
        $staff = $this->member();
        $handler = $this->app['session']->driver()->getHandler();
        $handler->write('stored-session-id', serialize(['x' => 1]));
        $other = $this->otherSession($staff, ['session_id' => 'stored-session-id']);

        StaffSessions::end($staff, [$other], $staff, request());

        $this->assertSame('', (string) $handler->read('stored-session-id'));
    }

    public function test_the_profile_lists_the_members_own_sessions(): void
    {
        $staff = $this->member();
        $this->otherSession($staff);

        $this->actingAs($staff, 'staff')->get(Filament::getPanel('admin')->getProfileUrl())->assertOk()->assertSee('Other Browser');
    }

    public function test_a_members_page_shows_their_sessions_only_with_the_permission(): void
    {
        $member = Staff::factory()->withPermissions(['admin.staff.viewAny'])->create();
        $this->otherSession($member);
        $url = StaffResource::getUrl('edit', ['record' => $member]);

        $this->actingAs(Staff::factory()->withPermissions(['admin.staff.viewAny', 'admin.staff.update'])->create(), 'staff')
            ->get($url)->assertOk()->assertDontSee('Other Browser');
        $this->actingAs(Staff::factory()->withPermissions(['admin.staff.viewAny', 'admin.staff.update', 'admin.staff.sessions'])->create(), 'staff')
            ->get($url)->assertOk()->assertSee('Other Browser');
    }
}
