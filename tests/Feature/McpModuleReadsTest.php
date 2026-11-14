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
use App\Models\ServiceHostedDomain;
use App\Models\ServiceUsage;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the modules last read, through the MCP (owner 2026-11-14): one service's (`service_module`) and one server's
 * accounts (`server_accounts`), from the read-only syncs' tables; the server's records only to staff who may see the
 * servers, as in the admin.
 */
class McpModuleReadsTest extends TestCase
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

    /** @return array<string, mixed> */
    private function ask(string $key, string $tool, array $arguments = []): array
    {
        $response = $this->withHeaders(['Authorization' => "Bearer {$key}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]])->assertOk();
        $this->assertNotTrue($response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return (array) json_decode((string) $response->json('result.content.0.text'), true);
    }

    /** @return list<string> */
    private function tools(string $key): array
    {
        return collect($this->withHeaders(['Authorization' => "Bearer {$key}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []])->json('result.tools'))->pluck('name')->all();
    }

    private function hosting(Server $server, string $user): Service
    {
        $product = Product::factory()->create(['name' => 'Hosting', 'server_id' => $server->id]);

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'product_id' => $product->id, 'server_id' => $server->id,
            'status' => Service::STATUS_ACTIVE, 'domain' => "{$user}.test", 'username' => $user]);
    }

    public function test_each_tool_is_offered_only_with_its_permission(): void
    {
        $new = ['service_module', 'server_accounts'];

        $this->assertSame([], array_values(array_intersect($new, $this->tools($this->key($this->staff([McpAuth::PERMISSION]))))));
        $this->assertSame($new, array_values(array_intersect($new, $this->tools($this->key($this->staff(['*']))))));
        $this->assertSame(['service_module'], array_values(array_intersect($new, $this->tools($this->key($this->staff([McpAuth::PERMISSION, 'admin.services.viewAny']))))));
    }

    public function test_a_hosting_service_with_its_usage_domains_account_lve_and_resold_accounts(): void
    {
        $server = Server::query()->create(['name' => 'Orion', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $service = $this->hosting($server, 'shopgr');
        ServiceUsage::query()->create(['service_id' => $service->id, 'disk_used_mb' => 900, 'disk_limit_mb' => 1000, 'read_at' => now()->subHour()]);
        foreach ([['shop.gr', 'main'], ['blog-shop.gr', 'addon'], ['shop.eu', 'parked']] as [$domain, $type]) {
            ServiceHostedDomain::query()->create(['service_id' => $service->id, 'domain' => $domain, 'type' => $type, 'account' => 'shopgr', 'read_at' => now()->subHour()]);
        }
        $account = CpanelAccount::query()->create(['server_id' => $server->id, 'user' => 'shopgr', 'domain' => 'shop.gr', 'owner' => 'root', 'reseller' => true,
            'disk_used_mb' => 950, 'disk_limit_mb' => 1000, 'inodes_used' => 10, 'inodes_limit' => 100, 'mail_held' => true,
            'service_id' => $service->id, 'last_seen_at' => now(), 'lve_read_at' => now(), 'lve_memory_limit_mb' => 1024]);
        CpanelLveSample::query()->create(['cpanel_account_id' => $account->id, 'sampled_at' => now()->subHours(3), 'cpu' => 95, 'memory_mb' => 1000]);
        CpanelLveSample::query()->create(['cpanel_account_id' => $account->id, 'sampled_at' => now()->subMinutes(30), 'cpu' => 20, 'memory_mb' => 300]);
        CpanelAccount::query()->create(['server_id' => $server->id, 'user' => 'subone', 'domain' => 'sub.test', 'owner' => 'shopgr', 'last_seen_at' => now()]);
        CpanelAccount::query()->create(['server_id' => $server->id, 'user' => 'subold', 'owner' => 'shopgr', 'gone_at' => now()]);

        $data = $this->ask($this->key($this->staff(['*'])), 'service_module', ['service' => (string) $service->id]);

        $this->assertSame('CPanel', $data['server']['module']);
        $this->assertSame(900, $data['usage']['disk_used_mb']);
        $this->assertSame([['shop.gr', 'main'], ['blog-shop.gr', 'addon'], ['shop.eu', 'parked']], array_map(fn ($d) => [$d['domain'], $d['type']], $data['hosted_domains']['domains']));
        $cpanel = $data['cpanel_account'];
        $this->assertSame(['disk'], $cpanel['near_limits']);
        $this->assertTrue($cpanel['mail_held']);
        $this->assertSame(['peak' => 95, 'now' => 20, 'limit' => 100], $cpanel['lve']['peak_24h_now_limit']['cpu']);
        $this->assertSame(['peak' => 1000, 'now' => 300, 'limit' => 1024], $cpanel['lve']['peak_24h_now_limit']['memory']);
        $this->assertContains('memory', $cpanel['lve']['near']);
        $this->assertSame(['subone'], array_column($cpanel['resold_accounts'], 'user'), 'the gone one left out');

        $narrow = $this->ask($this->key($this->staff([McpAuth::PERMISSION, 'admin.services.viewAny'])), 'service_module', ['service' => (string) $service->id]);
        $this->assertSame(900, $narrow['usage']['disk_used_mb']);
        $this->assertNull($narrow['cpanel_account'], 'the server\'s records need View Servers');
        $this->assertStringStartsWith('hidden', $narrow['server_records']);
    }

    public function test_a_vps_and_a_mail_domain(): void
    {
        $proxmox = Server::query()->create(['name' => 'vega', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        $vps = $this->hosting($proxmox, 'vps1');
        ProxmoxGuest::query()->create(['server_id' => $proxmox->id, 'vmid' => 101, 'node' => 'vega', 'name' => 'vps1', 'status' => 'stopped', 'cpus' => 2,
            'max_memory' => 2147483648, 'service_id' => $vps->id, 'last_seen_at' => now(), 'ips' => ['203.0.113.9']]);
        $mail = Server::query()->create(['name' => 'mx', 'extension' => 'Mailcow', 'type' => 'server', 'enabled' => true]);
        $box = $this->hosting($mail, 'mailone');
        $domain = MailcowDomain::query()->create(['server_id' => $mail->id, 'domain' => 'example.org', 'mailboxes' => 1, 'mailbox_limit' => 10,
            'quota_bytes' => 1048576000, 'used_bytes' => 1048576 * 990, 'service_id' => $box->id, 'last_seen_at' => now(), 'active' => true]);
        $domain->mailboxList()->create(['address' => 'info@example.org', 'active' => true, 'quota_bytes' => 1048576 * 500, 'used_bytes' => 1048576 * 10]);
        $all = $this->key($this->staff(['*']));

        $guest = $this->ask($all, 'service_module', ['service' => (string) $vps->id])['proxmox_guest'];
        $this->assertSame(['stopped', 2048, ['203.0.113.9']], [$guest['status'], $guest['memory_mb'], $guest['ips']]);
        $mailcow = $this->ask($all, 'service_module', ['service' => (string) $box->id])['mailcow_domains'];
        $this->assertSame(['example.org', 'info@example.org', 500], [$mailcow[0]['domain'], $mailcow[0]['mailbox_list'][0]['address'], $mailcow[0]['mailbox_list'][0]['quota_mb']]);

        $this->assertSame(['vps1'], array_column($this->ask($all, 'server_accounts', ['server' => 'vega', 'show' => 'suspended'])['guests'], 'name'), 'not running');
        $this->assertSame(['example.org'], array_column($this->ask($all, 'server_accounts', ['server' => (string) $mail->id, 'show' => 'near_limits'])['domains'], 'domain'));
    }

    public function test_server_accounts_by_lve_peak_and_what_needs_a_look(): void
    {
        $server = Server::query()->create(['name' => 'Orion', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $service = $this->hosting($server, 'mapped');
        foreach (['quiet' => 10, 'busy' => 99, 'mapped' => 50] as $user => $cpu) {
            $account = CpanelAccount::query()->create(['server_id' => $server->id, 'user' => $user, 'owner' => 'root', 'last_seen_at' => now(), 'lve_read_at' => now(),
                'service_id' => $user === 'mapped' ? $service->id : null, 'internal' => $user === 'quiet', 'mail_held' => $user === 'busy']);
            CpanelLveSample::query()->create(['cpanel_account_id' => $account->id, 'sampled_at' => now()->subHour(), 'cpu' => $cpu]);
        }
        CpanelAccount::query()->create(['server_id' => $server->id, 'user' => 'never', 'owner' => 'root', 'last_seen_at' => now()]); // no LVE read
        CpanelAccount::query()->create(['server_id' => $server->id, 'user' => 'left', 'gone_at' => now()]);
        $key = $this->key($this->staff([McpAuth::PERMISSION, 'admin.servers.viewAny']));

        $this->assertSame(['Orion'], array_column($this->ask($key, 'server_accounts')['servers'], 'name'));
        $byCpu = $this->ask($key, 'server_accounts', ['server' => 'Orion', 'sort' => 'cpu']);
        $this->assertSame(['busy', 'mapped', 'quiet', 'never'], array_column($byCpu['accounts'], 'user'), 'highest first, unknown last, the gone one left out');
        $this->assertSame(99, $byCpu['accounts'][0]['lve_peak_24h']['cpu']);
        $this->assertSame(['busy', 'never'], array_column($this->ask($key, 'server_accounts', ['server' => 'Orion', 'show' => 'not_mapped'])['accounts'], 'user'));
        $this->assertSame(['busy'], array_column($this->ask($key, 'server_accounts', ['server' => 'Orion', 'show' => 'mail_held'])['accounts'], 'user'));
        $this->assertSame(['left'], array_column($this->ask($key, 'server_accounts', ['server' => 'Orion', 'show' => 'gone'])['accounts'], 'user'));
        $this->assertSame(['busy'], array_column($this->ask($key, 'server_accounts', ['server' => 'Orion', 'query' => 'bus'])['accounts'], 'user'));
    }
}
