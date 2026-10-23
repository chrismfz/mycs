<?php

namespace Tests\Feature;

use App\Admin\Resources\McpOauthGrantResource\Pages\ListMcpOauthGrants;
use App\Models\ApiKey;
use App\Models\McpCall;
use App\Models\McpOauthGrant;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Mcp\McpOauth;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * OAuth for the MCP server (docs/mcp.md), the way the claude.ai connector goes: discovery from the 401, registration,
 * a consent staff approve (logged in to the admin, or with a pasted key), the code with PKCE, tokens that are checked
 * again on every call, rotated refresh tokens whose replay revokes the grant, and revocation from the admin.
 */
class McpOauthTest extends TestCase
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

    private function key(Staff $staff, array $attributes = []): array
    {
        $plain = 'mcp-key-' . bin2hex(random_bytes(16));
        $key = ApiKey::query()->create(['name' => 'Agent', 'token' => hash('sha256', $plain), 'staff_id' => $staff->id, 'type' => 'admin',
            'permissions' => ['admin.mcp.use'], 'enabled' => true] + $attributes);

        return [$plain, $key];
    }

    private function client(): string
    {
        return $this->postJson('/mcp/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT]])->assertCreated()->json('client_id');
    }

    private function challenge(): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    }

    /** @return array<string, string> */
    private function authorizeQuery(string $clientId): array
    {
        return ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT, 'code_challenge' => $this->challenge(),
            'code_challenge_method' => 'S256', 'state' => 'st4te', 'resource' => url('/mcp')];
    }

    /** Approves as $staff (logged in) and returns the code from the redirect. */
    private function approve(string $clientId, Staff $staff): string
    {
        $location = $this->actingAs($staff, 'staff')->post('/mcp/oauth/authorize', $this->authorizeQuery($clientId) + ['decision' => 'approve'])
            ->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith(self::REDIRECT, $location);
        $this->assertSame('st4te', $query['state']);

        return $query['code'];
    }

    private function exchange(string $clientId, string $code, ?string $verifier = null)
    {
        return $this->postJson('/mcp/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code,
            'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier ?? $this->verifier]);
    }

    private function tools(string $access)
    {
        // The staff guard of an earlier request must not carry over (each call authenticates on its own).
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$access}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []]);
    }

    public function test_discovery_starts_from_the_401(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="' . url('/.well-known/oauth-protected-resource/mcp') . '"');
        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJson(['resource' => url('/mcp')]);
        $this->getJson('/.well-known/oauth-protected-resource')->assertOk()->assertJson(['resource' => url('/mcp'), 'authorization_servers' => [url('/')]]);
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJson(['code_challenge_methods_supported' => ['S256'], 'registration_endpoint' => url('/mcp/oauth/register')]);
    }

    public function test_registration_takes_https_or_localhost_redirects_only_and_is_throttled(): void
    {
        $this->postJson('/mcp/oauth/register', ['redirect_uris' => ['http://evil.example.test/cb']])->assertStatus(400)->assertJson(['error' => 'invalid_redirect_uri']);
        $this->postJson('/mcp/oauth/register', ['redirect_uris' => ['https://a.example.test/cb#x']])->assertStatus(400);
        $this->postJson('/mcp/oauth/register', ['redirect_uris' => ['http://127.0.0.1:33418/callback']])->assertCreated();
        $this->postJson('/mcp/oauth/register', ['redirect_uris' => [self::REDIRECT], 'token_endpoint_auth_method' => 'client_secret_basic'])->assertStatus(400);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/mcp/oauth/register', ['redirect_uris' => [self::REDIRECT]]);
        }
        $this->postJson('/mcp/oauth/register', ['redirect_uris' => [self::REDIRECT]])->assertStatus(429);
    }

    public function test_a_bad_client_or_redirect_is_never_redirected_to_and_a_bad_request_is_told_at_the_client(): void
    {
        $clientId = $this->client();

        $this->get('/mcp/oauth/authorize?' . http_build_query(['client_id' => 'nope'] + $this->authorizeQuery($clientId)))->assertStatus(400);
        $this->get('/mcp/oauth/authorize?' . http_build_query(['redirect_uri' => 'https://evil.example.test/cb'] + $this->authorizeQuery($clientId)))->assertStatus(400);
        // Told at the client, but as a link on a page: never an automatic redirect (review MCPO-1).
        $this->get('/mcp/oauth/authorize?' . http_build_query(['code_challenge_method' => 'plain'] + $this->authorizeQuery($clientId)))
            ->assertStatus(400)->assertSee(e(self::REDIRECT . '?error=invalid_request'), false);
    }

    public function test_the_consent_asks_to_log_in_or_for_a_key_and_shows_approve_to_staff_who_may(): void
    {
        $clientId = $this->client();
        $url = '/mcp/oauth/authorize?' . http_build_query($this->authorizeQuery($clientId));

        $this->get($url)->assertOk()->assertSee('Log in to the admin to approve')->assertSee('paste an admin API key')
            ->assertHeader('X-Frame-Options', 'DENY')->assertSee('claude.example.test');
        // A customer is nobody here: never a code (the client area may send them home first).
        $location = (string) $this->actingAs(User::factory()->create(), 'web')
            ->post('/mcp/oauth/authorize', $this->authorizeQuery($clientId) + ['decision' => 'approve'])->headers->get('Location');
        $this->assertStringNotContainsString('code=', $location);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff(['admin.services.viewAny']), 'staff')->get($url)->assertOk()->assertSee('may not use the MCP server');
        $this->actingAs($this->staff(), 'staff')->get($url)->assertOk()->assertSee('Logged in as');
    }

    public function test_the_whole_flow_and_the_token_acts_as_the_staff_member(): void
    {
        $staff = $this->staff();
        $clientId = $this->client();
        $code = $this->approve($clientId, $staff);

        $this->exchange($clientId, $code, str_repeat('w', 50))->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
        $this->exchange($clientId, $code)->assertStatus(400); // the failed try used it

        $code = $this->approve($clientId, $staff);
        $tokens = $this->exchange($clientId, $code)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertStringStartsWith(McpOauth::ACCESS_PREFIX, $tokens['access_token']);
        $this->assertContains('health', collect($this->tools($tokens['access_token'])->assertOk()->json('result.tools'))->pluck('name')->all());
        $grant = McpOauthGrant::query()->sole();
        $this->assertSame([$staff->id, 'session', null], [$grant->staff_id, $grant->approved_by, $grant->api_key_id]);
        $this->assertNull(McpCall::query()->first(), 'listing is not a call');

        $this->exchange($clientId, $code)->assertStatus(400)->assertJson(['error' => 'invalid_grant']); // single use
        $this->tools($tokens['access_token'])->assertStatus(401); // and its replay revokes what it bought (MCPO-5)
    }

    public function test_refresh_rotates_and_a_replay_revokes_the_grant(): void
    {
        $clientId = $this->client();
        $first = $this->exchange($clientId, $this->approve($clientId, $this->staff()))->json();

        $second = $this->postJson('/mcp/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $first['refresh_token']])->assertOk()->json();
        $this->tools($first['access_token'])->assertStatus(401); // replaced
        $this->tools($second['access_token'])->assertOk();

        $this->postJson('/mcp/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $first['refresh_token']])->assertStatus(400);
        $this->assertSame('Refresh token replayed', McpOauthGrant::query()->sole()->revoked_reason);
        $this->tools($second['access_token'])->assertStatus(401);
        $this->postJson('/mcp/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $second['refresh_token']])->assertStatus(400);
    }

    public function test_an_access_token_expires_in_an_hour(): void
    {
        $clientId = $this->client();
        $tokens = $this->exchange($clientId, $this->approve($clientId, $this->staff()))->json();

        Carbon::setTestNow(now()->addSeconds(McpOauth::ACCESS_SECONDS + 1));
        $this->tools($tokens['access_token'])->assertStatus(401);
        Carbon::setTestNow();
    }

    public function test_approved_with_a_key_the_grant_lives_and_dies_with_the_key(): void
    {
        $staff = $this->staff();
        [$plain, $key] = $this->key($staff);
        $clientId = $this->client();

        $location = $this->post('/mcp/oauth/authorize', $this->authorizeQuery($clientId) + ['decision' => 'approve', 'api_key' => $plain])
            ->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $tokens = $this->exchange($clientId, $query['code'])->assertOk()->json();
        $this->assertSame(['key', $key->id], [McpOauthGrant::query()->sole()->approved_by, McpOauthGrant::query()->sole()->api_key_id]);
        $this->tools($tokens['access_token'])->assertOk();

        $key->update(['enabled' => false]);
        $this->tools($tokens['access_token'])->assertStatus(401);
        $this->assertNotNull(McpOauthGrant::query()->sole()->revoked_at);
    }

    public function test_a_wrong_key_is_refused_and_tries_are_limited(): void
    {
        $clientId = $this->client();
        for ($i = 0; $i < 10; $i++) {
            $this->post('/mcp/oauth/authorize', $this->authorizeQuery($clientId) + ['decision' => 'approve', 'api_key' => 'wrong-' . $i])->assertRedirect();
        }
        [$plain] = $this->key($this->staff());

        $this->post('/mcp/oauth/authorize', $this->authorizeQuery($clientId) + ['decision' => 'approve', 'api_key' => $plain])
            ->assertRedirectContains('/mcp/oauth/authorize');
        $this->assertSame(0, McpOauthGrant::query()->count());
        $this->assertStringContainsString('Too many tries', (string) session('mcp_key_error'));
    }

    public function test_deny_tells_the_client(): void
    {
        $clientId = $this->client();

        $location = $this->actingAs($this->staff(), 'staff')->post('/mcp/oauth/authorize', $this->authorizeQuery($clientId) + ['decision' => 'deny'])
            ->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('error=access_denied', $location);
    }

    public function test_staff_who_lose_the_permission_lose_the_token(): void
    {
        $staff = $this->staff(['admin.mcp.use']);
        $clientId = $this->client();
        $tokens = $this->exchange($clientId, $this->approve($clientId, $staff))->json();
        $this->tools($tokens['access_token'])->assertOk();

        $staff->role->update(['permissions' => ['admin.services.viewAny']]);
        $this->tools($tokens['access_token'])->assertStatus(401);
    }

    public function test_staff_revoke_their_own_authorization_in_the_admin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = $this->staff(['admin.mcp.use']);
        $clientId = $this->client();
        $tokens = $this->exchange($clientId, $this->approve($clientId, $staff))->json();
        $other = $this->staff(['admin.mcp.use']);

        $grant = McpOauthGrant::query()->sole();
        $this->livewireAs($other)->test(ListMcpOauthGrants::class)->assertCanNotSeeTableRecords([$grant]);
        $this->livewireAs($staff)->test(ListMcpOauthGrants::class)->assertCanSeeTableRecords([$grant])->callTableAction('revoke', $grant);

        $this->assertNotNull($grant->fresh()->revoked_at);
        $this->tools($tokens['access_token'])->assertStatus(401);
    }

    public function test_a_new_password_revokes_what_the_login_approved(): void
    {
        $staff = $this->staff();
        $clientId = $this->client();
        $tokens = $this->exchange($clientId, $this->approve($clientId, $staff))->json();

        $staff->update(['password' => 'An0ther-Passw0rd!']);

        $this->assertSame('Password changed', McpOauthGrant::query()->sole()->revoked_reason);
        $this->tools($tokens['access_token'])->assertStatus(401);
    }
}
