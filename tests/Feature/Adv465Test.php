<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource;
use App\Livewire\Services\Show;
use App\Models\ClientUser;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Support\Actor;
use App\Support\CurrentClient;
use App\Support\StaffLink;
use Filament\Facades\Filament;
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
 * Adversarial review of #465 («Login as client» and panel sign-ins in a new tab). test_adv_N_* fail until fixed;
 * test_control_* are attacks tried that are blocked, kept as regression controls.
 */
class Adv465Test extends TestCase
{
    use RefreshDatabase;

    private const PANEL = 'https://orion.test:2083/cpsess1/login/?session=secret-token';

    private User $owner;

    private Service $service;

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
        $this->service = Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD',
            'price' => 10, 'expires_at' => now()->addMonth(), 'domain' => 'zonecloud.io', 'username' => 'zonecl']);
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'zonecl']);
        Http::fake(fn (Request $request) => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/create_user_session')
            ? Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['url' => self::PANEL . ($request['app'] ?? '')]])
            : Http::response([], 404));
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

    private function panelLink(): string
    {
        return StaffLink::to('filament.admin.sso.service', ['service' => $this->service->id]);
    }

    private function asOwner(?User $user = null): void
    {
        $user ??= $this->owner;
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $this->service->client_id]));
    }

    private function impersonating(array $permissions): Staff
    {
        $staff = $this->staff($permissions);
        $this->withSession([CurrentClient::SESSION_KEY => $this->service->client_id, 'impersonating' => $this->owner->id,
            CurrentClient::IMPERSONATING_KEY => $this->service->client_id]);

        return $staff;
    }

    // Findings.

    /**
     * ADV-1: the admin's «Log in to panel» route checks only `admin.services.sso`. Before #465 the action lived on
     * EditService, which Filament opens only for staff who may edit the service (`admin.services.update`); the route
     * now signs a member with the SSO permission alone (no viewAny, no update) into the customer's panel.
     */
    public function test_adv_1_admin_panel_login_needs_the_rights_on_the_service_its_page_needed(): void
    {
        $this->staff(['admin.services.sso']);

        $response = $this->get($this->panelLink());

        $this->assertNotSame(self::PANEL, $response->headers->get('Location'), 'staff without rights on the service were signed into its panel');
        $this->assertContains($response->status(), [403, 404]);
        $this->assertSame(0, $this->sessions());
        $this->assertSame(0, Audit::query()->where('event', 'panel_login')->count());
    }

    /**
     * ADV-2 (not a regression, now documented as equivalent to the admin's): staff in «Login as client» sign into the
     * panel through the client area with `admin.services.update` alone, since `productsso` maps to it
     * (Actor::STAFF_PERMISSION_AS). A role that is denied `admin.services.sso` (the dedicated permission since #400) gets
     * the same one-time panel session one tab over; the PR even audits it «as the admin's Log in to panel».
     */
    public function test_adv_2_impersonating_staff_without_the_sso_permission_cannot_sign_into_the_panel(): void
    {
        $this->impersonating(['admin.users.impersonate', 'admin.services.update']);

        $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl']);

        $this->assertSame(0, $this->sessions(), 'staff denied admin.services.sso got a panel session via Login as client');
    }

    /**
     * ADV-3: arrays in the form's input are cast to strings ((string) $request->input(...)): «Array to string
     * conversion» becomes a 500 (and an exception report) instead of a refusal.
     */
    public function test_adv_3_array_input_is_refused_not_a_server_error(): void
    {
        $this->asOwner();

        foreach ([['function' => ['getLoginUrl']], ['function' => 'getLoginUrl', 'app' => ['Email_Accounts']]] as $input) {
            $status = $this->post(route('services.panel-login', $this->service), $input)->status();
            $this->assertLessThan(500, $status, 'input ' . json_encode($input) . ' gave ' . $status);
        }
        $this->assertSame(0, $this->sessions());
    }

    // Controls: attacks tried that are blocked.

    /** Control: an extra query parameter appended to a good signed link breaks its signature. */
    public function test_control_extra_query_parameter_is_refused(): void
    {
        $this->staff();

        $this->get($this->panelLink() . '&x=1')->assertForbidden();
        $this->get(StaffLink::to('filament.admin.login-as.client', ['client' => $this->owner->client()->id]) . '&client=999')->assertForbidden();
        $this->assertSame(0, $this->sessions());
        $this->assertFalse(Session::has('impersonating'));
    }

    /** Control: a link with `by` changed to the opener's id (another member's link, rewritten) fails its signature. */
    public function test_control_by_rewritten_to_the_current_staff_is_refused(): void
    {
        $other = $this->staff();
        $link = $this->panelLink();
        $me = $this->staff();

        $this->get(str_replace('by=' . $other->id, 'by=' . $me->id, $link))->assertForbidden();
        $this->assertSame(0, $this->sessions());
    }

    /** Control: a relative signature (URL::signedRoute absolute: false) is not accepted by the absolute check. */
    public function test_control_relative_signature_is_refused(): void
    {
        $staff = $this->staff();
        $relative = URL::temporarySignedRoute('filament.admin.sso.service', now()->addHour(), ['service' => $this->service->id, 'by' => $staff->id], false);

        $this->get($relative)->assertForbidden();
        $this->assertSame(0, $this->sessions());
    }

    /** Control: a signed link without `by` (made in a context without the staff guard) is refused. */
    public function test_control_link_without_by_is_refused(): void
    {
        $this->staff();
        $link = URL::temporarySignedRoute('filament.admin.sso.service', now()->addHour(), ['service' => $this->service->id]);

        $this->get($link)->assertForbidden();
        $this->assertSame(0, $this->sessions());
    }

    /** Control: «Login as client» needs edit rights on the client; «Login as user» edit rights on the user. */
    public function test_control_login_as_without_edit_rights_is_refused(): void
    {
        $this->staff(['admin.users.impersonate', 'admin.clients.viewAny', 'admin.users.viewAny']);

        $this->get(StaffLink::to('filament.admin.login-as.client', ['client' => $this->owner->client()->id]))->assertForbidden();
        $this->get(StaffLink::to('filament.admin.login-as.user', ['user' => $this->owner->id]))->assertForbidden();
        $this->assertFalse(Session::has('impersonating'));
    }

    /** Control: the client area's sign-in is refused for a revoked grant, and for a grant with productsso but not products. */
    public function test_control_revoked_or_partial_grant_is_refused(): void
    {
        $revoked = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $this->service->client_id, 'user_id' => $revoked->id, 'permissions' => null,
            'granted_at' => now(), 'revoked_at' => now()]);
        $this->asOwner($revoked);
        $this->assertContains($this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl'])->status(), [302, 403, 404]);

        $ssoOnly = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $this->service->client_id, 'user_id' => $ssoOnly->id, 'permissions' => ['productsso'], 'granted_at' => now()]);
        Auth::forgetGuards();
        $this->asOwner($ssoOnly);
        $this->assertContains($this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl'])->status(), [403, 404]);

        $this->assertSame(0, $this->sessions());
    }

    /** Control: a terminated service, and the Livewire `goto` of an sso button, never open a panel session. */
    public function test_control_terminated_service_and_livewire_goto_are_refused(): void
    {
        $this->asOwner();
        Livewire::test(Show::class, ['service' => $this->service])->call('goto', 'getLoginUrl')->assertNoRedirect();

        $this->service->update(['status' => Service::STATUS_TERMINATED]);
        $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl'])->assertRedirect(route('services.show', $this->service));
        $this->assertSame(0, $this->sessions());
    }

    /** Control: «Leave» is POST only and goes back to the admin, never to an address from the request. */
    public function test_control_leave_is_post_only_and_redirects_inside_the_admin(): void
    {
        $this->impersonating(['*']);

        $this->get('/impersonation/leave')->assertStatus(405);
        $location = $this->post(route('impersonation.leave') . '?redirect=https://evil.test', ['redirect' => 'https://evil.test'])->headers->get('Location');
        $this->assertStringStartsWith(url('/sysadmin'), (string) $location);
        $this->assertFalse(Session::has('impersonating'));
    }

    /** Control: in the admin while a client tab impersonates, the actor is the staff member and no customer is logged in. */
    public function test_control_admin_while_impersonating_acts_as_staff(): void
    {
        $staff = $this->impersonating(['*']);

        $this->get('/sysadmin')->assertOk();
        $this->assertNull(Auth::guard('web')->user());
        $this->assertSame($staff->id, Actor::current()?->id);
        $this->assertInstanceOf(Staff::class, Actor::current());
    }

    /** Control: a page a real customer opened, then staff log in as a client in the same browser: its updates are refused. */
    public function test_control_page_opened_by_the_real_customer_is_refused_once_staff_impersonate(): void
    {
        $this->staff(['admin.users.impersonate']);
        Auth::guard('web')->setUser($this->owner);
        Auth::shouldUse('web');
        $page = Livewire::test(Show::class, ['service' => $this->service]);
        $page->call('$refresh')->assertStatus(200);

        Session::put(['impersonating' => $this->owner->id, CurrentClient::IMPERSONATING_KEY => $this->service->client_id]);
        $page->call('$refresh')->assertStatus(419);
    }

    /** Control: a page opened while impersonating, after «Leave» in another tab, is refused. */
    public function test_control_page_opened_impersonating_is_refused_after_leave(): void
    {
        $this->staff(['admin.users.impersonate']);
        Session::put(['impersonating' => $this->owner->id, CurrentClient::IMPERSONATING_KEY => $this->service->client_id]);
        Auth::guard('web')->setUser($this->owner);
        Auth::shouldUse('web');
        $page = Livewire::test(Show::class, ['service' => $this->service]);

        Session::forget(['impersonating', CurrentClient::IMPERSONATING_KEY]);
        $page->call('$refresh')->assertStatus(419);
    }

    /** Control: the admin button is a plain new-tab link, not an SPA (wire:navigate) fetch of the signed route. */
    public function test_control_admin_button_is_not_fetched_by_spa_navigation(): void
    {
        $this->staff();

        $html = $this->get(ServiceResource::getUrl('edit', ['record' => $this->service]))->assertOk()->getContent();
        preg_match('#<a[^>]*panel-login[^>]*>#', (string) $html, $match);
        $this->assertNotEmpty($match);
        $this->assertStringNotContainsString('wire:navigate', $match[0]);
        $this->assertSame(0, $this->sessions());
    }

    /** Control: with staff 2FA required and not set up, the signed links start nothing (EnsureStaffTwoFactor). */
    public function test_control_staff_without_required_2fa_are_stopped(): void
    {
        config(['settings.staff_2fa_required' => true]);
        $staff = $this->staff();
        $staff->forceFill(['app_authentication_secret' => null])->save();
        $this->assertFalse($staff->fresh()->hasTwoFactor());

        $this->get(StaffLink::to('filament.admin.login-as.client', ['client' => $this->owner->client()->id]))->assertRedirect();
        $this->get($this->panelLink())->assertRedirect();
        $this->assertFalse(Session::has('impersonating'));
        $this->assertSame(0, $this->sessions());
    }
}
