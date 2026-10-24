<?php

namespace Tests\Feature;

use App\Http\Middleware\McpAuth;
use App\Models\ApiKey;
use App\Models\McpCall;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Review of #435 (laravel/mcp 1.1): the legacy initialize handshake end to end with an OAuth token, the call records and
 * the answers' shape under both protocols, and what reaches an unauthenticated caller now that ValidateMcpHeaders runs
 * before McpAuth.
 */
class AdvMcpModernProtocolReviewTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://claude.example.test/api/mcp/auth_callback';

    private const MODERN = '2026-07-28';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::query()->updateOrCreate(['key' => 'mcp_oauth_redirect_hosts', 'settingable_type' => null, 'settingable_id' => null], ['value' => 'example.test', 'type' => 'string']);
        config(['settings.mcp_oauth_redirect_hosts' => 'example.test']);
    }

    private function staff(array $permissions = ['*']): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function key(Staff $staff): string
    {
        $plain = 'mcp-adv-' . bin2hex(random_bytes(16));
        ApiKey::query()->create(['name' => 'MCP', 'token' => hash('sha256', $plain), 'staff_id' => $staff->id, 'type' => 'admin',
            'permissions' => [McpAuth::PERMISSION], 'enabled' => true]);

        return $plain;
    }

    /** The claude.ai connector's way in: register, consent, code with PKCE, token. */
    private function oauthToken(Staff $staff): string
    {
        $verifier = str_repeat('v', 20) . bin2hex(random_bytes(16));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $clientId = $this->postJson('/mcp/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT]])->assertCreated()->json('client_id');
        $location = $this->actingAs($staff, 'staff')->post('/mcp/oauth/authorize', ['response_type' => 'code', 'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 's',
            'resource' => url('/mcp'), 'decision' => 'approve'])->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
        $this->app['auth']->forgetGuards();

        return $this->postJson('/mcp/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $query['code'],
            'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier])->assertOk()->json('access_token');
    }

    /** @param array<string, string> $headers */
    private function send(?string $bearer, array $body, array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders($headers + ['Accept' => 'application/json, text/event-stream'] + ($bearer !== null ? ['Authorization' => "Bearer {$bearer}"] : []))
            ->postJson('/mcp', $body);
    }

    /** @param array<string, mixed> $params */
    private function modern(?string $bearer, string $method, array $params = [], ?string $name = null): TestResponse
    {
        $params['_meta'] = ['io.modelcontextprotocol/protocolVersion' => self::MODERN, 'io.modelcontextprotocol/clientCapabilities' => (object) []];

        return $this->send($bearer, ['jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => $params],
            ['MCP-Protocol-Version' => self::MODERN, 'Mcp-Method' => $method] + ($name !== null ? ['Mcp-Name' => $name] : []));
    }

    public function test_the_legacy_handshake_end_to_end_with_an_oauth_token(): void
    {
        $staff = $this->staff();
        $token = $this->oauthToken($staff);

        foreach (['2025-06-18', '2025-11-25', '2025-03-26'] as $version) {
            $init = $this->send($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => $version,
                'capabilities' => (object) [], 'clientInfo' => ['name' => 'claude-ai', 'version' => '0.1.0']]])->assertOk();
            $this->assertSame($version, $init->json('result.protocolVersion'));
            $this->assertSame('mycs', $init->json('result.serverInfo.name'));
            $this->assertArrayHasKey('tools', (array) $init->json('result.capabilities'));

            // The client then sends the negotiated version as a header, and nothing else of the new protocol.
            $after = ['MCP-Protocol-Version' => $version];
            $this->send($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $after)->assertStatus(202);
            $tools = $this->send($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => (object) []], $after)->assertOk();
            $this->assertCount(22, (array) $tools->json('result.tools'), 'every tool in one page');
            $this->assertNull($tools->json('result.nextCursor'));

            $call = $this->send($token, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'health', 'arguments' => (object) []]], $after)->assertOk();
            $this->assertFalse((bool) $call->json('result.isError'));
            $this->assertStringStartsWith("{\n    \"_notice\"", (string) $call->json('result.content.0.text'));
        }

        $this->assertSame(3, McpCall::query()->where(['tool' => 'health', 'outcome' => 'ok', 'staff_id' => $staff->id])->count());
    }

    public function test_a_modern_call_is_recorded_with_its_own_arguments_only(): void
    {
        $staff = $this->staff();
        $key = $this->key($staff);

        $this->modern($key, 'tools/call', ['name' => 'failed_jobs', 'arguments' => ['limit' => 5]], 'failed_jobs')->assertOk();
        $this->modern($key, 'tools/call', ['name' => 'service', 'arguments' => ['service' => '999999']], 'service')->assertOk()->assertJsonPath('result.isError', true);

        $calls = McpCall::query()->orderBy('id')->get();
        $this->assertSame([['failed_jobs', 'ok', ['limit' => 5]], ['service', 'error', ['service' => '999999']]],
            $calls->map(fn (McpCall $c) => [$c->tool, $c->outcome, $c->arguments])->all());
        $this->assertSame([$staff->id, $staff->id], $calls->pluck('staff_id')->all());
        $this->assertNotNull($calls[0]->api_key_id);
    }

    public function test_modern_paging_and_permissions_are_the_legacy_ones(): void
    {
        $key = $this->key($this->staff([McpAuth::PERMISSION, 'admin.services.viewAny']));
        $legacy = collect($this->send($key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []])->json('result.tools'))->pluck('name')->all();
        $modern = $this->modern($key, 'tools/list')->assertOk();

        $this->assertSame($legacy, collect($modern->json('result.tools'))->pluck('name')->all());
        $this->assertNull($modern->json('result.nextCursor'));
        $this->assertNotContains('invoice', $legacy);

        // A tool the role may not use is not found, and is not run or recorded.
        $hidden = $this->modern($key, 'tools/call', ['name' => 'invoice', 'arguments' => ['invoice' => '1']], 'invoice');
        $this->assertNotNull($hidden->json('error'));
        $this->assertSame(0, McpCall::query()->count());
    }

    public function test_an_unauthenticated_caller_learns_nothing_new(): void
    {
        // No bearer, and the request's own values mismatched: refused by ValidateMcpHeaders before McpAuth, with no more than
        // what the caller sent.
        $mismatch = $this->send(null, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'health',
            '_meta' => ['io.modelcontextprotocol/protocolVersion' => self::MODERN, 'io.modelcontextprotocol/clientCapabilities' => (object) []]]],
            ['MCP-Protocol-Version' => self::MODERN, 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'nope'])->assertStatus(400);
        $this->assertSame(-32020, $mismatch->json('error.code'));
        $this->assertStringNotContainsString('mycs', strtolower((string) $mismatch->getContent()));

        // Anything consistent reaches McpAuth: a 401 naming the metadata, nothing of the server.
        foreach (['server/discover', 'tools/list'] as $method) {
            $denied = $this->modern(null, $method)->assertUnauthorized();
            $this->assertStringContainsString('resource_metadata="' . url('/.well-known/oauth-protected-resource/mcp') . '"', (string) $denied->headers->get('WWW-Authenticate'));
            $this->assertSame(['error'], array_keys((array) $denied->json()));
        }
        $this->send(null, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']])->assertUnauthorized();
    }

    public function test_laravel_mcps_own_oauth_and_registration_are_not_exposed(): void
    {
        $this->get('/.well-known/oauth-authorization-server')->assertOk()->assertJsonPath('registration_endpoint', url('/mcp/oauth/register'));
        $this->get('/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJsonPath('resource', url('/mcp'));
        $this->get('/.well-known/oauth-authorization-server/mcp')->assertNotFound();
        $this->assertContains($this->postJson('/oauth/register', ['redirect_uris' => [self::REDIRECT]])->status(), [404, 405], 'the social login GET route only');
        $this->get('/mcp/oauth/github/client-metadata.json')->assertNotFound();
    }
}
