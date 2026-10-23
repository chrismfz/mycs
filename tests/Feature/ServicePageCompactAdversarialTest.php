<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceHostedDomain;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/** Adversarial review of #400: the compact service page, its module buttons and staff SSO into the panel. */
class ServicePageCompactAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private Server $server;

    /** @var list<array<string, mixed>> */
    private array $sessions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['settings.default_currency' => 'USD']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
        $this->server = Server::query()->create(['name' => 'Orion', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $this->server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->service = $this->makeService('zonecl');
        Http::fake(function (Request $request) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/create_user_session')) {
                $this->sessions[] = $request->data();

                return Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['url' => 'https://orion.test:2083/cpsess1/login/?session=x']]);
            }

            return Http::response([], 404);
        });
    }

    private function makeService(string $username): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $this->server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $this->server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10,
            'expires_at' => now()->addMonth(), 'domain' => $username . '.test', 'username' => $username]);
        $service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => $username]);

        return $service;
    }

    private function staff(array $permissions = ['*']): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    /**
     * ADV-1: `loginToPanel` is a public Livewire method taking a Service, so `$wire.loginToPanel(<any id>)` on one
     * service's page signs into ANOTHER service's panel (Livewire binds the id), outside the action and the page's record.
     */
    public function test_a_direct_livewire_call_cannot_sign_into_another_services_panel(): void
    {
        $this->staff();
        $other = $this->makeService('victim');

        Livewire::test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->call('loginToPanel', $other->getKey());

        $this->assertSame([], array_column($this->sessions, 'user'), 'a session was made for another service than the page\'s');
        $this->assertSame(0, Audit::query()->where('event', 'panel_login')->where('auditable_id', $other->id)->count());
    }

    /** Control: a direct call without the permission is refused (passes). */
    public function test_a_direct_call_without_the_sso_permission_is_refused(): void
    {
        $this->staff(['admin.services.viewAny', 'admin.services.update']);

        Livewire::test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->call('loginToPanel', $this->service->getKey())->assertForbidden();
        $this->assertSame([], $this->sessions);
    }

    /** Control: a command not in the options, mounted by hand, is refused by the Select's own `in` rule (passes). */
    public function test_a_command_not_in_the_options_is_refused(): void
    {
        $this->staff();

        Livewire::test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->mountAction('changeStatus', ['action' => 'changePassword'])
            ->callMountedAction()->assertHasFormErrors(['action']);
    }

    /** Control: the Create page still renders (the module row and the summary line are edit only) (passes). */
    public function test_the_create_page_renders_without_the_module_row(): void
    {
        $this->staff();

        $this->get(ServiceResource::getUrl('create'))->assertOk()->assertDontSee('changeStatus');
    }

    /** Control: a hosted domain the server reported is escaped on the summary line (passes). */
    public function test_a_hosted_domain_from_the_server_is_escaped(): void
    {
        $this->staff();
        ServiceHostedDomain::query()->create(['service_id' => $this->service->id, 'domain' => '<img src=x onerror=alert(1)>', 'type' => 'addon', 'account' => 'zonecl', 'read_at' => now()]);

        $this->get(ServiceResource::getUrl('edit', ['record' => $this->service]))->assertOk()
            ->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    /**
     * ADV-2: a reseller's hosted domains were shown by account («acct: a.gr, b.gr»); the summary line flattens them,
     * so staff no longer see which of the reseller's accounts hosts a domain.
     */
    public function test_a_resellers_hosted_domains_still_say_which_account_hosts_them(): void
    {
        $this->staff();
        foreach ([['zonecl.test', 'main', 'zonecl'], ['shop.test', 'main', 'subacct1'], ['blog.test', 'main', 'subacct2']] as [$domain, $type, $account]) {
            ServiceHostedDomain::query()->create(['service_id' => $this->service->id, 'domain' => $domain, 'type' => $type, 'account' => $account, 'read_at' => now()]);
        }

        $this->get(ServiceResource::getUrl('edit', ['record' => $this->service]))->assertOk()
            ->assertSee('subacct1')->assertSee('subacct2');
    }
}
