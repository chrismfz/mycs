<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Livewire\Services\Show;
use App\Models\ClientUser;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use App\Support\StaffLink;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * «Login as client» and the panels' sign-ins open in a new tab (owner 2026-10-26): the admin by a signed link for its
 * staff member (StaffLink), the client area by a form (CSRF); each with today's permission and audit, the panel's
 * one-time address never logged, and both tabs working at once.
 */
class NewTabLoginsTest extends TestCase
{
    use RefreshDatabase;

    private const PANEL = 'https://orion.test:2083/cpsess1/login/?session=secret-token';

    private User $owner;

    private Service $service;

    private string $answer = self::PANEL;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        config(['settings.default_currency' => 'USD']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $server = Server::query()->create(['name' => 'Orion', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->owner = User::factory()->create();
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $this->service = $this->makeService($this->owner, $server, $product);
        Http::fake(fn (Request $request) => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/create_user_session')
            ? Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['url' => $this->answer . ($request['app'] ?? '')]])
            : Http::response([], 404));
    }

    private function makeService(User $user, Server $server, $product): Service
    {
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addMonth(),
            'domain' => 'zonecloud.io', 'username' => 'zonecl']);
        $service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'zonecl']);

        return $service;
    }

    private function staff(array $permissions = ['*']): Staff
    {
        $staff = Staff::factory()->withPermissions($permissions)->create();
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    private function sessions(): int
    {
        return collect(Http::recorded())->filter(fn ($pair) => str_ends_with((string) parse_url($pair[0]->url(), PHP_URL_PATH), '/create_user_session'))->count();
    }

    private function panelLink(?Service $service = null): string
    {
        return StaffLink::to('filament.admin.sso.service', ['service' => ($service ?? $this->service)->id]);
    }

    /** CSRF checked as outside the tests (Laravel skips it while running them). */
    private function enforceCsrf(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    // The admin's «Log in to panel».

    public function test_staff_sign_into_the_panel_in_a_new_tab_audited_and_the_address_never_kept(): void
    {
        $staff = $this->staff();

        $response = $this->get($this->panelLink())->assertRedirect(self::PANEL);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $audit = Audit::query()->where('event', 'panel_login')->sole();
        $this->assertSame($staff->id, $audit->new_values['staff_id']);
        $this->assertStringNotContainsString('secret-token', json_encode([$audit->new_values, $audit->old_values, $audit->url]));
    }

    public function test_the_admin_button_is_a_link_into_a_new_tab(): void
    {
        $this->staff();

        $this->get(ServiceResource::getUrl('edit', ['record' => $this->service]))->assertOk()
            ->assertSee('/sysadmin/services/' . $this->service->id . '/panel-login', false)->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener noreferrer"', false);
        $this->assertSame(0, $this->sessions(), 'the page asked the panel for a session before a click');
    }

    public function test_an_unsigned_or_altered_link_is_refused(): void
    {
        $this->staff();
        $other = $this->makeService(User::factory()->create(), $this->service->server, (object) ['plan' => $this->service->plan, 'product' => $this->service->product]);

        // A page elsewhere that links (or embeds) the route: no signature.
        $this->get('/sysadmin/services/' . $this->service->id . '/panel-login')->assertForbidden();
        $this->get('/sysadmin/services/' . $this->service->id . '/panel-login?by=' . Auth::guard('staff')->id())->assertForbidden();
        // A signed link with another service in it.
        $this->get(str_replace('/services/' . $this->service->id . '/', '/services/' . $other->id . '/', $this->panelLink()))->assertForbidden();

        $this->assertSame(0, $this->sessions());
        $this->assertSame(0, Audit::query()->where('event', 'panel_login')->count());
    }

    public function test_another_staff_members_link_is_refused(): void
    {
        $this->staff();
        $link = $this->panelLink();
        $this->staff();

        $this->get($link)->assertForbidden();
        $this->assertSame(0, $this->sessions());
    }

    public function test_an_expired_link_goes_back_to_the_service_to_try_again(): void
    {
        $this->staff();
        $link = $this->panelLink();
        $this->travel(StaffLink::MINUTES + 1)->minutes();

        $this->get($link)->assertRedirect(ServiceResource::getUrl('edit', ['record' => $this->service]));
        $this->assertSame(0, $this->sessions());
    }

    public function test_without_the_sso_permission_or_logged_out_it_is_refused(): void
    {
        $this->staff(['admin.services.viewAny', 'admin.services.update']);
        $this->get($this->panelLink())->assertForbidden();

        $this->service->update(['status' => 'terminated']);
        $this->staff();
        $this->get($this->panelLink())->assertForbidden();
        $this->assertSame(0, $this->sessions());

        $link = $this->panelLink();
        Auth::guard('staff')->logout();
        $this->get($link)->assertRedirect();
        $this->assertSame(0, $this->sessions());
    }

    public function test_a_server_that_answers_no_web_page_is_not_followed(): void
    {
        $this->staff();
        $this->answer = 'javascript:alert(1)';

        $this->get($this->panelLink())->assertRedirect(ServiceResource::getUrl('edit', ['record' => $this->service]));
        $this->assertSame(0, Audit::query()->where('event', 'panel_login')->count());
    }

    // The admin's «Login as client».

    public function test_login_as_client_opens_in_a_new_tab_and_the_admin_tab_keeps_working(): void
    {
        $staff = $this->staff(['admin.clients.viewAny', 'admin.clients.update', 'admin.users.impersonate']);
        $client = $this->owner->client();

        $this->get(ClientResource::getUrl('edit', ['record' => $client]))->assertOk()
            ->assertSee('/sysadmin/clients/' . $client->id . '/login-as', false)->assertSee('target="_blank"', false);
        $this->get(StaffLink::to('filament.admin.login-as.client', ['client' => $client->id]))->assertRedirect('/dashboard');
        $this->assertSame($this->owner->id, Session::get('impersonating'));
        $this->assertSame($staff->id, Audit::query()->where('event', 'impersonated')->sole()->new_values['staff_id']);

        // The admin tab: still the staff member's, and it leaves the client's tab on.
        $this->get(ClientResource::getUrl('edit', ['record' => $client]))->assertOk();
        $this->assertSame($this->owner->id, Session::get('impersonating'));
        $this->assertNull(Auth::guard('web')->user(), 'the admin saw the customer as its login');
        // The client's tab: the customer.
        $this->get('/dashboard')->assertOk();
        $this->assertSame($this->owner->id, Auth::guard('web')->id());
    }

    public function test_login_as_a_user_opens_in_a_new_tab(): void
    {
        $this->staff(['admin.users.viewAny', 'admin.users.update', 'admin.users.impersonate']);

        $this->get(StaffLink::to('filament.admin.login-as.user', ['user' => $this->owner->id]))->assertRedirect('/dashboard');
        $this->assertSame($this->owner->id, Session::get('impersonating'));
        $this->assertFalse(Session::has(CurrentClient::IMPERSONATING_KEY));
    }

    public function test_login_as_client_is_refused_unsigned_or_without_the_permission(): void
    {
        $client = $this->owner->client();
        $this->staff(['admin.clients.viewAny', 'admin.clients.update']);
        $this->get(StaffLink::to('filament.admin.login-as.client', ['client' => $client->id]))->assertForbidden();

        $this->staff(['admin.clients.viewAny', 'admin.users.impersonate']);
        $this->get(StaffLink::to('filament.admin.login-as.client', ['client' => $client->id]))->assertForbidden();

        $this->staff(['*']);
        $this->get('/sysadmin/clients/' . $client->id . '/login-as')->assertForbidden();
        $this->get('/sysadmin/users/' . $this->owner->id . '/login-as')->assertForbidden();
        $link = StaffLink::to('filament.admin.login-as.user', ['user' => $this->owner->id]);
        $this->get(str_replace('/users/' . $this->owner->id . '/', '/users/' . User::factory()->create()->id . '/', $link))->assertForbidden();

        $this->assertFalse(Session::has('impersonating'));
        $this->assertSame(0, Audit::query()->where('event', 'impersonated')->count());
    }

    public function test_an_expired_login_as_link_goes_back_to_the_client(): void
    {
        $this->staff();
        $link = StaffLink::to('filament.admin.login-as.client', ['client' => $this->owner->client()->id]);
        $this->travel(StaffLink::MINUTES + 1)->minutes();

        $this->get($link)->assertRedirect(ClientResource::getUrl('view', ['record' => $this->owner->client()]));
        $this->assertFalse(Session::has('impersonating'));
    }

    public function test_leave_ends_it_back_at_the_client(): void
    {
        $this->staff();
        $client = $this->owner->client();
        $this->withSession(['impersonating' => $this->owner->id, CurrentClient::IMPERSONATING_KEY => $client->id]);

        $this->get('/dashboard')->assertOk()->assertSee(route('impersonation.leave'), false);
        Auth::guard('web')->forgetUser(); // a new request: the customer was this request's only (onceUsingId)
        $this->post(route('impersonation.leave'))->assertRedirect(ClientResource::getUrl('view', ['record' => $client]));
        $this->assertFalse(Session::has('impersonating'));
        $this->assertFalse(Session::has(CurrentClient::IMPERSONATING_KEY));
    }

    public function test_leave_needs_the_forms_csrf_token(): void
    {
        $this->staff();
        $this->withSession(['impersonating' => $this->owner->id]);
        $this->enforceCsrf();

        $this->post(route('impersonation.leave'))->assertStatus(419);
        $this->assertTrue(Session::has('impersonating'));
    }

    // The client area's sign-ins.

    private function asOwner(?User $user = null): void
    {
        $user ??= $this->owner;
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $this->service->client_id]));
    }

    public function test_the_customer_signs_in_by_a_form_into_a_new_tab(): void
    {
        $this->asOwner();

        Livewire::test(Show::class, ['service' => $this->service])->assertSee(route('services.panel-login', $this->service), false)
            ->assertSee('target="_blank"', false);
        $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl'])->assertRedirect(self::PANEL);
        $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl', 'app' => 'Email_Accounts'])
            ->assertRedirect(self::PANEL . 'Email_Accounts');
        $this->assertSame(2, $this->sessions());
        // The customer's own: no staff in it, no audit (as before).
        $this->assertSame(0, Audit::query()->where('event', 'panel_login')->count());
    }

    public function test_never_another_clients_service(): void
    {
        $this->asOwner(User::factory()->create());

        $status = $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl'])->status();
        $this->assertContains($status, [403, 404]);
        $this->assertSame(0, $this->sessions());
    }

    public function test_a_login_without_productsso_a_call_not_offered_or_a_suspended_service_is_refused(): void
    {
        $viewer = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $this->service->client_id, 'user_id' => $viewer->id, 'permissions' => ['products'], 'granted_at' => now()]);
        $this->asOwner($viewer);
        $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl'])->assertRedirect(route('services.show', $this->service));

        $this->asOwner();
        foreach ([['function' => 'changePassword'], ['function' => 'terminate'], ['function' => 'getLoginUrl', 'app' => 'Evil_App'], ['function' => 'getWebmailUrl', 'app' => 'Email_Accounts'], []] as $input) {
            $this->post(route('services.panel-login', $this->service), $input)->assertRedirect(route('services.show', $this->service));
        }
        $this->service->update(['status' => Service::STATUS_SUSPENDED]);
        $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl'])->assertRedirect(route('services.show', $this->service));

        $this->assertSame(0, $this->sessions());
    }

    public function test_the_client_area_form_needs_its_csrf_token_and_is_never_a_get(): void
    {
        $this->asOwner();
        $this->enforceCsrf();

        $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl'])->assertStatus(419);
        $this->get(route('services.panel-login', $this->service) . '?function=getLoginUrl')->assertStatus(405);
        $this->assertSame(0, $this->sessions());

        $this->withSession(['_token' => 'the-form-token'])->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl', '_token' => 'the-form-token'])
            ->assertRedirect(self::PANEL);
    }

    public function test_staff_logged_in_as_the_client_need_the_admin_permission_and_are_audited(): void
    {
        $session = fn () => [CurrentClient::SESSION_KEY => $this->service->client_id, 'impersonating' => $this->owner->id, CurrentClient::IMPERSONATING_KEY => $this->service->client_id];
        $this->staff(['admin.users.impersonate']);
        $this->withSession($session());
        $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl'])->assertRedirect(route('services.show', $this->service));
        $this->assertSame(0, $this->sessions());

        Auth::forgetGuards();
        $staff = $this->staff(['admin.users.impersonate', 'admin.services.sso']);
        $this->withSession($session());
        $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl'])->assertRedirect(self::PANEL);
        $audit = Audit::query()->where('event', 'panel_login')->sole();
        $this->assertSame($staff->id, $audit->new_values['staff_id']);
        $this->assertStringNotContainsString('secret-token', json_encode($audit->new_values));
    }

    public function test_a_page_opened_as_one_client_never_acts_as_another_staff_logged_in_as_since(): void
    {
        $this->staff(['admin.users.impersonate']);
        Session::put(['impersonating' => $this->owner->id, CurrentClient::IMPERSONATING_KEY => $this->service->client_id]);
        Auth::guard('web')->setUser($this->owner);
        Auth::shouldUse('web'); // the client area's (UseWebGuard)
        $page = Livewire::test(Show::class, ['service' => $this->service]);
        $page->call('$refresh')->assertStatus(200);

        // In another tab, staff log in as someone else.
        $other = User::factory()->create();
        Session::put(['impersonating' => $other->id, CurrentClient::IMPERSONATING_KEY => $other->client()->id]);

        $page->call('$refresh')->assertStatus(419);
    }

    /** Review of #465: behind a proxy the request comes as http://; the link is checked on the forced root and scheme. */
    public function test_a_link_through_a_proxy_that_passes_http_is_still_ours(): void
    {
        $this->staff();
        URL::forceRootUrl('https://mycs.example');
        URL::forceScheme('https');
        $link = $this->panelLink();
        $this->assertStringStartsWith('https://mycs.example/', $link);

        $this->get('http://localhost' . parse_url($link, PHP_URL_PATH) . '?' . parse_url($link, PHP_URL_QUERY))->assertRedirect(self::PANEL);
    }

    /** Review of #465: a module whose actions come from its API, down for the moment, is a failure, not «not allowed». */
    public function test_a_module_that_cannot_list_its_actions_says_it_failed(): void
    {
        $this->asOwner();
        $this->service->server->update(['extension' => 'NoSuchModule']);

        $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl'])->assertRedirect(route('services.show', $this->service));
        $this->assertSame(__('services.cpanel.failed'), Session::get('notification')['message'] ?? null);
        $this->assertSame(0, $this->sessions());
    }

    /** Review of #465: the admin's own components never get the client area's check (no 419 when staff change client). */
    public function test_the_admins_components_go_on_when_staff_log_in_as_someone_else(): void
    {
        $this->staff();
        Session::put(['impersonating' => $this->owner->id, CurrentClient::IMPERSONATING_KEY => $this->owner->client()->id]);
        $page = Livewire::test(EditService::class, ['record' => $this->service->getRouteKey()]);

        Session::forget(['impersonating', CurrentClient::IMPERSONATING_KEY]);
        $page->call('$refresh')->assertStatus(200);
        Session::put(['impersonating' => User::factory()->create()->id]);
        $page->call('$refresh')->assertStatus(200);
    }

    /** Review of #465: a page opened as the client answers 419 once staff left in another tab. */
    public function test_a_page_opened_as_the_client_never_acts_after_leave(): void
    {
        $this->staff(['admin.users.impersonate']);
        Session::put(['impersonating' => $this->owner->id, CurrentClient::IMPERSONATING_KEY => $this->service->client_id]);
        Auth::guard('web')->setUser($this->owner);
        Auth::shouldUse('web');
        $page = Livewire::test(Show::class, ['service' => $this->service]);

        Session::forget(['impersonating', CurrentClient::IMPERSONATING_KEY]);
        $page->call('$refresh')->assertStatus(419);
    }

    /** Review of #465: a customer really logged in in the same browser stays so after the admin's pages. */
    public function test_a_customer_really_logged_in_stays_so_after_the_admin(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer)->withSession($this->loginUser($customer, ['impersonating' => $this->owner->id]));
        $this->staff();
        $this->get('/sysadmin')->assertOk();
        $this->assertNull(Auth::guard('web')->user());

        Auth::guard('web')->forgetUser(); // a new request
        $this->withSession(['impersonating' => null])->get('/dashboard')->assertOk();
        $this->assertSame($customer->id, Auth::guard('web')->id());
    }

    public function test_url_generator_signs_with_the_staff_member(): void
    {
        $staff = $this->staff();

        $link = $this->panelLink();
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $this->assertSame((string) $staff->id, (string) $query['by']);
        $this->assertArrayHasKey('expires', $query);
        $this->assertTrue(URL::hasValidSignature(\Illuminate\Http\Request::create($link)));
    }
}
