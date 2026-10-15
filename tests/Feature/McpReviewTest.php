<?php

namespace Tests\Feature;

use App\Http\Middleware\McpAuth;
use App\Models\ApiKey;
use App\Models\License;
use App\Models\McpCall;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of PR #142 (the MCP server, docs/mcp.md). Each test fails on the PR as opened and names its finding.
 */
class McpReviewTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $product = $this->createProduct();
        $user = User::factory()->create();
        $this->service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 5, 'quantity' => 1]);
    }

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function key(Staff $staff, array $attributes = []): string
    {
        $plain = 'mcp-review-' . bin2hex(random_bytes(16));
        ApiKey::query()->create(['name' => 'MCP', 'token' => hash('sha256', $plain), 'staff_id' => $staff->id, 'type' => 'admin',
            'permissions' => [McpAuth::PERMISSION], 'enabled' => true] + $attributes);

        return $plain;
    }

    private function rpc(string $bearer, string $method, array $params = [], array $headers = [])
    {
        return $this->withHeaders(['Authorization' => "Bearer {$bearer}", 'Accept' => 'application/json, text/event-stream'] + $headers)
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
    }

    /**
     * MCP-R1: with «Trusted Proxies» = * (a supported setting, ProxyMiddleware trusts REMOTE_ADDR), $request->ip() is
     * whatever the caller puts in X-Forwarded-For, so the key's IP allowlist is passed by naming the allowed address.
     * ZlicController::callerIp already refuses that (review ADV-L3); McpAuth does not.
     */
    public function test_r1_trust_all_proxies_does_not_let_x_forwarded_for_pass_the_ip_allowlist(): void
    {
        config(['settings.trusted_proxies' => ['*']]);
        $plain = $this->key($this->staff(['*']), ['ip_addresses' => ['203.0.113.5']]);

        $this->rpc($plain, 'tools/list', headers: ['X-Forwarded-For' => '203.0.113.5'])->assertStatus(403);
    }

    /**
     * MCP-R2: a license key is a credential (docs/mcp.md: «A license key comes out as its prefix and last 4»), but a
     * `service` lookup by license key stores it in full in mcp_calls.arguments (Redact masks the argument name `key`,
     * not `service`), shown in Other → MCP Calls to anyone with View API Keys, who need not see services.
     */
    public function test_r2_a_license_key_used_to_find_a_service_is_not_recorded_in_full(): void
    {
        $licenseKey = 'ZCWeb-A1B2C3D4E5F6G7H8';
        (new License)->forceFill(['service_id' => $this->service->id, 'license_key' => $licenseKey, 'status' => 'Active'])->save();

        $this->rpc($this->key($this->staff(['*'])), 'tools/call', ['name' => 'service', 'arguments' => ['service' => $licenseKey]])->assertOk();

        $call = McpCall::query()->sole();
        $this->assertSame('ok', $call->outcome);
        $this->assertStringNotContainsString($licenseKey, (string) json_encode($call->arguments));
    }

    /**
     * MCP-R3: the record stores whatever arguments the caller sends — names outside the tool's schema and values of any
     * size — so one key (120 calls a minute, each up to post_max_size) fills mcp_calls, kept 90 days. Keep only the
     * schema's arguments, each bounded.
     */
    public function test_r3_the_record_keeps_only_the_tools_arguments_and_bounded(): void
    {
        $this->rpc($this->key($this->staff(['*'])), 'tools/call', ['name' => 'health', 'arguments' => ['junk' => str_repeat('A', 200_000)]])->assertOk();

        $this->assertLessThan(10_000, strlen((string) json_encode(McpCall::query()->sole()->arguments)));
    }
}
