<?php

namespace Tests\Feature;

use App\Models\McpOauthClient;
use App\Models\McpOauthGrant;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of PR #144 (OAuth for the MCP server). Each test fails on the PR as written and names the finding
 * (MCPO-*) it pins down.
 */
class McpOauthReviewTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://claude.example.test/api/mcp/auth_callback';

    private string $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        // The tests' clients come back to example.test hosts (the allowed list: security audit API-5).
        Setting::query()->updateOrCreate(['key' => 'mcp_oauth_redirect_hosts', 'settingable_type' => null, 'settingable_id' => null], ['value' => 'example.test, localhost, 127.0.0.1', 'type' => 'string']);
        config(['settings.mcp_oauth_redirect_hosts' => 'example.test, localhost, 127.0.0.1']);
        $this->verifier = str_repeat('v', 20) . bin2hex(random_bytes(16));
    }

    private function staff(array $permissions = ['*']): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function client(string $redirect = self::REDIRECT): string
    {
        return $this->postJson('/mcp/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$redirect]])->assertCreated()->json('client_id');
    }

    private function challenge(): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    }

    /** @return array<string, string> */
    private function authorizeQuery(string $clientId, string $redirect = self::REDIRECT): array
    {
        return ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect, 'code_challenge' => $this->challenge(),
            'code_challenge_method' => 'S256', 'state' => 'st4te'];
    }

    private function codeFrom(?string $location): ?string
    {
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

        return isset($query['code']) ? (string) $query['code'] : null;
    }

    private function approve(string $clientId, Staff $staff): string
    {
        $location = $this->actingAs($staff, 'staff')->post('/mcp/oauth/authorize', $this->authorizeQuery($clientId) + ['decision' => 'approve'])
            ->assertRedirect()->headers->get('Location');
        $this->app['auth']->forgetGuards();

        return (string) $this->codeFrom($location);
    }

    private function exchange(string $clientId, string $code)
    {
        return $this->postJson('/mcp/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code,
            'redirect_uri' => self::REDIRECT, 'code_verifier' => $this->verifier]);
    }

    private function refresh(string $clientId, string $refresh)
    {
        return $this->postJson('/mcp/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $refresh]);
    }

    private function tools(string $access)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$access}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []]);
    }

    /**
     * MCPO-1: registration is open, any https redirect URI is accepted, and an authorization request error is redirected
     * at once, with no page and no staff: /mcp/oauth/authorize on MyCS's own domain becomes an open redirector to any
     * site (RFC 9700 §4.11.2). Phishing links then start on the trusted host.
     */
    public function test_mcpo1_an_unapproved_client_cannot_use_the_authorize_endpoint_as_an_open_redirector(): void
    {
        $clientId = $this->client('https://phish.example.test/landing');

        $response = $this->get('/mcp/oauth/authorize?' . http_build_query(['response_type' => 'token'] + $this->authorizeQuery($clientId, 'https://phish.example.test/landing')));

        $this->assertFalse(
            $response->isRedirect() && str_starts_with((string) $response->headers->get('Location'), 'https://phish.example.test/'),
            'Anyone can bounce a visitor off /mcp/oauth/authorize to a host of their choosing, no click needed.'
        );
    }

    /**
     * MCPO-2: the consent page names the return host as PHP's parse_url sees it, and the browser follows the WHATWG URL
     * parser. A backslash (special schemes treat it as '/') or userinfo tricks make the page show a trusted host while
     * the code goes elsewhere; a non-ASCII (homoglyph) host is shown as is.
     */
    public function test_mcpo2_redirect_uris_whose_host_a_browser_reads_differently_are_refused(): void
    {
        foreach ([
            'https://evil.example.test:443\\@claude.example.test/cb', // parse_url: claude.example.test; browser: evil.example.test
            'https://evil.example.test\\.claude.example.test/cb',     // browser: evil.example.test, path /.claude.example.test/cb
            'https://clаude.example.test/cb',                          // Cyrillic «а»: shown as claude.example.test
        ] as $uri) {
            $this->postJson('/mcp/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$uri]])
                ->assertStatus(400, 'Accepted a redirect URI whose host the consent page misstates: ' . json_encode($uri));
        }
    }

    /**
     * MCPO-3: the consent page is in the `web` group, whose LockSession checks the customers' guard (no customer: it
     * does nothing), and it has no AuthenticateSession. A staff session that the admin itself would end (the IP/agent
     * lock, or a password change) still approves here, and buys a 90-day, self-renewing MCP grant that outlives logout.
     */
    public function test_mcpo3_a_staff_session_the_admin_would_end_by_ip_lock_cannot_approve(): void
    {
        config(['settings.session_validation' => 'ip_admin']);
        $clientId = $this->client();

        $location = $this->actingAs($this->staff(), 'staff')
            ->withSession(['login_ip' => '203.0.113.9']) // logged in elsewhere; this request comes from 127.0.0.1
            ->post('/mcp/oauth/authorize', $this->authorizeQuery($clientId) + ['decision' => 'approve'])->headers->get('Location');

        $this->assertNull($this->codeFrom($location), 'A session locked to another address approved an MCP grant.');
    }

    public function test_mcpo3_a_staff_session_invalidated_by_a_password_change_cannot_approve(): void
    {
        $clientId = $this->client();

        $location = $this->actingAs($this->staff(), 'staff')
            ->withSession(['password_hash_staff' => 'hash-of-the-old-password'])
            ->post('/mcp/oauth/authorize', $this->authorizeQuery($clientId) + ['decision' => 'approve'])->headers->get('Location');

        $this->assertNull($this->codeFrom($location), 'A session the admin logs out (AuthenticateSession) approved an MCP grant.');
    }

    /**
     * MCPO-4: replay detection looks one generation back only. A thief who refreshes a stolen token twice before the
     * legitimate client's next refresh is never detected: the client's stale token matches neither hash, nothing is
     * revoked, and the thief keeps a grant that slides 90 days on every refresh (docs/mcp.md: «A refresh token used
     * twice revokes the grant»).
     */
    public function test_mcpo4_a_stolen_refresh_token_rotated_twice_is_still_detected(): void
    {
        $clientId = $this->client();
        $stolen = $this->exchange($clientId, $this->approve($clientId, $this->staff()))->assertOk()->json('refresh_token');

        $thief = $this->refresh($clientId, $stolen)->assertOk()->json();
        $thief = $this->refresh($clientId, $thief['refresh_token'])->assertOk()->json();

        $this->refresh($clientId, $stolen)->assertStatus(400); // the legitimate client, an hour later
        $this->assertNotNull(McpOauthGrant::query()->sole()->revoked_at, 'The replay of a used refresh token went unnoticed.');
        $this->tools($thief['access_token'])->assertStatus(401);
    }

    /**
     * MCPO-5: a replayed authorization code is refused but the tokens it already bought stay valid (RFC 6749 §4.1.2,
     * RFC 9700 §4.2.4: SHOULD revoke). A replay means the code leaked; the grant it made is suspect.
     */
    public function test_mcpo5_a_replayed_code_revokes_the_grant_it_made(): void
    {
        $clientId = $this->client();
        $code = $this->approve($clientId, $this->staff());
        $tokens = $this->exchange($clientId, $code)->assertOk()->json();

        $this->exchange($clientId, $code)->assertStatus(400);

        $this->assertNotNull(McpOauthGrant::query()->sole()->revoked_at);
        $this->tools($tokens['access_token'])->assertStatus(401);
    }

    /**
     * MCPO-6: dynamic registration is unauthenticated and clients are never pruned (only codes and grants are). Ten an
     * hour per address, and IPv6 gives everyone many: mcp_oauth_clients grows without bound.
     */
    public function test_mcpo6_clients_that_never_got_a_grant_are_pruned(): void
    {
        $client = McpOauthClient::query()->create(['client_id' => 'mcpc_' . str_repeat('x', 32), 'name' => 'Junk',
            'redirect_uris' => [self::REDIRECT], 'registered_ip' => '198.51.100.7']);
        $client->forceFill(['created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)])->save();

        $this->artisan('app:cron-job');

        $this->assertNull(McpOauthClient::query()->find($client->id), 'A month-old client with no grant is kept forever.');
    }
}
