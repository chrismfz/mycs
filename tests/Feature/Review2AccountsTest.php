<?php

namespace Tests\Feature;

use App\Actions\Auth\Logout as LogoutAction;
use App\Admin\Resources\UserResource\Pages\EditUser;
use App\Helpers\ExtensionHelper;
use App\Http\Controllers\Api\ProxmoxConsoleCheckController;
use App\Http\Controllers\LeaveImpersonationController;
use App\Livewire\Auth\Logout;
use App\Livewire\Domains\Show as DomainShow;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Domain;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffSession;
use App\Models\User;
use App\Support\Actor;
use App\Support\CurrentClient;
use App\Support\ItemPaymentAccess;
use App\Support\StaffSessions;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use MyCS\Proxmox\Console\ConsoleTicket;
use Tests\TestCase;

/**
 * Review 2 of #698 (accounts): the VPS console staff open in «Login as client» bound to that live «Login as client»
 * (A1), one staff mapping for the route and the check (A2), one grant helper with the closed filter (A3, A6), and the
 * check's lookups once per body (A7). Fake data only.
 */
class Review2AccountsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'the-cluster-console-key-of-32-chars-or-more';

    private Service $service;

    private User $user;

    /** @var array<string, int> */
    private array $console;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(fn () => Http::response(['data' => null], 500));
        $server = Server::query()->create(['name' => 'PVE', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, ['host' => 'https://vega.example.test:8006', 'ip' => '192.0.2.201', 'self_signed' => true,
            'token_id' => 'mycs@pve!api', 'token_secret' => 's3cret', 'pool' => 'mycs', 'terminate' => 'stop', 'console' => true,
            'console_url' => 'wss://console.example.test/ws', 'console_key' => self::KEY]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $this->user = User::factory()->create();
        $this->service = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        ProxmoxGuest::query()->create(['server_id' => $server->id, 'vmid' => 101, 'node' => 'vega', 'name' => 'vps1', 'status' => 'running',
            'pool' => 'mycs', 'macs' => ['BC:24:11:00:00:01'], 'service_id' => $this->service->id]);
        $this->console = ['id' => 1, 'server_id' => $server->id, 'service_id' => $this->service->id, 'user_id' => $this->user->id, 'vmid' => 101];
    }

    private function staff(array $permissions = ['admin.users.impersonate', 'admin.services.update']): Staff
    {
        return Staff::factory()->withPermissions($permissions)->create();
    }

    /**
     * Staff logged in, in «Login as client» of the user, as the admin starts it (EditUser::impersonate), with their
     * login's row; the session saved to the store as at the end of a request. The console's binding is returned.
     *
     * @return array{staff_id: int, staff_session: string, impersonation: string}
     */
    private function impersonate(Staff $staff): array
    {
        $this->actingAs($staff, 'staff');
        $store = app('session.store');
        $guard = Auth::guard('staff');
        $this->assertInstanceOf(SessionGuard::class, $guard);
        $row = StaffSession::query()->create(['staff_id' => $staff->id, 'session_id' => $store->getId(), 'created_at' => now(), 'last_seen_at' => now()]);
        $store->put([$guard->getName() => $staff->id, StaffSessions::SESSION_KEY => $row->ulid]);
        EditUser::impersonate($this->user, $this->user->client());
        $store->save();

        return ['staff_id' => $staff->id, 'staff_session' => $row->ulid, 'impersonation' => (string) $store->get(Actor::IMPERSONATION_NONCE)];
    }

    /** @param  array<string, mixed>  $binding */
    private function allowed(array $binding): bool
    {
        return ProxmoxConsoleCheckController::allowed($this->console + $binding);
    }

    /** @param  array<string, mixed>  $consoles */
    private function check(array $consoles): ?array
    {
        $body = (string) json_encode(['ts' => time(), 'consoles' => $consoles]);

        return $this->call('POST', route('api.proxmox.console.check'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CONSOLE_SIGNATURE' => ConsoleTicket::checkSignature($body, self::KEY)], $body)->json('allowed');
    }

    public function test_a1_a_staff_console_stays_open_while_that_login_as_client_does(): void
    {
        $binding = $this->impersonate($this->staff());

        $this->assertTrue($this->allowed($binding), 'still impersonating: open');
        $this->assertSame(['1' => true], $this->check([$this->console + $binding]));
        // The binding is required, every part of it (fail closed: a ticket or relay from before it).
        $this->assertFalse($this->allowed(['staff_id' => $binding['staff_id']]), 'staff_id alone');
        $this->assertFalse($this->allowed(['impersonation' => Str::random(32)] + $binding), 'another nonce');
        $this->assertFalse($this->allowed(['staff_session' => strtolower((string) Str::ulid())] + $binding), 'another session row');
        $this->assertFalse($this->allowed(['staff_id' => $this->staff()->id] + $binding), 'another staff member');
    }

    public function test_a1_leave_closes_it(): void
    {
        $binding = $this->impersonate($this->staff());
        $request = Request::create('/leave');
        $request->setLaravelSession(app('session.store'));
        app(LeaveImpersonationController::class)($request);
        app('session.store')->save();

        $this->assertFalse($this->allowed($binding));
    }

    public function test_a1_the_client_areas_log_out_closes_it(): void
    {
        $binding = $this->impersonate($this->staff());
        (new Logout)->logout(app(LogoutAction::class));
        app('session.store')->save();

        $this->assertFalse($this->allowed($binding));
    }

    public function test_a1_a_new_login_as_client_closes_the_old_ones_console(): void
    {
        $binding = $this->impersonate($this->staff());
        EditUser::impersonate($this->user, $this->user->client());
        app('session.store')->save();

        $this->assertFalse($this->allowed($binding), 'a new nonce');
    }

    public function test_a1_the_staff_logout_closes_it(): void
    {
        $binding = $this->impersonate($this->staff());
        app(LogoutAction::class)->execute();
        app('session.store')->save();

        $this->assertFalse($this->allowed($binding));
    }

    public function test_a1_a_session_ended_from_sessions_closes_it(): void
    {
        $staff = $this->staff();
        $binding = $this->impersonate($staff);
        $row = StaffSession::query()->where('ulid', $binding['staff_session'])->sole();
        // Ended by an admin: the row, and the session in the store.
        StaffSessions::end($staff, [$row], Staff::factory()->withPermissions(['*'])->create(), Request::create('/'));

        $this->assertFalse($this->allowed($binding), 'row ended');

        // The row alone ended (the store kept the session) or the session alone gone: closed either way.
        $other = $this->impersonate($staff);
        StaffSession::query()->where('ulid', $other['staff_session'])->update(['ended_at' => now()]);
        $this->assertFalse($this->allowed($other), 'row ended, session kept');
        $third = $this->impersonate($staff);
        app('session.store')->getHandler()->destroy(app('session.store')->getId());
        $this->assertFalse($this->allowed($third), 'session gone from the store');
    }

    /** Review 2 (security): a password reset closes the console; a console makes no request of its own. */
    public function test_a1_a_changed_staff_password_closes_it(): void
    {
        $staff = $this->staff();
        $binding = $this->impersonate($staff);
        $guard = Auth::guard('staff');
        $this->assertInstanceOf(SessionGuard::class, $guard);
        app('session.store')->put('password_hash_staff', $guard->hashPasswordForCookie($staff->getAuthPassword()));
        app('session.store')->save();
        $this->assertTrue($this->allowed($binding), 'the HMAC form, the password unchanged');
        app('session.store')->put('password_hash_staff', $staff->getAuthPassword());
        app('session.store')->save();
        $this->assertTrue($this->allowed($binding), "the raw form Filament's profile stores");

        $staff->forceFill(['password' => bcrypt('a-new-password-after-a-reset')])->saveQuietly();

        $this->assertFalse($this->allowed($binding), 'password changed');
    }

    public function test_a5_staff_console_negatives(): void
    {
        $binding = $this->impersonate($without = $this->staff(['admin.users.impersonate']));
        $this->assertFalse($this->allowed($binding), 'without admin.services.update');

        $binding = $this->impersonate($this->staff(['admin.services.update']));
        $this->assertFalse($this->allowed($binding), 'without admin.users.impersonate');

        $binding = $this->impersonate($this->staff());
        $this->assertTrue($this->allowed($binding));
        ClientUser::query()->where('user_id', $this->user->id)->update(['revoked_at' => now()]);
        $this->assertFalse($this->allowed($binding), "the customer's grant revoked");
        $this->assertNotNull($without);

        // staff_id as null or a string in the body: refused, never the customer's path.
        ClientUser::query()->where('user_id', $this->user->id)->update(['revoked_at' => null]);
        $this->assertSame(['1' => false, '2' => false, '3' => true], $this->check([
            ['staff_id' => null] + $binding + $this->console,
            ['id' => 2, 'staff_id' => (string) $binding['staff_id']] + $binding + $this->console,
            ['id' => 3] + $this->console, // the customer's own: unchanged
        ]));
    }

    public function test_a1_the_staff_console_on_a_closed_account_stays_open_for_staff_only(): void
    {
        $binding = $this->impersonate($this->staff());
        $this->user->client()->forceFill(['closed_at' => now()])->save();

        $this->assertTrue($this->allowed($binding));
        // The relay's check has no session: this test's own store must not make the customer's path staff's.
        app('session.store')->forget(['impersonating', Actor::IMPERSONATION_NONCE]);
        $this->assertFalse($this->allowed([]), "the customer's own");
    }

    public function test_a7_one_bodys_lookups_are_asked_once(): void
    {
        $binding = $this->impersonate($this->staff());
        $consoles = array_map(fn (int $id) => ['id' => $id] + $binding + $this->console, range(1, 20));
        $body = (string) json_encode(['ts' => time(), 'consoles' => $consoles]);
        DB::enableQueryLog();
        $answer = $this->call('POST', route('api.proxmox.console.check'), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_CONSOLE_SIGNATURE' => ConsoleTicket::checkSignature($body, self::KEY)], $body)->json('allowed');
        // One quoting for both drivers: SQLite's "col", MariaDB's `col`.
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $sql) => str_replace('`', '"', $sql));
        DB::disableQueryLog();

        $this->assertCount(20, array_filter($answer));
        $this->assertSame(1, $queries->filter(fn (string $sql) => str_contains($sql, 'from "staff"') && !str_contains($sql, 'staff_sessions'))->count(), 'staff once');
        $this->assertSame(1, $queries->filter(fn (string $sql) => str_contains($sql, 'from "client_user"'))->count(), 'grant once');
        $this->assertSame(1, $queries->filter(fn (string $sql) => str_contains($sql, 'from "staff_sessions"') && str_contains($sql, '"ended_at" is null'))->count(), 'session row once');
        $this->assertSame(1, $queries->filter(fn (string $sql) => str_contains($sql, 'from "users"'))->count(), 'login once');
    }

    public function test_a2_one_mapping_for_staff_in_login_as_client(): void
    {
        $full = $this->staff();
        $this->assertTrue(Actor::staffMayAsStaff($full, 'manageproducts', acting: true));
        $this->assertFalse(Actor::staffMayAsStaff($this->staff(['admin.users.impersonate']), 'manageproducts', acting: true));
        $this->assertFalse(Actor::staffMayAsStaff(null, 'manageproducts'));
        $this->assertFalse(Actor::staffMayAsStaff($full, 'chat'), 'none listed');
        // A2b: an entry listed as null («seeing») passes for staffMayAs, never where an admin permission is required.
        $this->assertTrue(Actor::staffMayAsStaff($full, 'products'));
        $this->assertFalse(Actor::staffMayAsStaff($full, 'products', acting: true));
        $this->assertFalse(Actor::staffMayAsStaff($full, null, acting: true));
    }

    public function test_a6_closed_account_payment_method_change_invoices_and_panel_login(): void
    {
        $user = User::factory()->create();
        $client = $user->client();
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $this->service->plan_id, 'product_id' => $this->service->product_id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        $domain = Domain::factory()->create(['client_id' => $client->id]);
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $client->id]));
        $component = new DomainShow;
        $component->domain = $domain;
        $seesInvoices = fn () => (fn () => $this->seesInvoices())->call($component);

        $this->assertTrue(ItemPaymentAccess::mayChange($service), 'baseline: open');
        $this->assertTrue(ItemPaymentAccess::mayChange($domain), 'baseline: open');
        $this->assertTrue($seesInvoices());
        $this->assertTrue(ServiceShow::loginMay($service, 'productsso'));

        Client::query()->whereKey($client->id)->update(['closed_at' => now()]);

        $this->assertFalse(ItemPaymentAccess::mayChange($service->fresh()), "a closed account's service: no payment-method change");
        $this->assertFalse(ItemPaymentAccess::mayChange($domain->fresh()), "a closed account's domain: no payment-method change");
        $this->assertFalse($seesInvoices(), "a closed account's invoices from the domain page");
        $this->assertFalse(ServiceShow::loginMay($service, 'productsso'), 'no panel login on a closed account');
        $this->assertNull(ClientUser::liveGrant($user, $client->id));
        $this->assertNotNull(ClientUser::liveGrant($user, $client->id, includeClosed: true));
    }
}
