<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Livewire\Services\Show;
use App\Models\ClientUser;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Exception;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use MyCS\Extensions\Servers\CPanel\CPanel;
use Tests\TestCase;

/**
 * The basics of WHMCS's cPanel product page (owner 2026-10-22): webmail signed in, quick shortcuts into cPanel, a new
 * password at the server, a mailbox on the service's domain; each with its permission, its limit, and never the
 * password in a URL, a log or a message.
 */
class CPanelClientToolsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'N3w-Strong-Pass';

    private User $owner;

    private Service $service;

    /** WHM's answer to passwd / add_pop: null = OK */
    private ?string $refuse = null;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        config(['settings.default_currency' => 'USD']);
        $server = Server::query()->create(['name' => 'Orion', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->owner = User::factory()->create();
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $product->product->settings()->create(['key' => 'package', 'value' => 'Business20']);
        $this->service = Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addMonth(),
            'domain' => 'zonecloud.io', 'username' => 'zonecl', 'password' => 'Old-Pass-123']);
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'zonecl']);
        Http::fake(function (Request $request) {
            $call = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($call, '/create_user_session') => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'],
                    'data' => ['url' => 'https://orion.test:2083/cpsess1/login/?session=x' . ($request['app'] ?? '') . ($request['service'] === 'webmaild' ? 'webmail' : '')]]),
                str_ends_with($call, '/passwd') => Http::response(['metadata' => $this->refuse === null ? ['result' => 1, 'reason' => 'OK']
                    : ['result' => 0, 'reason' => $this->refuse]]),
                str_ends_with($call, '/cpanel') => Http::response(['result' => $this->refuse === null ? ['status' => 1, 'errors' => null, 'data' => '']
                    : ['status' => 0, 'errors' => [$this->refuse]]]),
                default => Http::response([], 404),
            };
        });
        RateLimiter::clear('service-password:' . $this->service->id);
        RateLimiter::clear('service-mailbox:' . $this->service->id);
    }

    private function page(?User $user = null)
    {
        $user ??= $this->owner;
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $this->service->client_id]));

        return Livewire::test(Show::class, ['service' => $this->service]);
    }

    private function sent(string $call): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/' . $call))->values()->all();
    }

    public function test_webmail_and_a_shortcut_open_signed_in(): void
    {
        // Forms of the page into a new tab (owner 2026-10-26): ServicePanelLoginController.
        $this->page();
        $this->post(route('services.panel-login', $this->service), ['function' => 'getWebmailUrl'])->assertRedirectContains('webmail');
        $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl', 'app' => 'Email_Accounts'])->assertRedirectContains('Email_Accounts');

        [$webmail, $shortcut] = $this->sent('create_user_session');
        $this->assertSame(['webmaild', 'cpaneld', 'Email_Accounts'], [$webmail['service'], $shortcut['service'], $shortcut['app']]);
    }

    public function test_a_shortcut_not_offered_is_never_asked(): void
    {
        $this->page();
        $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl', 'app' => 'Evil_App'])->assertRedirect(route('services.show', $this->service));

        $this->assertSame([], $this->sent('create_user_session'));
    }

    public function test_a_login_without_productsso_gets_no_shortcuts(): void
    {
        $viewer = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $this->service->client_id, 'user_id' => $viewer->id, 'permissions' => ['products'], 'granted_at' => now()]);

        $page = $this->page($viewer);
        $this->assertSame([], $page->get('shortcuts'));
        $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl', 'app' => 'Email_Accounts'])->assertRedirect(route('services.show', $this->service));
        $this->assertSame([], $this->sent('create_user_session'));
    }

    public function test_a_new_password_goes_to_whm_as_a_form_and_is_kept_encrypted(): void
    {
        $this->page()->set('newPassword', self::PASSWORD)->set('newPasswordConfirmation', self::PASSWORD)->call('changeServicePassword')
            ->assertHasNoErrors()->assertDispatched('notify', fn ($name, $params) => ($params[0]['type'] ?? $params['type'] ?? null) === 'success');

        [$passwd] = $this->sent('passwd');
        $this->assertSame(['zonecl', self::PASSWORD], [$passwd['user'], $passwd['password']]);
        $this->assertStringNotContainsString(self::PASSWORD, $passwd->url(), 'never in a URL');
        $this->assertStringContainsString('application/x-www-form-urlencoded', $passwd->header('Content-Type')[0] ?? '');
        $this->assertSame(self::PASSWORD, $this->service->fresh()->password);
    }

    public function test_a_refusal_keeps_the_old_password_and_never_repeats_the_new_one(): void
    {
        Exceptions::fake();
        $this->refuse = 'Sorry, the password «' . self::PASSWORD . '» is too weak (strength 40, 65 required)';

        $page = $this->page()->set('newPassword', self::PASSWORD)->set('newPasswordConfirmation', self::PASSWORD)->call('changeServicePassword')
            ->assertDispatched('notify', fn ($name, $params) => ($params[0]['type'] ?? $params['type'] ?? null) === 'error');

        $this->assertSame('Old-Pass-123', $this->service->fresh()->password);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($page->effects));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($page->snapshot));
        Exceptions::assertReported(fn (Exception $e) => !str_contains($e->getMessage(), self::PASSWORD) && str_contains($e->getMessage(), 'too weak'));
        Exceptions::assertNotReported(fn (Exception $e) => str_contains($e->getMessage(), self::PASSWORD));
    }

    public function test_a_weak_password_is_refused_before_whm(): void
    {
        $this->page()->set('newPassword', 'short')->set('newPasswordConfirmation', 'short')->call('changeServicePassword')->assertHasErrors('newPassword');

        $this->assertSame([], $this->sent('passwd'));
    }

    public function test_only_who_manages_the_service_changes_its_password(): void
    {
        $viewer = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $this->service->client_id, 'user_id' => $viewer->id, 'permissions' => ['products', 'productsso'], 'granted_at' => now()]);

        $this->page($viewer)->set('newPassword', self::PASSWORD)->set('newPasswordConfirmation', self::PASSWORD)->call('changeServicePassword')->assertNotFound();

        $this->assertSame([], $this->sent('passwd'));
    }

    public function test_password_changes_are_limited(): void
    {
        $page = $this->page();
        foreach (range(1, 6) as $ignored) {
            $page->set('newPassword', self::PASSWORD)->set('newPasswordConfirmation', self::PASSWORD)->call('changeServicePassword');
        }

        $this->assertCount(5, $this->sent('passwd'));
    }

    public function test_a_mailbox_is_made_on_the_services_domain_only(): void
    {
        $this->page()->set('mailboxName', 'info')->set('mailboxPassword', self::PASSWORD)->call('createMailbox')->assertHasNoErrors();

        [$call] = $this->sent('cpanel');
        $this->assertSame(['zonecl', 'Email', 'add_pop', 'info', 'zonecloud.io'],
            [$call['cpanel_jsonapi_user'], $call['cpanel_jsonapi_module'], $call['cpanel_jsonapi_func'], $call['email'], $call['domain']]);
        $this->assertStringNotContainsString(self::PASSWORD, $call->url());
    }

    public function test_a_name_naming_another_domain_is_refused_before_whm(): void
    {
        foreach (['info@evil.example', 'a..b', '.info', 'in fo', ''] as $name) {
            $this->page()->set('mailboxName', $name)->set('mailboxPassword', self::PASSWORD)->call('createMailbox')->assertHasErrors('mailboxName');
        }

        $this->assertSame([], $this->sent('cpanel'));
    }

    public function test_a_mailbox_refused_never_repeats_its_password(): void
    {
        Exceptions::fake();
        $this->refuse = 'The password «' . self::PASSWORD . '» does not meet the strength requirement';

        $page = $this->page()->set('mailboxName', 'info')->set('mailboxPassword', self::PASSWORD)->call('createMailbox');

        $this->assertStringNotContainsString(self::PASSWORD, json_encode($page->effects));
        Exceptions::assertNotReported(fn (Exception $e) => str_contains($e->getMessage(), self::PASSWORD));
    }

    public function test_staff_change_it_on_the_server_from_the_service(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->callAction('serverPassword', ['password' => self::PASSWORD])->assertHasNoActionErrors();

        $this->assertCount(1, $this->sent('passwd'));
        $this->assertSame(self::PASSWORD, $this->service->fresh()->password);
    }

    /** As Jupiter's dynamicui.conf «implements» them (read on orion, 2026-10-22): a wrong name opens cPanel's home. */
    public function test_the_shortcuts_are_cpanels_own_page_names(): void
    {
        $this->assertSame(['Email_Accounts', 'Email_Forwarders', 'Email_AutoResponders', 'FileManager_Home', 'Backups_Home', 'Domains_domains',
            'Cron_Home', 'Database_MySQL', 'Database_phpMyAdmin', 'Stats_AWStats'], array_keys(CPanel::SHORTCUTS));
        foreach (array_keys(CPanel::SHORTCUTS) as $app) {
            $this->assertNotSame('services.cpanel.shortcuts.' . $app, __('services.cpanel.shortcuts.' . $app), "{$app} has a label");
        }
    }

    public function test_the_mysql_password_changes_too_only_when_asked(): void
    {
        $this->page()->set('newPassword', self::PASSWORD)->set('newPasswordConfirmation', self::PASSWORD)->call('changeServicePassword');
        $this->page()->set('newPassword', self::PASSWORD)->set('newPasswordConfirmation', self::PASSWORD)->set('changeMysqlToo', true)->call('changeServicePassword');

        [$plain, $both] = $this->sent('passwd');
        $this->assertArrayNotHasKey('db_pass_update', $plain->data());
        $this->assertSame('1', (string) $both['db_pass_update']);
    }

    public function test_staff_may_change_the_mysql_password_too(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->callAction('serverPassword', ['password' => self::PASSWORD, 'mysqlToo' => true])->assertHasNoActionErrors();

        [$passwd] = $this->sent('passwd');
        $this->assertSame('1', (string) $passwd['db_pass_update']);
    }
}
