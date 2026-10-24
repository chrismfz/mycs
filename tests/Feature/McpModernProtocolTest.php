<?php

namespace Tests\Feature;

use App\Http\Middleware\McpAuth;
use App\Models\ApiKey;
use App\Models\Role;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * laravel/mcp 1.x speaks MCP 2026-07-28 (server/discover, protocol metadata and headers on every request) beside the
 * legacy initialize clients the other tests are. Both go through McpAuth and the tools' permissions alike.
 */
class McpModernProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const VERSION = '2026-07-28';

    private function key(array $permissions): string
    {
        $plain = 'mcp-test-' . bin2hex(random_bytes(16));
        ApiKey::query()->create(['name' => 'MCP', 'token' => hash('sha256', $plain),
            'staff_id' => Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id])->id,
            'type' => 'admin', 'permissions' => [McpAuth::PERMISSION], 'enabled' => true]);

        return $plain;
    }

    /** @param array<string, mixed> $params */
    private function modern(?string $key, string $method, array $params = [], ?string $name = null, ?string $headerMethod = null): \Illuminate\Testing\TestResponse
    {
        $params['_meta'] = ['io.modelcontextprotocol/protocolVersion' => self::VERSION, 'io.modelcontextprotocol/clientCapabilities' => (object) []];
        $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => self::VERSION, 'Mcp-Method' => $headerMethod ?? $method]
            + ($name !== null ? ['Mcp-Name' => $name] : []) + ($key !== null ? ['Authorization' => "Bearer {$key}"] : []);

        return $this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    public function test_a_modern_client_discovers_lists_and_calls(): void
    {
        $key = $this->key(['*']);

        $this->assertContains(self::VERSION, (array) $this->modern($key, 'server/discover')->assertOk()->json('result.supportedVersions'));
        $this->assertContains('health', collect($this->modern($key, 'tools/list')->assertOk()->json('result.tools'))->pluck('name')->all());

        $text = (string) $this->modern($key, 'tools/call', ['name' => 'health', 'arguments' => (object) []], 'health')->assertOk()->json('result.content.0.text');
        $this->assertStringContainsString('"_notice"', $text);
    }

    public function test_it_still_needs_a_key_and_the_permission(): void
    {
        $this->modern(null, 'tools/list')->assertUnauthorized();

        $names = collect($this->modern($this->key([McpAuth::PERMISSION]), 'tools/list')->assertOk()->json('result.tools'))->pluck('name')->all();
        $this->assertNotContains('ticket', $names);
    }

    public function test_headers_that_do_not_match_the_body_are_refused(): void
    {
        $this->modern($this->key(['*']), 'tools/call', ['name' => 'health', 'arguments' => (object) []], 'ticket')->assertStatus(400);
    }
}
