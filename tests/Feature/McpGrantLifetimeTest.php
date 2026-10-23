<?php

namespace Tests\Feature;

use App\Models\McpOauthGrant;
use App\Models\Setting;
use App\Models\Staff;
use App\Services\Mcp\McpOauth;
use App\Services\Ops\Health;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** «MCP grant lifetime» (owner 2026-10-24): days a connector's approval lasts however often refreshed; 0 until revoked. */
class McpGrantLifetimeTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://claude.example.test/cb';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::query()->updateOrCreate(['key' => 'mcp_oauth_redirect_hosts', 'settingable_type' => null, 'settingable_id' => null], ['value' => 'example.test', 'type' => 'string']);
        config(['settings.mcp_oauth_redirect_hosts' => 'example.test']);
    }

    /** A grant approved $days ago (its refresh token still valid), and that refresh token. @return array{McpOauthGrant, string, string} */
    private function grant(int $days): array
    {
        $verifier = str_repeat('v', 50);
        $clientId = $this->postJson('/mcp/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT]])->json('client_id');
        $location = (string) $this->actingAs(Staff::factory()->admin()->create(), 'staff')->post('/mcp/oauth/authorize', ['response_type' => 'code', 'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'state' => 's', 'resource' => url('/mcp'), 'decision' => 'approve'])->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->app['auth']->forgetGuards();
        $tokens = $this->postJson('/mcp/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $query['code'],
            'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier])->assertOk()->json();
        $grant = McpOauthGrant::query()->sole();
        $grant->forceFill(['created_at' => now()->subDays($days)])->save();

        return [$grant, $clientId, (string) $tokens['refresh_token']];
    }

    /** Stored as staff save it: the settings are read again on later requests. */
    private function days(int $days): void
    {
        Setting::query()->updateOrCreate(['key' => 'mcp_grant_days', 'settingable_type' => null, 'settingable_id' => null], ['value' => (string) $days, 'type' => 'integer']);
        config(['settings.mcp_grant_days' => $days]);
    }

    private function refresh(string $clientId, string $token)
    {
        return $this->postJson('/mcp/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $token]);
    }

    public function test_by_default_a_grant_ends_a_year_after_its_approval(): void
    {
        [$grant, $clientId, $token] = $this->grant(366);

        $this->refresh($clientId, $token)->assertStatus(400);
        $this->assertNotNull($grant->fresh()->revoked_at);
    }

    public function test_the_setting_sets_the_days(): void
    {
        $this->days(30);
        [, $clientId, $token] = $this->grant(31);

        $this->refresh($clientId, $token)->assertStatus(400);
    }

    public function test_zero_keeps_it_until_revoked_and_automation_status_warns(): void
    {
        $this->days(0);
        [$grant, $clientId, $token] = $this->grant(3000);

        $this->refresh($clientId, $token)->assertOk();
        $this->assertNull($grant->fresh()->revoked_at);
        $this->assertSame(0, McpOauth::grantDays());
        $this->assertStringContainsString('MCP grants never expire', Health::report()['checks']['runtime']['detail']);
    }
}
