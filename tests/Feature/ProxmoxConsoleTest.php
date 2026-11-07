<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Http\Controllers\ServiceConsoleController;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Support\Actor;
use App\Support\CurrentClient;
use App\Support\StaffSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use MyCS\Proxmox\Console\ConsoleTicket;
use Tests\TestCase;

/**
 * A VPS's console in MyCS (docs/proxmox.md, B6 and «Σχέδιο: το relay στο cluster»): the ticket the customer's page
 * asks, signed with the cluster's console key for the relay next to the cluster; the switch that turns it off; and the
 * relay's 30 s check. The relay itself is the package's (packages/proxmox/tests/Console).
 */
class ProxmoxConsoleTest extends TestCase
{
    use RefreshDatabase;

    private const VEGA = 'https://vega.example.test:8006';

    private const KEY = 'the-cluster-console-key-of-32-chars-or-more';

    private const RELAY = 'wss://console.example.test/ws';

    private string $status = 'running';

    private bool $clusterDown = false;

    /** @var list<string> */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function (Request $request) {
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api2/json/'));
            if ($request->method() !== 'GET') {
                $this->writes[] = $request->method() . ' ' . $path;

                return Http::response(['data' => null], 500);
            }
            if ($this->clusterDown) {
                return Http::response(['data' => null], 595);
            }

            return match ($path) {
                'cluster/resources' => Http::response(['data' => [['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps1', 'status' => $this->status, 'pool' => 'mycs']]]),
                'nodes/vega/qemu/101/config' => Http::response(['data' => ['net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0']]),
                'nodes/vega/qemu/101/status/current' => Http::response(['data' => ['status' => $this->status]]),
                default => Http::response(['data' => null], 500),
            };
        });
    }

    /** @param  array<string, mixed>  $console  the server's console settings */
    private function vps(string $status = 'active', array $console = ['console' => true, 'console_url' => self::RELAY, 'console_key' => self::KEY]): Service
    {
        $server = Server::query()->create(['name' => 'PVE', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, ['host' => self::VEGA, 'ip' => '192.0.2.201', 'self_signed' => true, 'token_id' => 'mycs@pve!api',
            'token_secret' => 's3cret', 'pool' => 'mycs', 'terminate' => 'stop'] + $console);
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

    private function origin(): string
    {
        $parts = parse_url((string) config('app.url'));

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    public function test_the_owner_gets_a_ticket_signed_for_the_relay_audited_without_asking_the_cluster_a_proxy(): void
    {
        $service = $this->vps();
        $this->as($this->owner($service));

        $this->get(route('services.console', $service))->assertOk()->assertSee(route('services.console.ticket', $service), false);
        $answer = $this->postJson(route('services.console.ticket', $service))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();

        $this->assertSame([], $this->writes, 'MyCS asks no VNC proxy: the relay does, with its own token');
        $this->assertSame(['url'], array_keys($answer), 'no password from MyCS');
        $this->assertStringStartsWith(self::RELAY . '?t=v1.', $answer['url']);
        $ticket = ConsoleTicket::verify(substr($answer['url'], strlen(self::RELAY . '?t=')), [self::KEY]);
        $this->assertSame(['origin' => $this->origin(), 'server_id' => $service->provisioningServer()->id, 'service_id' => $service->id,
            'user_id' => $this->owner($service)->id, 'node' => 'vega', 'type' => 'qemu', 'vmid' => 101],
            array_intersect_key($ticket, array_flip(['origin', 'server_id', 'service_id', 'user_id', 'node', 'type', 'vmid'])));
        $this->assertDatabaseHas('audits', ['auditable_id' => $service->id, 'event' => 'vm_console']);
    }

    /** ADV-A6-1, review 6: a ticket staff open in «Login as client» names them, for the relay's check; the owner's never. */
    public function test_a_ticket_staff_open_in_login_as_client_names_them(): void
    {
        $service = $this->vps();
        $owner = $this->owner($service);
        $this->as($owner);
        $mine = ConsoleTicket::verify(substr($this->postJson(route('services.console.ticket', $service))->json('url'), strlen(self::RELAY . '?t=')), [self::KEY]);
        $this->assertArrayNotHasKey('staff_id', $mine);

        $staff = Staff::factory()->withPermissions(['*'])->create();
        $ulid = strtolower((string) Str::ulid());
        $nonce = Str::random(32);
        $this->actingAs($staff, 'staff')->actingAs($owner)->withSession($this->loginUser($owner, ['impersonating' => $owner->id,
            CurrentClient::SESSION_KEY => $service->client_id, CurrentClient::IMPERSONATING_KEY => $service->client_id,
            StaffSessions::SESSION_KEY => $ulid, Actor::IMPERSONATION_NONCE => $nonce]));
        $theirs = ConsoleTicket::verify(substr($this->postJson(route('services.console.ticket', $service))->json('url'), strlen(self::RELAY . '?t=')), [self::KEY]);
        $this->assertSame([$owner->id, $staff->id], [$theirs['user_id'], $theirs['staff_id'] ?? null]);
        // Review 2, A1: bound to that «Login as client» (its staff session row and nonce); the audit names the staff member.
        $this->assertSame([$ulid, $nonce], [$theirs['staff_session'] ?? null, $theirs['impersonation'] ?? null]);
        $this->assertArrayNotHasKey('staff_session', $mine);
        $audit = DB::table('audits')->where('auditable_id', $service->id)->where('event', 'vm_console')->latest('id')->first();
        $this->assertSame($staff->id, json_decode((string) $audit->new_values, true)['staff_id'] ?? null);
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

        $service->forceFill(['status' => Service::STATUS_ACTIVE])->save();
        for ($i = 0; $i < 9; $i++) { // the stopped VM's try counted too
            $this->postJson(route('services.console.ticket', $service))->assertOk();
        }
        $this->postJson(route('services.console.ticket', $service))->assertStatus(429);
    }

    /** The switch (ROADMAP: before production): off, or not set up, there is no page, no ticket and no button. */
    public function test_with_the_console_off_or_not_set_up_there_is_no_page_ticket_or_button(): void
    {
        foreach ([['console' => false, 'console_url' => self::RELAY, 'console_key' => self::KEY], ['console' => true, 'console_key' => self::KEY],
            ['console' => true, 'console_url' => self::RELAY], ['console' => true, 'console_url' => self::RELAY, 'console_key' => 'too-short'],
            ['console' => true, 'console_url' => 'https://console.example.test/ws', 'console_key' => self::KEY]] as $n => $settings) {
            $service = $this->vps('active', $settings);
            $this->as($this->owner($service));

            $this->assertNull(ServiceConsoleController::relay($service), "settings {$n}: no relay, so no button");
            $this->get(route('services.console', $service))->assertNotFound();
            $this->postJson(route('services.console.ticket', $service))->assertNotFound();
        }
        $this->assertSame([], $this->writes);
    }

    /** Review of #506: the cluster that does not answer is the cluster's, never told as «the VM must be running». */
    public function test_a_cluster_that_does_not_answer_is_a_503_not_a_stopped_vm(): void
    {
        $service = $this->vps();
        $this->clusterDown = true;

        $this->as($this->owner($service))->postJson(route('services.console.ticket', $service))
            ->assertStatus(503)->assertJson(['message' => __('services.proxmox.console.unavailable')]);
    }

    // --- the relay's check ----------------------------------------------------------------------------------------------

    /** @param  list<array<string, mixed>>  $consoles */
    private function check(array $consoles, string $key = self::KEY, ?int $ts = null): TestResponse
    {
        $body = (string) json_encode(['ts' => $ts ?? time(), 'consoles' => $consoles]);

        return $this->call('POST', route('api.proxmox.console.check'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CONSOLE_SIGNATURE' => ConsoleTicket::checkSignature($body, $key)], $body);
    }

    /** @return array<string, mixed> */
    private function console(Service $service, int $id = 1, ?int $user = null): array
    {
        return ['id' => $id, 'jti' => 'j', 'server_id' => $service->provisioningServer()->id, 'service_id' => $service->id,
            'user_id' => $user ?? $this->owner($service)->id, 'vmid' => 101];
    }

    public function test_the_check_answers_each_console_of_its_cluster(): void
    {
        $service = $this->vps();
        $suspended = $this->vps(Service::STATUS_SUSPENDED);
        // Another cluster with another key: its consoles are not this body's to ask about.
        $other = $this->vps('active', ['console' => true, 'console_url' => self::RELAY, 'console_key' => 'another-cluster-key-of-32-chars-or-more']);
        $stranger = User::factory()->create();
        $stranger->client(); // a login of another account

        $answer = $this->check([$this->console($service, 1), $this->console($suspended, 2), $this->console($other, 3),
            $this->console($service, 4, $stranger->id), ['vmid' => 999, 'id' => 5] + $this->console($service), ['id' => 'x']])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('allowed');

        $this->assertSame(['1' => true, '2' => false, '3' => false, '4' => false, '5' => false], $answer);
    }

    /** ADV-506-5 in MyCS's half: a login whose grant on the account is revoked is told «no». */
    public function test_a_login_removed_from_the_account_is_told_no(): void
    {
        $service = $this->vps();
        $employee = User::factory()->create();
        $grant = $service->client->grantTo($employee, null);
        $this->assertSame(['1' => true], $this->check([$this->console($service, 1, $employee->id)])->json('allowed'));

        $service->client->revoke($grant);

        $this->assertSame(['1' => false], $this->check([$this->console($service, 1, $employee->id)])->json('allowed'));
    }

    /** Off, its key kept: every console of the cluster «no» at once (closed within 30 s, not after three failed checks). */
    public function test_with_the_console_turned_off_every_answer_is_no(): void
    {
        $service = $this->vps('active', ['console' => false, 'console_url' => self::RELAY, 'console_key' => self::KEY]);

        $this->assertSame(['1' => false], $this->check([$this->console($service)])->json('allowed'));
    }

    public function test_the_check_is_refused_unsigned_with_another_key_or_old(): void
    {
        $service = $this->vps();
        $this->check([$this->console($service)], 'a-key-no-cluster-has-of-32-chars-or-more')->assertForbidden();
        $this->postJson(route('api.proxmox.console.check'), ['ts' => time(), 'consoles' => [$this->console($service)]])->assertForbidden();
        $this->check([$this->console($service)], self::KEY, time() - 120)->assertStatus(422);
        $this->check(array_fill(0, 300, $this->console($service)))->assertStatus(422);
    }

    /** The relay restarts on a deploy by its own file (its config's `restart_file`), touched at the deploy's end. */
    public function test_a_deploy_touches_the_relays_restart_file(): void
    {
        $this->assertStringContainsString('touch storage/framework/console-relay.restart', (string) file_get_contents(base_path('deploy/update.sh')));
        $this->assertStringContainsString('restart_file', (string) file_get_contents(base_path('packages/proxmox/bin/console-relay')));
    }
}
