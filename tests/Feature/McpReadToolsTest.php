<?php

namespace Tests\Feature;

use App\Http\Middleware\McpAuth;
use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The read-only tools for debugging in production (owner 2026-10-24): tickets, domains and the registrar log, the
 * gateway log, the cron's history, the HTTP and error logs, and the audit. Each offered only with the permission of
 * what it shows, as in the admin, and never a secret.
 */
class McpReadToolsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function key(Staff $staff): string
    {
        $plain = 'mcp-test-' . bin2hex(random_bytes(16));
        ApiKey::query()->create(['name' => 'MCP', 'token' => hash('sha256', $plain), 'staff_id' => $staff->id, 'type' => 'admin',
            'permissions' => [McpAuth::PERMISSION], 'enabled' => true]);

        return $plain;
    }

    private function tool(string $key, string $tool, array $arguments = []): string
    {
        $response = $this->withHeaders(['Authorization' => "Bearer {$key}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]])->assertOk();

        return (string) $response->json('result.content.0.text');
    }

    /** @return list<string> */
    private function tools(string $key): array
    {
        return collect($this->withHeaders(['Authorization' => "Bearer {$key}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []])->json('result.tools'))->pluck('name')->all();
    }

    public function test_each_tool_is_offered_only_with_its_permission(): void
    {
        $new = ['ticket', 'domain', 'registrar_log', 'gateway_log', 'cron_history', 'http_error_logs', 'audit_search'];
        $none = $this->tools($this->key($this->staff([McpAuth::PERMISSION])));
        $all = $this->tools($this->key($this->staff(['*'])));

        $this->assertSame([], array_values(array_intersect($new, $none)));
        $this->assertSame($new, array_values(array_intersect($new, $all)));
        $this->assertSame(['ticket'], array_values(array_intersect($new, $this->tools($this->key($this->staff([McpAuth::PERMISSION, 'admin.tickets.viewAny']))))));
    }

    public function test_a_ticket_with_its_messages_and_notes(): void
    {
        $ticket = Ticket::factory()->create(['subject' => 'Cannot log in']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'The site is down since this morning', 'is_internal' => false]);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'Checked the server', 'is_internal' => true, 'staff_id' => $this->staff(['*'])->id]);

        $text = $this->tool($this->key($this->staff(['*'])), 'ticket', ['ticket' => (string) $ticket->id]);

        $this->assertStringContainsString('Cannot log in', $text);
        $this->assertStringContainsString('Checked the server', $text);
        $this->assertStringContainsString('"internal": true', $text);
        $this->assertStringContainsString('down since this morning', $text, 'the text as staff read it in the admin');
    }

    public function test_a_domain_and_its_registrar_calls_never_show_the_epp_code_or_requests(): void
    {
        $domain = Domain::factory()->create(['sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr', 'epp_code' => 'EPP-SECRET-123']);
        DB::table('domain_registrar_logs')->insert(['domain_id' => $domain->id, 'action' => 'renew', 'status' => 'failed', 'request' => '{"authcode":"REQ-SECRET"}',
            'response' => '{"error":"RESP-SECRET"}', 'error' => 'Not enough credit', 'created_at' => now(), 'updated_at' => now()]);
        $key = $this->key($this->staff(['*']));

        $text = $this->tool($key, 'domain', ['domain' => 'example.gr']) . $this->tool($key, 'registrar_log', ['status' => 'failed']);

        $this->assertStringContainsString('Not enough credit', $text);
        foreach (['EPP-SECRET-123', 'REQ-SECRET', 'RESP-SECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }

    public function test_the_gateway_log_masks_the_events_secrets(): void
    {
        DB::table('payment_gateway_events')->insert(['gateway' => 'PayPal', 'endpoint' => 'webhook', 'method' => 'POST', 'outcome' => 'rejected',
            'reason' => 'bad signature', 'fields' => json_encode(['txn_id' => 'TX1', 'password' => 'GW-SECRET']), 'signature_verified' => false,
            'created_at' => now(), 'updated_at' => now()]);

        $text = $this->tool($this->key($this->staff(['*'])), 'gateway_log');

        $this->assertStringContainsString('bad signature', $text);
        $this->assertStringContainsString('TX1', $text);
        $this->assertStringNotContainsString('GW-SECRET', $text);
    }

    public function test_the_cron_history_and_the_logs(): void
    {
        DB::table('cron_stats')->insert(['key' => 'invoices_created', 'value' => 7, 'date' => today()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('debug_logs')->insert(['type' => 'http', 'context' => json_encode(['method' => 'GET', 'url' => 'https://api.example.test/x?api_key=URL-SECRET', 'response_status' => 500]),
            'created_at' => now(), 'updated_at' => now()]);
        $key = $this->key($this->staff(['*']));

        $this->assertStringContainsString('"invoices_created": 7', $this->tool($key, 'cron_history'));
        $http = $this->tool($key, 'http_error_logs', ['type' => 'http']);
        $this->assertStringContainsString('api.example.test', $http);
        $this->assertStringNotContainsString('URL-SECRET', $http);
    }

    public function test_the_audit_search_finds_who_changed_what(): void
    {
        $staff = $this->staff(['*']);
        DB::table('audits')->insert(['user_type' => Staff::class, 'user_id' => $staff->id, 'event' => 'updated', 'auditable_type' => User::class, 'auditable_id' => 5,
            'old_values' => json_encode(['first_name' => 'Old']), 'new_values' => json_encode(['first_name' => 'New', 'password' => 'AUDIT-SECRET']),
            'created_at' => now(), 'updated_at' => now()]);

        $text = $this->tool($this->key($staff), 'audit_search', ['type' => 'User', 'id' => 5]);

        $this->assertStringContainsString('"New"', $text);
        $this->assertStringContainsString('Staff #' . $staff->id, $text);
        $this->assertStringNotContainsString('AUDIT-SECRET', $text);
    }
}
