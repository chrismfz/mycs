<?php

namespace Tests\Feature;

use App\Exceptions\ServerRefused;
use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Show;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use App\Support\StaffLink;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use MyCS\Extensions\Servers\Ngm\Ngm;
use MyCS\Extensions\Servers\Ngm\NgmApi;
use Tests\TestCase;

/**
 * ngm, our own hosting panel, as our WHMCS module drives its `/api/v1`: create, suspend, unsuspend, terminate, change
 * package, password, single sign-on, usage and the connection test; each failure left open for staff. A fake ngm here,
 * never a live panel.
 */
class NgmExtensionTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://ngm.test:9601/api/v1';

    private const TOKEN = 'ngm-token-secret';

    private User $owner;

    private Service $service;

    private Server $server;

    /** @var array<string, array<string, mixed>> accounts on ngm, by username */
    private array $accounts = [];

    /** @var list<array{0: string, 1: string, 2: string}> method, path, raw body */
    private array $calls = [];

    /** @var array<string, array{0: int, 1: array<string, mixed>|string}> "METHOD path" => status and answer */
    private array $answer = [];

    /** No answer from ngm for this "METHOD path" (once made: the account is made, the answer lost). */
    private ?string $lost = null;

    private bool $down = false;

    /** Names ngm refuses with 409 though it has no account by them (its database names clash). */
    private array $collide = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
        $this->server = Server::query()->create(['name' => 'ngm', 'extension' => 'Ngm', 'type' => 'server', 'enabled' => true]);
        $this->server->settings()->create(['key' => 'host', 'value' => 'ngm.test']);
        $this->server->settings()->create(['key' => 'token', 'value' => self::TOKEN, 'encrypted' => true]);
        $this->owner = User::factory()->create(['email' => 'shop@example.test']);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $this->server->id]);
        $product->product->settings()->create(['key' => 'package', 'value' => 'basic']);
        $product->product->settings()->create(['key' => 'php', 'value' => '8.3']);
        $this->service = Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'server_id' => $this->server->id, 'status' => 'pending', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addYear(), 'domain' => 'Example-Shop.gr']);
        Http::fake(fn (Request $request) => $this->ngm($request));
    }

    private function ngm(Request $request)
    {
        if ($this->down) {
            throw new ConnectionException('cURL error 28 for https://ngm.test:9601 ' . self::TOKEN);
        }
        $this->assertStringStartsWith(self::API . '/', $request->url());
        $this->assertSame(['Bearer ' . self::TOKEN], $request->header('Authorization'));
        $path = substr($request->url(), strlen(self::API) + 1);
        $key = "{$request->method()} {$path}";
        $this->calls[] = [$request->method(), $path, $request->body()];
        $body = json_decode($request->body(), true) ?? [];
        if (isset($this->answer[$key])) {
            [$status, $data] = $this->answer[$key];

            return Http::response($data, $status);
        }
        $user = preg_match('#^accounts/([^/]+)#', $path, $m) ? rawurldecode($m[1]) : null;
        $known = $user !== null && isset($this->accounts[$user]);
        $missing = Http::response(['error' => 'account not found'], 404);

        $response = match (true) {
            $key === 'GET ping' => Http::response(['ok' => true, 'hostname' => 'ngm', 'version' => '1.0']),
            $key === 'GET usage' => Http::response(['accounts' => [
                ['username' => 'shop', 'disk_used_mb' => 120, 'disk_limit_mb' => 1000, 'bandwidth_used_mb' => 4500, 'bandwidth_limit_mb' => -1],
                ['username' => 'other', 'disk_used_mb' => 1, 'disk_limit_mb' => 1, 'bandwidth_used_mb' => 1, 'bandwidth_limit_mb' => 1],
            ]]),
            $key === 'POST accounts' => isset($this->accounts[$body['username']]) || in_array($body['username'], $this->collide, true)
                ? Http::response(['error' => isset($this->accounts[$body['username']]) ? 'account already exists' : 'username collides with an existing database namespace'], 409)
                : tap(Http::response(['ok' => true, 'account' => ['username' => $body['username']]], 201),
                    fn () => $this->accounts[$body['username']] = $body + ['suspended' => false, 'created_at' => now()->toIso8601String()]),
            !$known => $missing,
            $request->method() === 'GET' => Http::response($this->accounts[$user]),
            $request->method() === 'DELETE' => tap(Http::response(['terminated' => true, 'backups_kept' => true]), function () use ($user) {
                unset($this->accounts[$user]);
            }),
            str_ends_with($path, '/suspend') => tap(Http::response(['account' => []]), fn () => $this->accounts[$user]['suspended'] = true),
            str_ends_with($path, '/unsuspend') => tap(Http::response(['account' => []]), fn () => $this->accounts[$user]['suspended'] = false),
            str_ends_with($path, '/package') => tap(Http::response(['account' => []]), fn () => $this->accounts[$user]['package'] = $body['package']),
            str_ends_with($path, '/password') => tap(Http::response(['ok' => true]), fn () => $this->accounts[$user]['password'] = $body['password']),
            str_ends_with($path, '/sso') => Http::response(['url' => 'https://ngm.test/ui/sso?ticket=t1', 'expires_in' => 60]),
            default => Http::response(['error' => 'not found'], 404),
        };
        if ($this->lost === $key) {
            $this->lost = null;
            throw new ConnectionException('cURL error 28: timed out');
        }

        return $response;
    }

    /** @return list<array<string, mixed>> the bodies sent to "METHOD path" */
    private function sent(string $key): array
    {
        return array_values(array_map(fn ($c) => json_decode($c[2], true), array_filter($this->calls, fn ($c) => "{$c[0]} {$c[1]}" === $key)));
    }

    private function made(string $username = 'shop'): void
    {
        $this->accounts[$username] = ['username' => $username, 'suspended' => false, 'package' => 'basic'];
        $this->service->properties()->create(['key' => Ngm::USERNAME, 'name' => 'ngm account', 'value' => $username]);
        $this->service->forceFill(['username' => $username, 'password' => 'Old-Pass-123!', 'status' => 'active'])->save();
    }

    private function extension(): Ngm
    {
        return ExtensionHelper::getExtension('server', 'Ngm', $this->server->settings);
    }

    private function failed(string $action): ?ModuleAction
    {
        return ModuleAction::query()->open()->where('service_id', $this->service->id)->where('action', $action)->first();
    }

    public function test_the_api_is_on_its_own_port_and_a_given_one_is_kept(): void
    {
        $this->assertSame('https://ngm.test:9601/api/v1', (new NgmApi('ngm.test', 't'))->baseUrl());
        $this->assertSame('https://ngm.test:8443/api/v1', (new NgmApi('https://ngm.test:8443/', 't'))->baseUrl());
        $this->assertSame('http://lab.test:9601/api/v1', (new NgmApi('http://lab.test', 't'))->baseUrl());
        $this->assertSame('https://[2001:db8::1]:9601/api/v1', (new NgmApi('[2001:db8::1]', 't'))->baseUrl());
        $this->assertSame('https://ngm.test:9601/ui/', (new NgmApi('ngm.test', 't'))->panelUrl(), 'the panel on the API\'s own port');
    }

    public function test_the_connection_test_and_a_failure_never_tells_the_token(): void
    {
        $this->assertTrue($this->extension()->testConfig());

        $this->answer['GET ping'] = [401, ['error' => 'invalid token']];
        $this->assertStringContainsString('API token was rejected', (string) $this->extension()->testConfig());

        $this->down = true;
        $message = (string) $this->extension()->testConfig();
        $this->assertStringContainsString('no answer', $message);
        $this->assertStringNotContainsString(self::TOKEN, $message);
    }

    public function test_create_makes_the_account_with_its_package_domain_and_php_and_starts_the_service(): void
    {
        ExtensionHelper::createServer($this->service->fresh());

        [$sent] = $this->sent('POST accounts');
        $service = $this->service->fresh();
        $this->assertSame(['exampleshop', 'basic', 'example-shop.gr', '8.3', true], [$sent['username'], $sent['package'], $sent['domain'], $sent['php'], $sent['enabled']]);
        $this->assertSame($sent['password'], $service->password, 'the password kept, encrypted');
        $this->assertSame(20, strlen($sent['password']));
        $this->assertSame(['exampleshop', 'active'], [$service->username, $service->status]);
        $properties = $service->properties()->pluck('value', 'key')->all();
        $this->assertSame('exampleshop', $properties[Ngm::USERNAME]);
        $this->assertArrayNotHasKey(Ngm::PENDING, $properties);
    }

    public function test_a_given_username_is_cleaned_as_ngm_takes_it_and_a_taken_one_is_never_used(): void
    {
        $this->service->forceFill(['username' => 'Shop-Name.1'])->save();
        ExtensionHelper::createServer($this->service->fresh());
        $this->assertSame('shopname1', $this->sent('POST accounts')[0]['username']);
        $this->assertSame('u1abc', Ngm::sanitize('1ABC'));
        $this->assertSame(32, strlen(Ngm::sanitize(str_repeat('a', 40))));

        // Another service's given name ngm has already: told, nothing made.
        $other = $this->service->replicate();
        $other->forceFill(['username' => 'shopname1', 'status' => 'pending'])->save();
        $this->assertFalse(rescue(fn () => ExtensionHelper::createServer($other->fresh()), false, false));
        $this->assertCount(1, $this->sent('POST accounts'));
        $this->assertStringContainsString('is taken on this server', (string) ModuleAction::query()->open()->where('service_id', $other->id)->value('last_error'));
    }

    public function test_a_name_from_the_domain_taken_on_ngm_or_here_gets_a_digit(): void
    {
        $this->accounts['exampleshop'] = ['username' => 'exampleshop'];
        $other = $this->service->replicate();
        $other->forceFill(['username' => 'exampleshop1', 'status' => 'active'])->save();

        ExtensionHelper::createServer($this->service->fresh());

        $this->assertSame('exampleshop2', $this->service->fresh()->username);
    }

    public function test_a_lost_answer_is_retried_with_the_same_name_and_password_and_adopted(): void
    {
        Exceptions::fake();
        $this->lost = 'POST accounts';
        $this->assertFalse(rescue(fn () => ExtensionHelper::createServer($this->service->fresh()), false, false));
        $this->assertNotNull($this->failed('create'), 'left open for staff');
        $this->assertSame('exampleshop', $this->service->properties()->where('key', Ngm::PENDING)->value('value'));
        $password = $this->service->fresh()->password;

        ExtensionHelper::createServer($this->service->fresh());

        $sent = $this->sent('POST accounts');
        $this->assertCount(2, $sent);
        $this->assertSame([$password, 'exampleshop'], [$sent[1]['password'], $sent[1]['username']], 'the same name and password asked again');
        $this->assertCount(1, $this->accounts, 'one account');
        $service = $this->service->fresh();
        $this->assertSame(['exampleshop', 'active'], [$service->username, $service->status]);
        $this->assertNull($this->failed('create'));
        Exceptions::assertReported(fn (Exception $e) => str_contains($e->getMessage(), 'adopted «exampleshop», made by an earlier attempt'));
    }

    public function test_a_name_ngm_refuses_as_taken_is_given_up_for_the_next(): void
    {
        // ngm's DB-namespace collision: the exact name absent, yet 409 (review of #630: never the same name for ever).
        $this->collide = ['exampleshop'];

        ExtensionHelper::createServer($this->service->fresh());

        $this->assertSame(['exampleshop', 'exampleshop1'], array_column($this->sent('POST accounts'), 'username'));
        $this->assertSame(['exampleshop1', 'active'], [$this->service->fresh()->username, $this->service->fresh()->status]);
        $this->assertSame(['exampleshop1'], array_keys($this->accounts), 'never adopted');
    }

    public function test_a_taken_name_never_asked_for_is_never_adopted(): void
    {
        // Every name refused as taken: told, nothing kept.
        $this->answer['POST accounts'] = [409, ['error' => 'username collides with an existing database namespace']];

        $this->assertFalse(rescue(fn () => ExtensionHelper::createServer($this->service->fresh()), false, false));

        $this->assertCount(10, $this->sent('POST accounts'), 'each free name tried once');
        $this->assertNull($this->service->fresh()->username);
        $this->assertNull($this->service->properties()->where('key', Ngm::PENDING)->value('value'), 'refused: its name taken off');
        $this->assertStringContainsString('ngm: username collides', (string) $this->failed('create')?->last_error);
        $this->assertSame('pending', $this->service->fresh()->status);
    }

    public function test_a_kept_name_another_service_now_has_is_never_adopted(): void
    {
        $this->service->properties()->create(['key' => Ngm::PENDING, 'name' => 'p', 'value' => 'exampleshop']);
        $this->accounts['exampleshop'] = ['username' => 'exampleshop'];
        $other = $this->service->replicate();
        $other->forceFill(['username' => 'exampleshop', 'status' => 'active'])->save();

        $this->assertFalse(rescue(fn () => ExtensionHelper::createServer($this->service->fresh()), false, false));

        $this->assertNull($this->service->fresh()->username);
    }

    public function test_a_refused_domain_is_ngms_reason_and_nothing_is_kept(): void
    {
        $this->answer['POST accounts'] = [422, ['error' => 'primary site example-shop.gr cannot be created: it belongs to another account']];

        try {
            $this->extension()->createServer($this->service->fresh(), ['package' => 'basic'], []);
            $this->fail('refused');
        } catch (ServerRefused $e) {
            $this->assertSame('ngm: primary site example-shop.gr cannot be created: it belongs to another account', $e->getMessage());
        }
        $this->assertNull($this->service->properties()->where('key', Ngm::PENDING)->value('value'));
    }

    public function test_an_error_of_ngms_own_is_not_the_customers_to_read_and_keeps_the_name(): void
    {
        $this->answer['POST accounts'] = [500, ['error' => 'provision failed']];

        try {
            $this->extension()->createServer($this->service->fresh(), [], []);
            $this->fail('failed');
        } catch (Exception $e) {
            $this->assertNotInstanceOf(ServerRefused::class, $e);
            $this->assertSame('ngm POST /accounts: HTTP 500 (provision failed)', $e->getMessage());
        }
        $this->assertSame('exampleshop', $this->service->properties()->where('key', Ngm::PENDING)->value('value'), 'its outcome not known: the name kept');
    }

    public function test_a_service_with_an_account_is_never_created_again(): void
    {
        $this->made();
        $this->service->forceFill(['status' => 'pending'])->save();

        $this->assertFalse(rescue(fn () => ExtensionHelper::createServer($this->service->fresh()), false, false));
        $this->assertSame([], $this->sent('POST accounts'));
    }

    public function test_ngms_warnings_are_reported_and_the_action_done(): void
    {
        Exceptions::fake();
        $this->answer['POST accounts'] = [201, ['ok' => true, 'account' => [], 'warnings' => ['primary site not created: disk full']]];

        ExtensionHelper::createServer($this->service->fresh());

        $this->assertSame('active', $this->service->fresh()->status);
        Exceptions::assertReported(fn (Exception $e) => str_contains($e->getMessage(), 'primary site not created: disk full'));
    }

    public function test_suspend_carries_its_reason_and_unsuspend_an_empty_object(): void
    {
        $this->made();
        $this->service->forceFill(['suspend_reason' => 'Overdue on Payment'])->save();

        ExtensionHelper::suspendServer($this->service->fresh());
        $this->assertSame([['reason' => 'Overdue on Payment']], $this->sent('POST accounts/shop/suspend'));
        $this->assertTrue($this->accounts['shop']['suspended']);

        ExtensionHelper::unsuspendServer($this->service->fresh());
        $this->assertFalse($this->accounts['shop']['suspended']);
        $raw = array_values(array_filter($this->calls, fn ($c) => $c[1] === 'accounts/shop/unsuspend'))[0][2];
        $this->assertSame('{}', $raw, 'an object, never []');
    }

    public function test_a_failed_suspend_is_left_open_for_staff(): void
    {
        $this->made();
        $this->answer['POST accounts/shop/suspend'] = [403, ['error' => 'account write API disabled']];

        $this->assertFalse(rescue(fn () => ExtensionHelper::suspendServer($this->service->fresh()), false, false));

        $this->assertStringContainsString('HTTP 403: refused (the token needs the scopes read and accounts', (string) $this->failed('suspend')?->last_error);
    }

    public function test_never_an_account_another_service_claims_or_ngm_lacks(): void
    {
        $this->made();
        $other = $this->service->replicate();
        $other->save();

        $this->assertFalse(rescue(fn () => ExtensionHelper::suspendServer($this->service->fresh()), false, false));
        $this->assertStringContainsString('another service here has the account «shop»', (string) $this->failed('suspend')?->last_error);

        $other->forceFill(['username' => 'other'])->save();
        unset($this->accounts['shop']);
        $this->assertFalse(rescue(fn () => ExtensionHelper::unsuspendServer($this->service->fresh()), false, false));
        $this->assertStringContainsString('no account «shop» on this server', (string) $this->failed('unsuspend')?->last_error);

        $this->assertSame([], array_filter($this->calls, fn ($c) => $c[0] !== 'GET'), 'nothing but reads');
    }

    public function test_terminate_deletes_the_account_and_keeps_its_name(): void
    {
        $this->made();

        ExtensionHelper::terminateServer($this->service->fresh());

        $this->assertCount(1, array_filter($this->calls, fn ($c) => $c[0] === 'DELETE' && $c[1] === 'accounts/shop'));
        $this->assertSame([], $this->accounts);
        $service = $this->service->fresh();
        $this->assertSame('shop', $service->username, 'kept: ngm keeps its backups under it');
        $this->assertNull($service->properties()->where('key', Ngm::USERNAME)->value('value'));
    }

    public function test_terminate_of_an_account_already_gone_is_done_only_for_one_made_here(): void
    {
        $this->made();
        unset($this->accounts['shop']);
        ExtensionHelper::terminateServer($this->service->fresh());
        $this->assertNull($this->failed('terminate'));

        // An imported service (its username from WHMCS, no mark of ours) whose name ngm does not have: staff's to check.
        $this->service->forceFill(['status' => 'active'])->save();
        $this->assertFalse(rescue(fn () => ExtensionHelper::terminateServer($this->service->fresh()), false, false));
        $this->assertStringContainsString('no account «shop»', (string) $this->failed('terminate')?->last_error);
        $this->assertSame([], array_filter($this->calls, fn ($c) => $c[0] === 'DELETE'));
    }

    public function test_an_imported_account_is_driven_by_its_username(): void
    {
        $this->accounts['legacy'] = ['username' => 'legacy', 'suspended' => false];
        $this->service->forceFill(['username' => 'legacy', 'status' => 'active'])->save();

        ExtensionHelper::suspendServer($this->service->fresh());

        $this->assertTrue($this->accounts['legacy']['suspended']);
    }

    public function test_a_package_change_sends_the_new_products_package(): void
    {
        $this->made();
        $this->service->product->settings()->where('key', 'package')->update(['value' => 'pro']);

        ExtensionHelper::upgradeServer($this->service->fresh());
        $this->assertSame([['package' => 'pro']], $this->sent('POST accounts/shop/package'));

        $this->service->product->settings()->where('key', 'package')->delete();
        $this->assertFalse(rescue(fn () => ExtensionHelper::upgradeServer($this->service->fresh()), false, false));
        $this->assertSame('ngm: the product has no package', $this->failed('upgrade')?->last_error);
        $this->assertCount(1, $this->sent('POST accounts/shop/package'));
    }

    public function test_a_new_password_is_kept_once_ngm_took_it_and_its_refusal_is_the_customers_to_read(): void
    {
        $this->made();

        $this->assertTrue($this->extension()->changePassword($this->service->fresh(), [], ['ngm_username' => 'shop'], 'N3w-Strong-Pass!'));
        $this->assertSame([['password' => 'N3w-Strong-Pass!']], $this->sent('POST accounts/shop/password'));
        $this->assertSame('N3w-Strong-Pass!', $this->service->fresh()->password);

        $this->answer['POST accounts/shop/password'] = [400, ['error' => 'password must contain a symbol']];
        try {
            $this->extension()->changePassword($this->service->fresh(), [], ['ngm_username' => 'shop'], 'weakpassword');
            $this->fail('refused');
        } catch (ServerRefused $e) {
            $this->assertSame('ngm: password must contain a symbol', $e->getMessage());
        }
        $this->assertSame('N3w-Strong-Pass!', $this->service->fresh()->password);
    }

    public function test_single_sign_on_and_a_host_without_it(): void
    {
        $this->made();
        $properties = ['ngm_username' => 'shop'];

        $this->assertSame('https://ngm.test/ui/sso?ticket=t1', $this->extension()->getLoginUrl($this->service->fresh(), [], $properties));
        $this->assertSame([[]], array_map(fn ($b) => (array) $b, $this->sent('POST accounts/shop/sso')));

        // Staff's (a fourth argument, as Admin\ServicePanelLoginController calls it): ngm's admin ticket.
        $this->extension()->getLoginUrl($this->service->fresh(), [], $properties, null);
        $this->assertSame(['admin' => true], $this->sent('POST accounts/shop/sso')[1]);

        // A 403 for another reason (the token's scope) is not called «SSO off».
        $this->answer['POST accounts/shop/sso'] = [403, ['error' => 'token lacks required scope: accounts']];
        $this->assertStringContainsString('token lacks required scope', (string) rescue(fn () => $this->extension()->getLoginUrl($this->service->fresh(), [], $properties), fn ($e) => $e->getMessage(), false));

        $this->answer['POST accounts/shop/sso'] = [403, ['error' => 'account SSO API is disabled (set api.allow_sso: true)']];
        $this->expectExceptionMessage('ngm: single sign-on is not enabled on this host');
        $this->extension()->getLoginUrl($this->service->fresh(), [], $properties);
    }

    public function test_staff_sign_in_to_a_suspended_account_with_the_admin_ticket(): void
    {
        $this->made();
        $this->service->forceFill(['status' => 'suspended'])->save();
        $this->actingAs(Staff::factory()->withPermissions(['*'])->create(), 'staff');

        $this->get(StaffLink::to('filament.admin.sso.service', ['service' => $this->service->id]))
            ->assertRedirect('https://ngm.test/ui/sso?ticket=t1');

        $this->assertSame([['admin' => true]], $this->sent('POST accounts/shop/sso'));
    }

    public function test_the_page_shows_the_account_and_signs_in_through_a_new_tab(): void
    {
        $this->made();
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->service->client_id]));

        $page = Livewire::test(Show::class, ['service' => $this->service]);
        $page->assertSee('shop')->assertSee('https://ngm.test:9601/ui/')->assertSee(__('services.ngm.login'));
        $page->assertSee(route('services.panel-login', $this->service), false);
        $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl'])->assertRedirect('https://ngm.test/ui/sso?ticket=t1');
    }

    public function test_usage_from_one_call(): void
    {
        $this->made();

        $this->artisan('services:usage')->assertExitCode(0);

        $usage = $this->service->usage()->sole();
        $this->assertSame([120, 1000, 4500, null], [$usage->disk_used_mb, $usage->disk_limit_mb, $usage->bandwidth_used_mb, $usage->bandwidth_limit_mb]);
        $this->assertCount(1, $this->sent('GET usage'));
    }
}
