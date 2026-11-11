<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Livewire\Auth\Password\Reset;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use MyCS\Proxmox\Console\ConsoleTicket;
use Tests\TestCase;

/**
 * Sweep 10, «access» (adversarial verifier). S10-ACCESS-1: the customer's own VPS console is bound to nothing of the
 * login's session, so ending the login's sessions (password reset, new password by staff, «log out other sessions»)
 * does not close a console already open: the relay's 30 s check keeps answering «yes» (up to the relay's 4 hours).
 * Staff's consoles are bound to their session row and close within 30 s (docs/proxmox.md).
 */
class Sweep10AccessTest extends TestCase
{
    use RefreshDatabase;

    private const VEGA = 'https://vega.example.test:8006';

    private const KEY = 'the-cluster-console-key-of-32-chars-or-more';

    private const RELAY = 'wss://console.example.test/ws';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api2/json/'));
            if ($request->method() !== 'GET') {
                return Http::response(['data' => null], 500);
            }

            return match ($path) {
                'cluster/resources' => Http::response(['data' => [['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps1', 'status' => 'running', 'pool' => 'mycs']]]),
                'nodes/vega/qemu/101/config' => Http::response(['data' => ['net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0']]),
                'nodes/vega/qemu/101/status/current' => Http::response(['data' => ['status' => 'running']]),
                default => Http::response(['data' => null], 500),
            };
        });
    }

    private function vps(): Service
    {
        $server = Server::query()->create(['name' => 'PVE', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, ['host' => self::VEGA, 'ip' => '192.0.2.201', 'self_signed' => true, 'token_id' => 'mycs@pve!api',
            'token_secret' => 's3cret', 'pool' => 'mycs', 'terminate' => 'stop', 'console' => true, 'console_url' => self::RELAY, 'console_key' => self::KEY]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        ProxmoxGuest::query()->create(['server_id' => $server->id, 'vmid' => 101, 'node' => 'vega', 'name' => 'vps1', 'status' => 'running',
            'pool' => 'mycs', 'macs' => ['BC:24:11:00:00:01'], 'service_id' => $service->id]);

        return $service;
    }

    /** @param  list<array<string, mixed>>  $consoles */
    private function check(array $consoles): TestResponse
    {
        $body = (string) json_encode(['ts' => time(), 'consoles' => $consoles]);

        return $this->call('POST', route('api.proxmox.console.check'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CONSOLE_SIGNATURE' => ConsoleTicket::checkSignature($body, self::KEY)], $body);
    }

    /**
     * S10-ACCESS-1: someone on the customer's (stolen) session opens the console; the customer resets the password,
     * which ends every session of the login. The relay asks again with exactly what the ticket gave it: the console
     * must be told «no» (as staff's is once their session row ends), and is told «yes».
     */
    public function test_a_customers_open_console_closes_once_a_password_reset_ended_the_logins_sessions(): void
    {
        $service = $this->vps();
        $owner = $service->client->users()->first();
        $this->actingAs($owner)->withSession($this->loginUser($owner, [CurrentClient::SESSION_KEY => $owner->client()->id]));

        // The console as the site opens it: the relay sends back, every 30 s, what the ticket carried.
        $url = $this->postJson(route('services.console.ticket', $service))->assertOk()->json('url');
        $ticket = ConsoleTicket::verify(substr($url, strlen(self::RELAY . '?t=')), [self::KEY]);
        $console = ['id' => 1] + array_diff_key($ticket, array_flip(['iat', 'exp', 'origin', 'node', 'type']));
        $this->assertSame(['1' => true], $this->check([$console])->json('allowed'), 'open while the session lives');
        $this->assertSame(1, $owner->sessions()->count());

        // The customer resets the password from the mailed link: every session of the login is ended.
        $token = Password::createToken($owner);
        Livewire::withQueryParams(['email' => $owner->email])->test(Reset::class, ['token' => $token])
            ->set('password', 'A-new-Passw0rd-2026!')->set('password_confirmation', 'A-new-Passw0rd-2026!')
            ->call('submit')->assertRedirect(route('login'));
        $this->assertSame(0, $owner->sessions()->count(), 'the reset ended the sessions');

        $this->assertSame(['1' => false], $this->check([$console])->json('allowed'),
            'the session that opened this console was ended by the password reset: the console must close at the next check');
    }
}
