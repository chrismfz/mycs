<?php

namespace Tests\Feature;

use App\Admin\Resources\McpCallResource;
use App\Admin\Resources\McpCallResource\Pages\ListMcpCalls;
use App\Http\Middleware\McpAuth;
use App\Mcp\Servers\MycsServer;
use App\Mcp\Tools\HealthTool;
use App\Mcp\Tools\ServiceTool;
use App\Models\ApiKey;
use App\Models\McpCall;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The MCP server (docs/mcp.md): staff's only, behind an admin API key with «Use the MCP Server»; each tool offered to
 * who may see what it shows; every call recorded; no secret in an answer.
 */
class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $product = $this->createProduct();
        $user = User::factory()->create();
        $this->service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 5, 'quantity' => 1, 'username' => 'mcpuser', 'password' => 'Pa55word!mcp']);
    }

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function key(Staff $staff, array $permissions = [McpAuth::PERMISSION], array $attributes = []): string
    {
        $plain = 'mcp-test-' . bin2hex(random_bytes(16));
        ApiKey::query()->create(['name' => 'MCP', 'token' => hash('sha256', $plain), 'staff_id' => $staff->id, 'type' => 'admin',
            'permissions' => $permissions, 'enabled' => true] + $attributes);

        return $plain;
    }

    private function rpc(?string $bearer, string $method, array $params = [])
    {
        return $this->withHeaders(array_filter(['Authorization' => $bearer ? "Bearer {$bearer}" : null, 'Accept' => 'application/json, text/event-stream']))
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
    }

    public function test_only_an_admin_key_with_the_mcp_permission_gets_in(): void
    {
        $staff = $this->staff(['admin.mcp.use', 'admin.services.viewAny']);

        $this->rpc(null, 'tools/list')->assertStatus(401);
        $this->rpc('not-a-key', 'tools/list')->assertStatus(401);
        $this->rpc($this->key($staff, ['admin.services.view']), 'tools/list')->assertStatus(403);
        $this->rpc($this->key($staff, attributes: ['ip_addresses' => ['203.0.113.5']]), 'tools/list')->assertStatus(403);
        $this->rpc($this->key($this->staff(['admin.services.viewAny'])), 'tools/list')->assertStatus(403); // its owner may not
        $inactive = $this->staff(['*']);
        $inactive->update(['active' => false]);
        $this->rpc($this->key($inactive), 'tools/list')->assertStatus(403);

        $tools = collect($this->rpc($this->key($staff), 'tools/list')->assertOk()->json('result.tools'))->pluck('name')->all();
        $this->assertContains('health', $tools);
        $this->assertContains('service', $tools);
        $this->assertNotContains('invoice', $tools, 'its role may not see invoices');
    }

    public function test_a_tool_answers_with_the_record_and_no_secret_and_the_call_is_recorded(): void
    {
        $staff = $this->staff(['*']);

        $response = $this->rpc($this->key($staff), 'tools/call', ['name' => 'service', 'arguments' => ['service' => (string) $this->service->id]])->assertOk();

        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('"module_actions"', $text);
        $this->assertStringContainsString('mcpuser', $text);
        $this->assertStringNotContainsString('Pa55word', $text);
        $call = McpCall::query()->sole();
        $this->assertSame(['service', 'ok', $staff->id, ['service' => (string) $this->service->id]], [$call->tool, $call->outcome, $call->staff_id, $call->arguments]);
        $this->assertNotNull($call->api_key_id);
    }

    public function test_a_tool_the_role_may_not_use_is_refused_and_an_error_is_an_mcp_error(): void
    {
        MycsServer::actingAs($this->staff(['admin.mcp.use']), 'staff')->tool(ServiceTool::class, ['service' => (string) $this->service->id])->assertHasErrors();
        $this->assertSame(0, McpCall::query()->count(), 'not offered, so never run (the «denied» record is for a role changed between list and call)');

        MycsServer::actingAs($this->staff(['*']), 'staff')->tool(ServiceTool::class, ['service' => '999999'])->assertHasErrors(['No service 999999.']);
        $this->assertSame(['error', 'No service 999999.'], [McpCall::query()->latest('id')->value('outcome'), McpCall::query()->latest('id')->value('error')]);

        MycsServer::actingAs($this->staff(['*']), 'staff')->tool(HealthTool::class)->assertOk()->assertSee('"scheduler"');
    }

    public function test_a_key_is_limited_per_minute(): void
    {
        $staff = $this->staff(['*']);
        $plain = $this->key($staff);
        $key = ApiKey::query()->where('token', hash('sha256', $plain))->sole();
        for ($i = 0; $i < McpAuth::PER_MINUTE; $i++) {
            RateLimiter::hit('mcp:' . $key->id, 60);
        }

        $this->rpc($plain, 'tools/list')->assertStatus(429);
    }

    public function test_a_customers_token_never_opens_it(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer, 'api')->withHeaders(['Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertStatus(401);
    }

    public function test_staff_who_see_the_api_keys_see_the_calls(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        MycsServer::actingAs($this->staff(['*']), 'staff')->tool(HealthTool::class);

        $this->livewireAs($this->staff(['admin.api_keys.viewAny']))->test(ListMcpCalls::class)
            ->assertCanSeeTableRecords(McpCall::query()->get());
        $this->actingAs($this->staff(['admin.services.viewAny']), 'staff')->get(McpCallResource::getUrl('index'))->assertForbidden();
    }
}
