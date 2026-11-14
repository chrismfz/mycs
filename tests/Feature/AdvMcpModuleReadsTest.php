<?php

namespace Tests\Feature;

use App\Http\Middleware\McpAuth;
use App\Models\ApiKey;
use App\Models\CpanelAccount;
use App\Models\CpanelLveSample;
use App\Models\MailcowDomain;
use App\Models\Product;
use App\Models\ProxmoxGuest;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of #858 (service_module, server_accounts): each test fails on the PR's code.
 */
class AdvMcpModuleReadsTest extends TestCase
{
    use RefreshDatabase;

    private function key(): string
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['*']])->id]);
        $plain = 'mcp-test-' . bin2hex(random_bytes(16));
        ApiKey::query()->create(['name' => 'MCP', 'token' => hash('sha256', $plain), 'staff_id' => $staff->id, 'type' => 'admin',
            'permissions' => [McpAuth::PERMISSION], 'enabled' => true]);

        return $plain;
    }

    /** @return array<string, mixed> */
    private function ask(string $key, string $tool, array $arguments = []): array
    {
        $response = $this->withHeaders(['Authorization' => "Bearer {$key}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]])->assertOk();
        $this->assertNotTrue($response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return (array) json_decode((string) $response->json('result.content.0.text'), true);
    }

    private function service(Server $server, string $domain): Service
    {
        $product = Product::factory()->create(['name' => 'Plan', 'server_id' => $server->id]);

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'product_id' => $product->id, 'server_id' => $server->id,
            'status' => Service::STATUS_ACTIVE, 'domain' => $domain]);
    }

    /**
     * ADV-858-2: `show` filters that do not apply to Proxmox (differs, mail_held, near_limits) are ignored without a word:
     * every guest comes back under «show: differs», read by an agent as «all of these differ from MyCS».
     */
    public function test_adv_858_2_a_show_filter_proxmox_has_not_lists_no_guest_as_if_it_matched(): void
    {
        $server = Server::query()->create(['name' => 'vega', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        $service = $this->service($server, 'vps.test');
        ProxmoxGuest::query()->create(['server_id' => $server->id, 'vmid' => 101, 'node' => 'vega', 'name' => 'fine', 'status' => 'running',
            'service_id' => $service->id, 'last_seen_at' => now()]);
        $key = $this->key();

        foreach (['differs', 'mail_held', 'near_limits'] as $show) {
            $data = $this->ask($key, 'server_accounts', ['server' => 'vega', 'show' => $show]);
            $this->assertSame([], $data['guests'] ?? [], "show={$show}: a running, mapped guest matches nothing of it");
        }
    }

    /** ADV-858-2b: the same on mailcow (differs, mail_held). */
    public function test_adv_858_2b_a_show_filter_mailcow_has_not_lists_no_domain_as_if_it_matched(): void
    {
        $server = Server::query()->create(['name' => 'mx', 'extension' => 'Mailcow', 'type' => 'server', 'enabled' => true]);
        $service = $this->service($server, 'example.org');
        MailcowDomain::query()->create(['server_id' => $server->id, 'domain' => 'example.org', 'active' => true, 'service_id' => $service->id, 'last_seen_at' => now()]);
        $key = $this->key();

        foreach (['differs', 'mail_held'] as $show) {
            $data = $this->ask($key, 'server_accounts', ['server' => 'mx', 'show' => $show]);
            $this->assertSame([], $data['domains'] ?? [], "show={$show}");
        }
    }

    /**
     * ADV-858-3: `not_mapped` on Proxmox lists templates, which the admin's «Not mapped» (ProxmoxGuest::whereUnmapped)
     * and the stray-account tasks leave out: a template is never a customer's VM.
     */
    public function test_adv_858_3_a_template_is_not_an_unmapped_guest(): void
    {
        $server = Server::query()->create(['name' => 'vega', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        ProxmoxGuest::query()->create(['server_id' => $server->id, 'vmid' => 9000, 'node' => 'vega', 'name' => 'debian-tpl', 'status' => 'stopped',
            'template' => true, 'last_seen_at' => now()]);
        ProxmoxGuest::query()->create(['server_id' => $server->id, 'vmid' => 102, 'node' => 'vega', 'name' => 'stray', 'status' => 'running', 'last_seen_at' => now()]);

        $data = $this->ask($this->key(), 'server_accounts', ['server' => 'vega', 'show' => 'not_mapped']);

        $this->assertSame(['stray'], array_column($data['guests'], 'name'));
    }

    /**
     * ADV-858-4: by an LVE peak, a suspended account's readings from before its suspension sort it first, while its row
     * says lve_peak_24h null (as lve(): a suspended account's readings are not what it uses now). The top of «who uses the
     * server» is an account that uses nothing.
     */
    public function test_adv_858_4_a_suspended_account_does_not_top_the_lve_order(): void
    {
        $server = Server::query()->create(['name' => 'Orion', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['stopped' => [true, 100], 'busy' => [false, 60]] as $user => [$suspended, $cpu]) {
            $account = CpanelAccount::query()->create(['server_id' => $server->id, 'user' => $user, 'owner' => 'root', 'suspended' => $suspended,
                'last_seen_at' => now(), 'lve_read_at' => now()]);
            CpanelLveSample::query()->create(['cpanel_account_id' => $account->id, 'sampled_at' => now()->subHours(5), 'cpu' => $cpu]);
        }

        $rows = $this->ask($this->key(), 'server_accounts', ['server' => 'Orion', 'sort' => 'cpu'])['accounts'];

        $this->assertSame('busy', $rows[0]['user'], 'the account using the most first');
    }

    /**
     * ADV-858-5: staff notes on the server's records go out as typed; the other tools pass staff text through Scrub
     * (DomainTool's notes, suspend_reason here). A note with a token in it leaves whole.
     */
    public function test_adv_858_5_notes_are_scrubbed_as_other_staff_text(): void
    {
        $server = Server::query()->create(['name' => 'Orion', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $service = $this->service($server, 'shop.test');
        CpanelAccount::query()->create(['server_id' => $server->id, 'user' => 'shop', 'owner' => 'root', 'service_id' => $service->id, 'last_seen_at' => now(),
            'note' => 'api: Bearer abcdefghijklmnopqrstuvwxyz0123']);

        $data = $this->ask($this->key(), 'service_module', ['service' => (string) $service->id]);

        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz0123', (string) $data['cpanel_account']['note']);
    }

    /**
     * ADV-858-6: a service's mail domain is the mapped one only; the service page (MailcowDomain::forService, staff)
     * also shows its domain on its server while nobody mapped it. The MCP says «no mail domain» where the page shows one.
     */
    public function test_adv_858_6_the_mail_domain_as_the_service_page_finds_it(): void
    {
        $server = Server::query()->create(['name' => 'mx', 'extension' => 'Mailcow', 'type' => 'server', 'enabled' => true]);
        $service = $this->service($server, 'example.org');
        MailcowDomain::query()->create(['server_id' => $server->id, 'domain' => 'example.org', 'active' => true, 'last_seen_at' => now()]);
        $this->assertNotNull(MailcowDomain::forService($service, staff: true), 'the page shows it');

        $data = $this->ask($this->key(), 'service_module', ['service' => (string) $service->id]);

        $this->assertSame(['example.org'], array_column((array) $data['mailcow_domains'], 'domain'));
    }
}
