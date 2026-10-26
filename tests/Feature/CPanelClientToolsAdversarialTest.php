<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Livewire\Services\Show;
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
use Tests\TestCase;

/**
 * Adversarial review of #318 (cPanel client tools): what a customer, a sub-user or a lost answer from WHM can make of
 * the shortcuts, the webmail button, the new password and the new mailbox.
 */
class CPanelClientToolsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'N3w-Strong-Pass';

    private User $owner;

    private Service $service;

    /** @var callable|null an answer for passwd other than OK */
    private $passwd = null;

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
                str_ends_with($call, '/passwd') => $this->passwd !== null ? ($this->passwd)($request) : Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']]),
                str_ends_with($call, '/cpanel') => Http::response(['result' => ['status' => 1, 'errors' => null, 'data' => '']]),
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

    /** @return list<string> the messages the page told */
    private function told($page): array
    {
        return collect($page->effects['dispatches'] ?? [])->where('name', 'notify')
            ->map(fn ($d) => (string) ($d['params'][0]['message'] ?? ''))->values()->all();
    }

    private function changePassword($page)
    {
        return $page->set('newPassword', self::PASSWORD)->set('newPasswordConfirmation', self::PASSWORD)->call('changeServicePassword');
    }

    /**
     * ADV-1: the page was opened while the service was active; staff then suspended it (non-payment). The shortcuts are
     * locked in from mount and openShortcut never looks at the status again: a suspended customer still gets a signed-in
     * cPanel session (changeServicePassword and createMailbox do re-check it).
     */
    public function test_adv1_a_shortcut_on_a_service_suspended_since_the_page_opened_is_never_asked(): void
    {
        $page = $this->page();
        $this->service->update(['status' => Service::STATUS_SUSPENDED]);

        $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl', 'app' => 'Email_Accounts'])->assertRedirect(route('services.show', $this->service));

        $this->assertSame([], $this->sent('create_user_session'));
    }

    /** ADV-2: the same for the new webmail button through goto() (the buttons are also kept from mount). */
    public function test_adv2_webmail_on_a_service_suspended_since_the_page_opened_is_never_asked(): void
    {
        $page = $this->page();
        $this->service->update(['status' => Service::STATUS_SUSPENDED]);

        $page->call('goto', 'getWebmailUrl')->assertNoRedirect();
        $this->post(route('services.panel-login', $this->service), ['function' => 'getWebmailUrl'])->assertRedirect(route('services.show', $this->service));

        $this->assertSame([], $this->sent('create_user_session'));
    }

    /**
     * ADV-3: WHM took the new password but its answer was lost (a timeout after the request was sent). The customer is
     * told «The password was not changed», the old one stays on the service: both may be false. An outcome not known
     * must not be told as a refusal.
     */
    public function test_adv3_a_lost_answer_is_not_told_as_a_refusal(): void
    {
        Exceptions::fake();
        $this->passwd = fn () => Http::failedConnection('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received for https://whm.test:2087/json-api/passwd');

        $page = $this->changePassword($this->page());

        $this->assertCount(1, $this->sent('passwd'), 'the request reached WHM');
        foreach ($this->told($page) as $message) {
            $this->assertStringNotContainsString('was not changed', $message, 'the outcome is not known: WHM may have changed it');
        }
    }

    /**
     * ADV-4: an error that is not WHM's refusal (transport, HTTP 5xx) reaches the customer as it is: WHM's address and
     * port, its API path, cURL's text or an error page's body. Only WHM's own reason (metadata.reason) is for them.
     */
    public function test_adv4_a_transport_error_does_not_show_the_customer_whms_address_or_body(): void
    {
        Exceptions::fake();
        $this->passwd = fn () => Http::failedConnection('cURL error 7: Failed to connect to whm.test port 2087 for https://whm.test:2087/json-api/passwd');

        $told = implode(' | ', $this->told($this->changePassword($this->page())));
        $this->assertStringNotContainsString('whm.test', $told);
        $this->assertStringNotContainsString('json-api', $told);

        RateLimiter::clear('service-password:' . $this->service->id);
        $this->passwd = fn () => Http::response('<html>Internal Server Error: /usr/local/cpanel/Cpanel/Server.pm line 512</html>', 500);

        $told = implode(' | ', $this->told($this->changePassword($this->page())));
        $this->assertStringNotContainsString('/usr/local/cpanel', $told);
        $this->assertStringNotContainsString('status code 500', $told);
    }

    /**
     * ADV-5: Redact::values masks the password as it is and HTML-escaped, but an HTTP error's message carries the body
     * as WHM sent it: a JSON body escapes «/» as «\/», so a password with a slash reaches the log (and the customer's
     * notice) un-masked.
     */
    public function test_adv5_a_password_echoed_json_escaped_in_an_http_error_is_masked(): void
    {
        Exceptions::fake();
        $password = 'Abc/Def/12345';
        $escaped = 'Abc\/Def\/12345';
        $this->passwd = fn () => Http::response(['error' => "password {$password} rejected"], 500);

        $page = $this->page()->set('newPassword', $password)->set('newPasswordConfirmation', $password)->call('changeServicePassword');

        $this->assertCount(1, $this->sent('passwd'));
        Exceptions::assertNotReported(fn (Exception $e) => str_contains($e->getMessage(), $escaped) || str_contains($e->getMessage(), $password));
        $this->assertStringNotContainsString('Abc\\\\/Def', json_encode($page->effects), 'nor in the notice');
    }

    /**
     * ADV-6: past the limit the method returns before the reset: the new password (validated) stays in the component's
     * public state and goes back in the snapshot, as in no other path of the method.
     */
    public function test_adv6_a_password_refused_by_the_limit_is_not_kept_in_the_snapshot(): void
    {
        RateLimiter::increment('service-password:' . $this->service->id, 900, 5);

        $page = $this->changePassword($this->page());

        $this->assertSame([], $this->sent('passwd'));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($page->snapshot));
    }

    /**
     * ADV-7: staff's «Change password on server» on a service suspended (or pending) is hidden; called anyway it must not
     * reach WHM.
     */
    public function test_adv7_staff_cannot_change_it_on_a_suspended_service(): void
    {
        $this->service->update(['status' => Service::STATUS_SUSPENDED]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        rescue(fn () => $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->callAction('serverPassword', ['password' => self::PASSWORD]), null, false);

        $this->assertSame([], $this->sent('passwd'));
        $this->assertSame('Old-Pass-123', $this->service->fresh()->password);
    }

    /** ADV-8: a crafted local part never names another domain, whatever the case, encoding or line break. */
    public function test_adv8_crafted_local_parts_never_reach_another_domain(): void
    {
        foreach (["info\n@evil.example", 'info%evil.example', "info\r\nBcc", 'ínfo', 'info@zonecloud.io', "info\0x"] as $name) {
            rescue(fn () => $this->page()->set('mailboxName', $name)->set('mailboxPassword', self::PASSWORD)->call('createMailbox'), null, false);
        }
        foreach ($this->sent('cpanel') as $call) {
            $this->assertSame('zonecloud.io', $call['domain']);
            $this->assertMatchesRegularExpression('/^[a-z0-9._+-]+$/', $call['email']);
        }
    }
}
