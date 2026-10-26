<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource;
use App\Livewire\Auth\Logout;
use App\Livewire\Services\Show;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
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
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * Adversarial review of #465, round 2. test_adv2_N_* fail until fixed; test_control_* are attacks tried that are
 * blocked, kept as regression controls.
 */
class Adv465Round2Test extends TestCase
{
    use RefreshDatabase;

    private const PANEL = 'https://orion.test:2083/cpsess1/login/?session=secret-token';

    private User $owner;

    private Service $service;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        config(['settings.default_currency' => 'USD']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->server = Server::query()->create(['name' => 'Orion', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $this->server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->owner = User::factory()->create();
        $this->service = $this->makeService($this->owner);
        Http::fake(fn (Request $request) => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/create_user_session')
            ? Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['url' => self::PANEL . ($request['app'] ?? '')]])
            : Http::response([], 404));
    }

    private function makeService(User $user): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $this->server->id]);
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $this->server->id, 'status' => 'active', 'currency_code' => 'USD',
            'price' => 10, 'expires_at' => now()->addMonth(), 'domain' => 'zonecloud.io', 'username' => 'zonecl']);
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

    private function asOwner(): void
    {
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->service->client_id]));
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
     * ADV2-1: «Log in to panel» has two doors with different locks. Since ADV-1 the admin's route needs
     * `admin.services.sso` AND edit rights on the service (`admin.services.update`); the client area's, for staff in
     * «Login as client», needs only `admin.services.sso` (productsso → admin.services.sso). Before #465 that door needed
     * `admin.services.update` (productsso → admin.services.update), so a role with the SSO permission but without
     * update rights, refused by the admin (shown here), now gets the panel session one tab over: a loosening, not the
     * «never less» the PR claims. Fix: `productsso` for staff needs both (e.g. staffMayAs checks a list, or loginMay
     * adds Actor::staffMayAs('manageproducts') for productsso), and the page's buttons follow.
     */
    public function test_adv2_1_impersonating_staff_need_the_admins_rights_for_a_panel_login_not_less(): void
    {
        $permissions = ['admin.users.impersonate', 'admin.clients.update', 'admin.services.viewAny', 'admin.services.sso'];

        // The admin refuses this member (ADV-1's fix).
        $this->staff($permissions);
        $this->get(StaffLink::to('filament.admin.sso.service', ['service' => $this->service->id]))->assertForbidden();
        $this->assertSame(0, $this->sessions());

        // The same member, in «Login as client», one tab over.
        Auth::forgetGuards();
        $this->impersonating($permissions);
        $response = $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl']);

        $this->assertNotSame(self::PANEL, $response->headers->get('Location'), 'refused in the admin, signed into the panel through Login as client');
        $this->assertSame(0, $this->sessions());
        $this->assertSame(0, Audit::query()->where('event', 'panel_login')->count());
    }

    // Controls: attacks tried that are blocked.

    /** Control: a link signed for one route never verifies on another (path in the HMAC): client → user, service → other id. */
    public function test_control_a_signature_is_not_replayed_on_another_route_or_record(): void
    {
        $this->staff();
        $client = $this->owner->client();
        $link = StaffLink::to('filament.admin.login-as.client', ['client' => $client->id]);
        $swapped = str_replace('/clients/' . $client->id . '/login-as', '/users/' . $this->owner->id . '/login-as', $link);
        $this->assertNotSame($link, $swapped);
        $this->get($swapped)->assertForbidden();

        $other = $this->makeService(User::factory()->create());
        $panel = StaffLink::to('filament.admin.sso.service', ['service' => $this->service->id]);
        $this->get(str_replace('/services/' . $this->service->id . '/', '/services/' . $other->id . '/', $panel))->assertForbidden();

        $this->assertFalse(Session::has('impersonating'));
        $this->assertSame(0, $this->sessions());
    }

    /** Control: `expires` pushed forward, a second `signature`, a percent-encoded path or reordered query are refused. */
    public function test_control_tampered_links_are_refused(): void
    {
        $this->staff();
        $link = StaffLink::to('filament.admin.sso.service', ['service' => $this->service->id]);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $path = (string) parse_url($link, PHP_URL_PATH);

        $this->get(preg_replace('/expires=\d+/', 'expires=' . ($query['expires'] + 86400), $link))->assertForbidden();
        $this->get($link . '&signature=x')->assertForbidden();
        $this->get(url(str_replace('/services/' . $this->service->id . '/', '/services/' . implode(array_map(fn (string $digit) => '%' . bin2hex($digit), str_split((string) $this->service->id))) . '/', $path)) . '?' . parse_url($link, PHP_URL_QUERY))->assertForbidden();
        $reordered = url($path) . '?expires=' . $query['expires'] . '&by=' . $query['by'] . '&signature=' . $query['signature'];
        $this->get($reordered)->assertForbidden();

        $this->assertSame(0, $this->sessions());
    }

    /** Control: odd input to the client area's sign-in (empty, JSON numbers, an app for a function not getLoginUrl, an unknown app). */
    public function test_control_odd_input_is_refused_without_a_server_error(): void
    {
        $this->asOwner();
        $url = route('services.panel-login', $this->service);

        foreach ([[], ['function' => ''], ['function' => 'getWebmailUrl', 'app' => 'Email_Accounts'], ['function' => 'getLoginUrl', 'app' => 'NoSuchApp'],
            ['function' => 'changePassword'], ['function' => 'createEmailAccount']] as $input) {
            $this->post($url, $input)->assertRedirect(route('services.show', $this->service));
        }
        $this->postJson($url, ['function' => 1])->assertRedirect(route('services.show', $this->service));
        $this->postJson($url, ['function' => 'getLoginUrl', 'app' => 0])->assertRedirect(route('services.show', $this->service));
        $this->assertSame(0, $this->sessions());

        // `app=` empty is the plain button (ConvertEmptyStringsToNull), as the form never sends it.
        $this->post($url, ['function' => 'getLoginUrl', 'app' => ''])->assertRedirect(self::PANEL);
    }

    /** Control: the client area's redirect to the panel is not cacheable and sends no referrer, as the admin's. */
    public function test_control_client_area_redirect_headers(): void
    {
        $this->asOwner();

        $response = $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl', 'app' => 'Email_Accounts']);

        $response->assertRedirect(self::PANEL . 'Email_Accounts');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    /**
     * Control: the admin's route checks the staff member's rights (Filament's guard), never the customer staff are logged
     * in as in another tab: with impersonation on, a member without update rights is still refused.
     */
    public function test_control_admin_route_uses_the_staff_guard_while_impersonating(): void
    {
        $this->impersonating(['admin.users.impersonate', 'admin.services.sso']);

        $this->get(StaffLink::to('filament.admin.sso.service', ['service' => $this->service->id]))->assertForbidden();
        $this->assertFalse(ServiceResource::canEdit($this->service));
        $this->assertSame(0, $this->sessions());
    }

    /** Control: the client area's «Log out» while impersonating ends it (both keys), the staff member stays logged in. */
    public function test_control_logout_while_impersonating_ends_it_only(): void
    {
        $staff = $this->staff(['admin.users.impersonate']);
        Session::put(['impersonating' => $this->owner->id, CurrentClient::IMPERSONATING_KEY => $this->service->client_id]);
        Auth::guard('web')->setUser($this->owner);
        Auth::shouldUse('web');

        Livewire::test(Logout::class)->call('logout')->assertRedirect('/sysadmin');

        $this->assertFalse(Session::has('impersonating'));
        $this->assertFalse(Session::has(CurrentClient::IMPERSONATING_KEY));
        $this->assertSame($staff->id, Auth::guard('staff')->id());
    }

    /** Control: a page opened as one account of a user, then staff log in as the same user on another account: refused. */
    public function test_control_same_user_other_account_is_refused(): void
    {
        $this->staff(['admin.users.impersonate']);
        Session::put(['impersonating' => $this->owner->id, CurrentClient::IMPERSONATING_KEY => $this->service->client_id]);
        Auth::guard('web')->setUser($this->owner);
        Auth::shouldUse('web');
        $page = Livewire::test(Show::class, ['service' => $this->service]);

        // «Login as» the user (their default account), not the client: IMPERSONATING_KEY is gone.
        Session::forget(CurrentClient::IMPERSONATING_KEY);
        $page->call('$refresh')->assertStatus(419);
    }

    /** Control: a sign-in form posted from a page opened as another customer (stale tab) is refused for this one. */
    public function test_control_stale_tab_form_for_the_previous_customers_service_is_refused(): void
    {
        $other = User::factory()->create();
        $this->staff(['*']);
        $this->withSession(['impersonating' => $other->id, CurrentClient::IMPERSONATING_KEY => $other->client()->id]);

        $this->assertContains($this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl'])->status(), [403, 404]);
        $this->assertSame(0, $this->sessions());
    }
}
