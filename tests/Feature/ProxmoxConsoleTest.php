<?php

namespace Tests\Feature;

use App\Console\Commands\ProxmoxConsoleRelay;
use App\Helpers\ExtensionHelper;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Proxmox\ConsoleRelay;
use App\Services\Proxmox\ConsoleTickets;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MyCS\Extensions\Servers\Proxmox\Proxmox;
use MyCS\Proxmox\WebSocket;
use Tests\TestCase;

/**
 * A VPS's console (docs/proxmox.md, B6): the ticket the customer's page asks, and the relay that opens the node's
 * websocket with it once, from this site only, and passes the frames both ways.
 */
class ProxmoxConsoleTest extends TestCase
{
    use RefreshDatabase;

    private const VEGA = 'https://vega.example.test:8006';

    private const ORIGIN = 'https://mycs.example.test';

    private string $status = 'running';

    private bool $vncDenied = false;

    private float $now = 1000.0;

    /** @var list<string> */
    private array $writes = [];

    private ?ConsoleRelay $relay = null;

    /** @var resource|null the fake node's listening socket */
    private $node = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function (Request $request) {
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api2/json/'));
            if ($request->method() !== 'GET') {
                $this->writes[] = $request->method() . ' ' . $path;

                if ($this->vncDenied) {
                    return Http::response(['data' => null], 403);
                }

                return $path === 'nodes/vega/qemu/101/vncproxy'
                    ? Http::response(['data' => ['port' => '5901', 'ticket' => 'PVEVNC:ABC+/=', 'user' => 'mycs@pve!api']])
                    : Http::response(['data' => null], 500);
            }

            return match ($path) {
                'cluster/resources' => Http::response(['data' => [['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps1', 'status' => $this->status, 'pool' => 'mycs']]]),
                'nodes/vega/qemu/101/config' => Http::response(['data' => ['net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0']]),
                'nodes/vega/qemu/101/status/current' => Http::response(['data' => ['status' => $this->status]]),
                default => Http::response(['data' => null], 500),
            };
        });
    }

    protected function tearDown(): void
    {
        $this->relay = null;
        if ($this->node) {
            fclose($this->node);
        }
        parent::tearDown();
    }

    private function vps(string $status = 'active'): Service
    {
        $server = Server::query()->create(['name' => 'PVE', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, ['host' => self::VEGA, 'ip' => '192.0.2.201', 'self_signed' => true, 'token_id' => 'mycs@pve!api',
            'token_secret' => 's3cret', 'pool' => 'mycs', 'terminate' => 'stop']);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => $status, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        ProxmoxGuest::query()->create(['server_id' => $server->id, 'vmid' => 101, 'node' => 'vega', 'name' => 'vps1', 'status' => 'running',
            'pool' => 'mycs', 'macs' => ['BC:24:11:00:00:01'], 'service_id' => $service->id]);

        return $service;
    }

    private function owner(Service $service): User
    {
        return $service->client->users()->first();
    }

    /** Signed in as the site does it: its login, on its account. */
    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $user->client()->id]));
    }

    public function test_the_owner_opens_its_console_and_gets_a_ticket_once_audited(): void
    {
        $service = $this->vps();
        $this->as($this->owner($service));

        $this->get(route('services.console', $service))->assertOk()->assertSee(route('services.console.ticket', $service), false);
        $answer = $this->postJson(route('services.console.ticket', $service))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();

        $this->assertSame(['POST nodes/vega/qemu/101/vncproxy'], $this->writes);
        $this->assertSame('PVEVNC:ABC+/=', $answer['password']);
        $this->assertMatchesRegularExpression('#^wss?://[^/]+/console/ws\?token=[A-Za-z0-9]{48}$#', $answer['url']);
        $token = substr($answer['url'], -48);
        $ticket = ConsoleTickets::take($token);
        $this->assertSame([self::VEGA, '/api2/json/nodes/vega/qemu/101/vncwebsocket', 5901, $service->id], [$ticket['base'], $ticket['path'], $ticket['port'], $ticket['service_id']]);
        $this->assertNull(ConsoleTickets::take($token), 'once');
        $this->assertDatabaseHas('audits', ['auditable_id' => $service->id, 'event' => 'vm_console']);
    }

    public function test_never_another_customers_never_while_suspended_never_a_stopped_vm_and_not_in_a_loop(): void
    {
        $service = $this->vps();
        $this->as(User::factory()->create());
        $this->get(route('services.console', $service))->assertNotFound(); // as every page of another account's
        $this->postJson(route('services.console.ticket', $service))->assertNotFound();

        $this->as($this->owner($service));
        $this->status = 'stopped';
        $this->postJson(route('services.console.ticket', $service))->assertStatus(409);
        $this->status = 'running';
        $service->forceFill(['status' => Service::STATUS_SUSPENDED])->save();
        $this->postJson(route('services.console.ticket', $service))->assertStatus(409)->assertJson(['message' => __('services.proxmox.console.not_active')]);
        $this->assertSame([], $this->writes);

        $service->forceFill(['status' => Service::STATUS_ACTIVE])->save();
        for ($i = 0; $i < 9; $i++) { // the stopped VM's try counted too
            $this->postJson(route('services.console.ticket', $service))->assertOk();
        }
        $this->postJson(route('services.console.ticket', $service))->assertStatus(429);
    }

    public function test_the_relay_reaches_the_node_as_the_settings_say(): void
    {
        $service = $this->vps();
        $extension = ExtensionHelper::getExtension('server', 'Proxmox', $service->provisioningServer()->settings);
        $this->assertInstanceOf(Proxmox::class, $extension);

        $this->assertSame(['host' => 'vega.example.test', 'port' => 8006, 'ip' => '192.0.2.201', 'self_signed' => true,
            'authorization' => 'PVEAPIToken=mycs@pve!api=s3cret'], $extension->websocketTarget(self::VEGA));
        $this->expectException(\RuntimeException::class);
        $extension->websocketTarget('https://elsewhere.example.test:8006');
    }

    // --- the relay, on real sockets: a browser and a node of our own ---------------------------------------------------

    private function relay(): ConsoleRelay
    {
        $this->node = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($this->node, false);

        return $this->relay = new ConsoleRelay('127.0.0.1:0', self::ORIGIN, '/console/ws', fn (array $ticket) => [
            'stream' => stream_socket_client('tcp://' . $address), 'host' => 'vega.example.test', 'port' => 8006, 'authorization' => 'PVEAPIToken=mycs@pve!api=s3cret',
        ], null, fn () => $this->now, fn () => true);
    }

    /** @return resource the node's side, once the relay connected it */
    private function nodeSide()
    {
        for ($i = 0; $i < 100; $i++) {
            $this->relay->tick(0.02);
            if (($node = @stream_socket_accept($this->node, 0)) !== false) {
                return $node;
            }
        }
        $this->fail('the relay never connected the node');
    }

    /** @return resource */
    private function browser(string $token, string $origin = self::ORIGIN, string $key = 'dGhlIHNhbXBsZSBub25jZQ==')
    {
        $client = stream_socket_client('tcp://' . $this->relay->address());
        fwrite($client, "GET /console/ws?token={$token} HTTP/1.1\r\nHost: mycs.example.test\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Protocol: binary\r\nOrigin: {$origin}\r\n\r\n");

        return $client;
    }

    /** The relay turned until $stream has something (or a while passed); what it had. */
    private function read($stream, float $for = 2.0): string
    {
        stream_set_blocking($stream, false);
        $got = '';
        $until = microtime(true) + $for;
        while (microtime(true) < $until) {
            $this->relay->tick(0.02);
            $chunk = (string) fread($stream, 65536);
            $got .= $chunk;
            if ($got !== '' && $chunk === '') {
                break;
            }
        }

        return $got;
    }

    private function ticket(Service $service): string
    {
        return ConsoleTickets::issue($service->provisioningServer()->id, $service->id, 1,
            ['vmid' => 101, 'base' => self::VEGA, 'path' => '/api2/json/nodes/vega/qemu/101/vncwebsocket', 'port' => 5901, 'ticket' => 'PVEVNC:ABC+/=']);
    }

    public function test_the_relay_opens_the_nodes_websocket_with_the_token_and_passes_frames_both_ways(): void
    {
        $this->relay();
        $browser = $this->browser($this->ticket($this->vps()));
        $node = $this->nodeSide();

        $request = $this->read($node);
        $this->assertStringStartsWith("GET /api2/json/nodes/vega/qemu/101/vncwebsocket?port=5901&vncticket=PVEVNC%3AABC%2B%2F%3D HTTP/1.1\r\n", $request);
        $this->assertStringContainsString("Authorization: PVEAPIToken=mycs@pve!api=s3cret\r\n", $request);
        preg_match('/Sec-WebSocket-Key: (\S+)/', $request, $m);
        fwrite($node, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . WebSocket::accept($m[1])
            . "\r\nSec-WebSocket-Protocol: binary\r\n\r\n");

        $answer = $this->read($browser);
        $this->assertStringStartsWith("HTTP/1.1 101 Switching Protocols\r\n", $answer);
        $this->assertStringContainsString("Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=\r\n", $answer);
        $this->assertStringContainsString("Sec-WebSocket-Protocol: binary\r\n", $answer);
        $this->assertStringNotContainsString('s3cret', $answer, 'the token never reaches the browser');

        fwrite($browser, WebSocket::encode(WebSocket::BINARY, 'RFB 003.008', true));
        $frames = $this->read($node);
        $this->assertSame('RFB 003.008', WebSocket::decode($frames, true, 1024)['payload'], 'masked again towards the node');

        $screen = random_bytes(70000);
        fwrite($node, WebSocket::encode(WebSocket::BINARY, $screen, false));
        $frames = $this->read($browser);
        while (strlen($frames) < 70010) {
            $frames .= $this->read($browser, 0.5);
        }
        $this->assertSame($screen, WebSocket::decode($frames, false, 1 << 20)['payload']);

        fwrite($browser, WebSocket::encode(WebSocket::CLOSE, '', true));
        $this->read($node);
        $this->read($browser, 0.3);
        $this->assertSame(0, $this->relay->count(), 'a close ends the console');
    }

    public function test_the_relay_refuses_another_site_a_ticket_used_or_none(): void
    {
        $this->relay();
        $service = $this->vps();
        $token = $this->ticket($service);

        $this->assertStringStartsWith('HTTP/1.1 403', $this->read($this->browser($token, 'https://evil.example.test')));
        $this->assertStringStartsWith('HTTP/1.1 403', $this->read($this->browser(str_repeat('a', 48))));
        $this->assertNull(@stream_socket_accept($this->node, 0) ?: null, 'the node was never asked');

        $this->browser($token); // this site's: it is taken, and the node asked
        $this->nodeSide();
        $this->assertStringStartsWith('HTTP/1.1 403', $this->read($this->browser($token)), 'once');

        $client = stream_socket_client('tcp://' . $this->relay->address());
        fwrite($client, "GET /console/ws HTTP/1.1\r\nHost: x\r\n\r\n");
        $this->assertStringStartsWith('HTTP/1.1 400', $this->read($client));
    }

    public function test_a_node_that_refuses_is_a_502_for_the_browser(): void
    {
        $this->relay();
        $browser = $this->browser($this->ticket($this->vps()));
        $node = $this->nodeSide();
        $this->read($node);
        fwrite($node, "HTTP/1.1 401 No ticket\r\nContent-Length: 0\r\n\r\n");

        $this->assertStringStartsWith('HTTP/1.1 502', $this->read($browser));
    }

    public function test_the_relay_begins_the_nodes_connection_without_waiting_for_it(): void
    {
        $service = $this->vps();
        $started = microtime(true);
        // 192.0.2.201 is a documentation address: nothing answers. The connection is begun, its TCP and TLS finished
        // from the relay's loop (review of #506: a node that stalls never holds every console).
        $node = ProxmoxConsoleRelay::connect(['server_id' => $service->provisioningServer()->id, 'base' => self::VEGA]);

        $this->assertLessThan(1.0, microtime(true) - $started);
        $this->assertSame(['vega.example.test', 8006, true], [$node['host'], $node['port'], $node['tls']]);
        $this->assertIsResource($node['stream']);
        fclose($node['stream']);
    }

    /** Review of #506: a role without VM.Console is the cluster's, never told as «the VM must be running». */
    public function test_a_token_without_the_console_privilege_is_a_503_not_a_stopped_vm(): void
    {
        $service = $this->vps();
        $this->vncDenied = true;

        $this->as($this->owner($service))->postJson(route('services.console.ticket', $service))
            ->assertStatus(503)->assertJson(['message' => __('services.proxmox.console.unavailable')]);
    }

    /** Review of #506: a deploy tells the relay at its end, with the new release in place. */
    public function test_a_deploy_restarts_the_relay_by_its_own_key(): void
    {
        $this->assertNull(Cache::get(ProxmoxConsoleRelay::RESTART_KEY));
        $this->artisan('proxmox:console-relay', ['--restart' => true])->assertSuccessful();
        $this->assertNotNull(Cache::get(ProxmoxConsoleRelay::RESTART_KEY));
        $this->assertStringContainsString('proxmox:console-relay --restart', (string) file_get_contents(base_path('deploy/update.sh')));
    }

    /** Review of #506: a browser's frame is a key or a mouse move; a large one ends its console, not the relay. */
    public function test_a_browsers_large_frame_ends_its_console(): void
    {
        $this->relay();
        $browser = $this->browser($this->ticket($this->vps()));
        $node = $this->nodeSide();
        $request = $this->read($node);
        preg_match('/Sec-WebSocket-Key: (\S+)/', $request, $m);
        fwrite($node, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . WebSocket::accept($m[1]) . "\r\n\r\n");
        $this->read($browser);

        fwrite($browser, WebSocket::encode(WebSocket::BINARY, str_repeat('k', ConsoleRelay::MAX_CLIENT_FRAME + 1), true));
        $this->read($browser, 0.5);
        $this->assertSame(0, $this->relay->count());
    }

    /** Round 2 of #506: a console whose browser reads nothing gives its slot back after BACKED seconds. */
    public function test_a_browser_that_reads_nothing_gives_its_slot_back(): void
    {
        $this->relay();
        $browser = $this->browser($this->ticket($this->vps()));
        $node = $this->nodeSide();
        $request = $this->read($node);
        preg_match('/Sec-WebSocket-Key: (\S+)/', $request, $m);
        fwrite($node, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . WebSocket::accept($m[1]) . "\r\n\r\n");
        $this->read($browser);
        stream_set_blocking($node, false);
        $screen = str_repeat(WebSocket::encode(WebSocket::BINARY, str_repeat('s', 65000), false), 16);
        for ($i = 0; $i < 300 && $this->relay->count() === 1; $i++) {
            @fwrite($node, $screen); // and the browser never reads
            $this->relay->tick(0.002);
            if ($i === 150) {
                $this->now += ConsoleRelay::BACKED + 1;
            }
        }

        $this->assertSame(0, $this->relay->count());
    }
}
