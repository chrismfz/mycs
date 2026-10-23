<?php

namespace Tests\Feature;

use App\Actions\Auth\Logout;
use App\Livewire\Admin\StaffSessionList;
use App\Models\McpOauthGrant;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\StaffSession;
use App\Support\StaffSessions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of PR #375 (staff sessions): what an ended staff session can still do, and what ending one leaves
 * behind.
 */
class AdvStaffSessionsTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://claude.example.test/api/mcp/auth_callback';

    private string $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Setting::query()->updateOrCreate(['key' => 'mcp_oauth_redirect_hosts', 'settingable_type' => null, 'settingable_id' => null], ['value' => 'example.test, localhost, 127.0.0.1', 'type' => 'string']);
        config(['settings.mcp_oauth_redirect_hosts' => 'example.test, localhost, 127.0.0.1']);
        $this->verifier = str_repeat('v', 20) . bin2hex(random_bytes(16));
    }

    private function staff(array $permissions = ['*']): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function client(): string
    {
        return $this->postJson('/mcp/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT]])->assertCreated()->json('client_id');
    }

    /** @return array<string, string> */
    private function authorizeQuery(string $clientId): array
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');

        return ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT, 'code_challenge' => $challenge,
            'code_challenge_method' => 'S256', 'state' => 'st4te', 'resource' => url('/mcp')];
    }

    /**
     * An ended session is refused only by TrackStaffSession, which runs in the admin panel alone. The staff guard is also
     * used outside it: the MCP OAuth consent (/mcp/oauth/authorize), the income export, and «login as client»
     * (ImpersonateMiddleware). StaffSessions::end() itself tolerates a store that kept the session ("the ended row
     * refuses it anyway"), and the next test shows a common case where the store does keep it. Such a session, ended in
     * the list, still approves an MCP client: a long-lived token outlives the ended login.
     */
    public function test_an_ended_session_cannot_approve_an_mcp_client(): void
    {
        $staff = $this->staff();
        $clientId = $this->client();
        Auth::guard('staff')->login($staff);
        $row = StaffSession::query()->sole();

        // Ended in the list; the store still holds the session.
        $row->forceFill(['ended_at' => now()])->save();
        $this->app['auth']->forgetGuards();

        $location = (string) $this->post('/mcp/oauth/authorize', $this->authorizeQuery($clientId) + ['decision' => 'approve'])->headers->get('Location');

        $this->assertStringNotContainsString('code=', $location, 'an ended staff session approved an MCP client');
        $this->assertSame(0, McpOauthGrant::query()->count());
    }

    /**
     * The row's session_id is refreshed only by panel requests (StaffSessions::check). Filament's login page calls
     * session()->regenerate() right after the Login event wrote the row, and a customer login in the same browser
     * migrates the id too; until the next panel request the row holds an id that no longer exists. Ending the session
     * in that window destroys the old id and leaves the live session, with its staff login, in the store (usable outside
     * the panel, as above).
     */
    public function test_ending_destroys_the_live_session_after_its_id_was_regenerated(): void
    {
        $staff = $this->staff();
        $store = $this->app['session.store'];
        $store->start();

        // As the admin login page does: the login (its Login event writes the row), then session()->regenerate().
        Auth::guard('staff')->login($staff);
        $store->regenerate();
        $live = $store->getId();
        $store->save();
        // That login request ends (fix of #375: the row takes the session's id at the end of every request).
        StaffSessions::touch($store, request());
        $row = StaffSession::query()->sole();
        $handler = $store->getHandler();
        $this->assertNotSame('', (string) $handler->read($live));

        // An admin elsewhere ends it before that browser's next panel request.
        $admin = $this->staff(['admin.staff.viewAny', 'admin.staff.sessions']);
        $store->setId(str_repeat('a', 40)); // the admin's own request runs in another session
        StaffSessions::end($staff, [$row->fresh()], $admin, request());

        $this->assertSame('', (string) $handler->read($live), 'the live session (with the staff login) is still in the store after it was ended');
    }

    /**
     * A password change revokes the MCP grants the login approved (Staff::booted, "as the admin's own sessions end").
     * Ending all of a member's sessions (a lost laptop) does not: a connector approved from that laptop keeps working.
     * The remember_token rotation goes through saveQuietly, and no hook looks at it either way.
     */
    public function test_ending_all_of_a_members_sessions_revokes_the_mcp_grants_their_login_approved(): void
    {
        $staff = $this->staff();
        $clientId = $this->client();
        $location = (string) $this->actingAs($staff, 'staff')->post('/mcp/oauth/authorize', $this->authorizeQuery($clientId) + ['decision' => 'approve'])
            ->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->postJson('/mcp/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $query['code'],
            'redirect_uri' => self::REDIRECT, 'code_verifier' => $this->verifier])->assertOk();
        $this->assertSame('session', McpOauthGrant::query()->sole()->approved_by);
        StaffSession::query()->create(['staff_id' => $staff->id, 'session_id' => 'laptop-session', 'ip_address' => '198.51.100.9',
            'user_agent' => 'Laptop', 'remembered' => true, 'created_at' => now()->subHour(), 'last_seen_at' => now()->subMinute()]);
        $this->app['auth']->forgetGuards();

        $admin = $this->staff(['admin.staff.viewAny', 'admin.staff.sessions']);
        // The admin works from another browser: its own session, not the one that gave the consent.
        $this->app['session.store']->forget(StaffSessions::SESSION_KEY);
        $this->app['session.store']->setId(str_repeat('b', 40));
        Livewire::actingAs($admin, 'staff')->test(StaffSessionList::class, ['staffId' => $staff->id])->call('endOthers');

        // The consent's own session has a row too now (fix of #375): every one of them is ended.
        $this->assertSame(0, StaffSession::query()->whereNull('ended_at')->count());
        $this->assertNotNull(McpOauthGrant::query()->sole()->revoked_at, 'the MCP grant approved by the ended login is still live');
    }

    /**
     * The shared Logout action (client-area logout, LockSession, ResolveUserSession) invalidates the session before it
     * logs the staff guard out, so the Logout listener no longer finds the row's ulid in the session: the row stays open
     * and is listed as a live session (a remembered one, for good) although that browser is logged out.
     */
    public function test_the_shared_logout_action_ends_the_staff_row(): void
    {
        $staff = $this->staff();
        $this->app['session.store']->start();
        Auth::guard('staff')->login($staff, true);
        $row = StaffSession::query()->sole();

        app(Logout::class)->execute();

        $this->assertGuest('staff');
        $this->assertNotNull($row->fresh()->ended_at, 'the logged-out browser is still listed as an open session');
    }
}
