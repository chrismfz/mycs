<?php

namespace Tests\Feature;

use App\Http\Middleware\McpAuth;
use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Adversarial review of the MCP read-only tools (ticket, domain, registrar_log, gateway_log, cron_history,
 * http_error_logs, audit_search).
 */
class AdvMcpReadToolsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $permissions): Staff
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

    private function mcp(string $key, string $tool, array $arguments = []): array
    {
        return $this->withHeaders(['Authorization' => "Bearer {$key}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]])
            ->assertOk()->json('result');
    }

    private function tool(string $key, string $tool, array $arguments = []): string
    {
        return (string) ($this->mcp($key, $tool, $arguments)['content'][0]['text'] ?? '');
    }

    /** ADV-1: the application errors are stored as type «exception» (bootstrap/app.php, ErrorLogResource), not «error». */
    public function test_adv1_the_error_log_shows_the_application_errors_the_admin_shows(): void
    {
        DB::table('debug_logs')->insert(['type' => 'exception', 'context' => json_encode(['message' => 'Division by zero in renewal', 'file' => '/app/X.php', 'line' => 12, 'trace' => '#0 main']),
            'created_at' => now(), 'updated_at' => now()]);

        $text = $this->tool($this->key($this->staff(['*'])), 'http_error_logs', ['type' => 'error']);

        $this->assertStringContainsString('Division by zero in renewal', $text, 'the Error Logs page lists this row; the tool returns none');
    }

    /**
     * ADV-2: «Never the EPP code»: a registrar's error or the last sync's error that echoes the domain's own EPP code
     * (an EPP <extValue> repeats the offending <domain:pw>) goes out as written; Scrub does not know a domain's code.
     */
    public function test_adv2_the_domains_own_epp_code_is_masked_in_its_errors(): void
    {
        $domain = Domain::factory()->create(['sld' => 'leak', 'tld' => 'gr', 'fqdn' => 'leak.gr', 'epp_code' => 'Zq8-EPP-77xk',
            'sync_error' => 'Info failed: <domain:pw>Zq8-EPP-77xk</domain:pw> rejected']);
        DB::table('domain_registrar_logs')->insert(['domain_id' => $domain->id, 'action' => 'renew', 'status' => 'failed',
            'error' => '2202 Invalid authorization information: <value><domain:pw>Zq8-EPP-77xk</domain:pw></value>', 'created_at' => now(), 'updated_at' => now()]);
        $key = $this->key($this->staff(['*']));

        $text = $this->tool($key, 'domain', ['domain' => 'leak.gr']) . $this->tool($key, 'registrar_log', ['domain' => 'leak.gr']);

        $this->assertStringContainsString('Invalid authorization information', $text);
        $this->assertStringNotContainsString('Zq8-EPP-77xk', $text);
    }

    /**
     * ADV-3: the domain tool lists its invoice lines (invoice, description, total) to staff who may see domains but not
     * invoices; the admin's domain page shows no invoice lines, and the gateway log hides invoice links without
     * admin.invoices.viewAny.
     */
    public function test_adv3_domain_invoice_lines_need_the_invoices_permission(): void
    {
        $domain = Domain::factory()->create(['sld' => 'billed', 'tld' => 'gr', 'fqdn' => 'billed.gr']);
        $invoice = Invoice::factory()->create(['client_id' => $domain->client_id]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'reference_type' => $domain->getMorphClass(), 'reference_id' => $domain->id,
            'description' => 'Renewal billed.gr SECRET-LINE', 'price' => 987.65, 'quantity' => 1]);
        $this->assertSame(1, $domain->invoiceItems()->count(), 'the fixture is one of the domain\'s lines');

        $text = $this->tool($this->key($this->staff([McpAuth::PERMISSION, 'admin.domains.viewAny'])), 'domain', ['domain' => 'billed.gr']);

        $this->assertStringContainsString('billed.gr', $text);
        $this->assertStringNotContainsString('SECRET-LINE', $text);
    }
}
