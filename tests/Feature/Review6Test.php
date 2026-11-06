<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Http\Controllers\Api\ProxmoxConsoleCheckController;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use MyCS\Proxmox\Console\ConsoleTicket;
use Tests\TestCase;

/**
 * Review of fix/adv-area-6 (uncommitted). Staff in «Login as client» on a closed account open a VPS console (the route's
 * `can:update` skips the closed check for them), but the relay's 30 s check has no session, so owns() applies the
 * closed filter to the customer's login recorded in the ticket: the staff console is cut within 30 s. Fake data only.
 */
class Review6Test extends TestCase
{
    use RefreshDatabase;

    public function test_staff_console_on_a_closed_account_is_opened_then_cut_by_the_relay_check(): void
    {
        Http::fake(fn () => Http::response(['data' => null], 500));
        $server = Server::query()->create(['name' => 'PVE', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, ['host' => 'https://vega.example.test:8006', 'ip' => '192.0.2.201', 'self_signed' => true,
            'token_id' => 'mycs@pve!api', 'token_secret' => 's3cret', 'pool' => 'mycs', 'terminate' => 'stop', 'console' => true,
            'console_url' => 'wss://console.example.test/ws', 'console_key' => 'the-cluster-console-key-of-32-chars-or-more']);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $user = User::factory()->create();
        $client = $user->client();
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        ProxmoxGuest::query()->create(['server_id' => $server->id, 'vmid' => 101, 'node' => 'vega', 'name' => 'vps1', 'status' => 'running',
            'pool' => 'mycs', 'macs' => ['BC:24:11:00:00:01'], 'service_id' => $service->id]);
        $console = ['id' => 1, 'server_id' => $server->id, 'service_id' => $service->id, 'user_id' => $user->id, 'vmid' => 101];
        $this->assertTrue(ProxmoxConsoleCheckController::allowed($console), 'baseline: open account');

        $client->forceFill(['closed_at' => now()])->save();

        // Staff in «Login as client» still open it (ClosedAccountAccessTest: the policy skips the closed check for them).
        // The relay's check (no session) for the same console: refused, so the staff console closes within 30 s.
        // The fix names the staff member in the ticket (ServiceConsoleController), which the relay echoes in its check.
        $staff = Staff::factory()->withPermissions(['admin.users.impersonate', 'admin.services.update'])->create();
        $body = (string) json_encode(['ts' => time(), 'consoles' => [$console + ['staff_id' => $staff->id]]]);
        $answer = $this->call('POST', route('api.proxmox.console.check'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CONSOLE_SIGNATURE' => ConsoleTicket::checkSignature($body, 'the-cluster-console-key-of-32-chars-or-more')], $body)->json('allowed');
        $this->assertSame(['1' => true], $answer, 'staff console on a closed account cut by the relay check');

        // The customer's own console on the now closed account still closes; so does staff's, once they may no longer.
        $this->assertFalse(ProxmoxConsoleCheckController::allowed($console), "the customer's console on a closed account");
        $staff->forceFill(['active' => false])->save();
        $this->assertFalse(ProxmoxConsoleCheckController::allowed($console + ['staff_id' => $staff->id]), 'a disabled staff member');
    }
}
