<?php

namespace Tests\Feature;

use App\Http\Controllers\McpOauthController;
use App\Models\Setting;
use App\Support\CallerIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Adversarial review of PR #369 (MCP/API limits by network, redirect hosts). */
class AdvApiGroup2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::query()->updateOrCreate(['key' => 'mcp_oauth_redirect_hosts', 'settingable_type' => null, 'settingable_id' => null], ['value' => 'example.test', 'type' => 'string']);
        config(['settings.mcp_oauth_redirect_hosts' => 'example.test']);
    }

    /**
     * ADV-1. An IPv4 client seen through a dual-stack socket arrives as an IPv4-mapped IPv6 address (::ffff:a.b.c.d,
     * e.g. a web server listening on [::] with ipv6only off). bucket() takes the first 64 bits, which are zero for
     * every such address: all IPv4 callers share ONE limit. One caller then exhausts registration, the MCP token
     * endpoint, the admin API's keyless limit, logins (CallerIp::key) for every other IPv4 caller. An IPv4-mapped
     * address should be bucketed as its IPv4 address.
     */
    public function test_ipv4_mapped_addresses_are_not_one_bucket(): void
    {
        $this->assertNotSame(CallerIp::bucket('::ffff:203.0.113.5'), CallerIp::bucket('::ffff:198.51.100.7'));
        $this->assertSame(CallerIp::bucket('203.0.113.5'), CallerIp::bucket('::ffff:203.0.113.5'));
    }

    /**
     * ADV-2. The global registration ceiling is hit before the request is checked: rejected registrations (a host
     * not on the allowlist, or no redirect URI at all) use it up. A single attacker with ten IPv6 /64s (one /56 is
     * 256 of them) sends 100 junk requests an hour and nobody can connect a new MCP client (claude.ai's connector
     * registers here when staff add it). Only an accepted registration should count against the shared ceiling.
     */
    public function test_rejected_registrations_do_not_use_up_the_global_ceiling(): void
    {
        $sent = 0;
        for ($net = 1; $sent < McpOauthController::REGISTER_PER_HOUR_ALL; $net++) {
            for ($i = 1; $i <= McpOauthController::REGISTER_PER_HOUR && $sent < McpOauthController::REGISTER_PER_HOUR_ALL; $i++, $sent++) {
                $this->withServerVariables(['REMOTE_ADDR' => "2001:db8:66:{$net}::{$i}"])
                    ->postJson('/mcp/oauth/register', ['client_name' => 'x', 'redirect_uris' => ['https://attacker.invalid/cb']])
                    ->assertStatus(400);
            }
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->postJson('/mcp/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.example.test/api/mcp/auth_callback']])
            ->assertCreated();
    }
}
