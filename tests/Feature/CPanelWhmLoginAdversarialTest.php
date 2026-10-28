<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\ClientUser;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Servers\CPanel\CPanel;
use Tests\TestCase;

/**
 * Adversarial review of #496 («Access WHM»: create_user_session «whostmgrd» with the server's token).
 *
 * ADV-496-1 (low): the WHM sign-in is offered and asked of WHM on the product's «reseller» flag alone, which the module
 * itself says never to trust alone (resellerInWhm: «a half-made reseller, nixpal's login: A5, A6 of #315»). A service
 * of a reseller product made on a server logged in as a reseller (nixpal's: the account is made, no reseller) or one
 * whose setupreseller never happened is a plain account: WHM is asked for a WHM session for it anyway, the button is a
 * dead end. It should be offered/asked only when WHM has the account as a reseller (the property, or root's
 * listresellers), as suspend/unsuspend do.
 *
 * The rest are guards for what was tried and held (another customer, a sub-user without productsso, a suspended or
 * pending service, root or a protected account as its username, arrays in the POST, the Livewire goto path).
 */
class CPanelWhmLoginAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private Product $product;

    private Service $service;

    /** @var list<string> */
    private array $resellers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        $this->server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $this->server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        $this->product = $product->product;
        $this->product->update(['server_id' => $this->server->id]);
        foreach (['package' => 'Business10', 'reseller' => '1', 'reseller_acl' => 'resellers'] as $key => $value) {
            $this->product->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $this->product->id, 'server_id' => $this->server->id, 'status' => 'active', 'expires_at' => now()->addMonth(),
            'currency_code' => 'USD', 'price' => 10.00, 'domain' => 'reseller.test']);
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'resel1']);
        Http::fake(function (Request $request) {
            $call = (string) parse_url($request->url(), PHP_URL_PATH);
            $ok = fn (array $data = []) => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => $data]);

            return match (true) {
                str_ends_with($call, '/listresellers') => $ok(['reseller' => $this->resellers]),
                str_ends_with($call, '/create_user_session') => $ok(['url' => 'https://whm.test:2087/cpsess1/login']),
                default => $ok(),
            };
        });
    }

    private function whmSessions(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/create_user_session') && (string) $r['service'] === 'whostmgrd')
            ->values()->all();
    }

    private function asOwner(?User $user = null): void
    {
        $user ??= $this->service->client->users()->first();
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $this->service->client_id]));
    }

    /** ADV-496-1, nixpal's case: a server logged in as a reseller makes the account but never a reseller. */
    public function test_adv_496_1_a_plain_account_of_a_reseller_product_on_a_resellers_login_gets_no_whm_session(): void
    {
        $this->server->settings()->where('key', 'username')->update(['value' => 'nixpalres']);
        $this->asOwner();

        $this->post(route('services.panel-login', $this->service), ['function' => 'getWhmUrl']);

        $this->assertCount(0, $this->whmSessions(), 'WHM asked for a WHM session for an account that is not a reseller');
        $this->assertNotContains('getWhmUrl', array_column(ExtensionHelper::getActions($this->service->fresh()), 'function'));
    }

    /** ADV-496-1, root's case: the product says reseller, WHM's resellers do not list it (setupreseller never ran). */
    public function test_adv_496_1_an_account_whm_does_not_list_as_a_reseller_gets_no_whm_session(): void
    {
        $this->resellers = ['someoneelse'];
        $this->asOwner();

        $this->post(route('services.panel-login', $this->service), ['function' => 'getWhmUrl']);

        $this->assertCount(0, $this->whmSessions(), 'a WHM session asked on the product flag alone');
    }

    // Guards (hold now).

    public function test_guard_root_or_a_protected_account_as_its_username_is_never_asked_for(): void
    {
        $this->service->properties()->create(['key' => CPanel::RESELLER, 'name' => 'WHM reseller', 'value' => '1']);
        $this->server->settings()->create(['key' => 'protected_accounts', 'value' => 'mycsapp']);
        $this->asOwner();
        foreach (['root', ' ROOT ', 'mycsapp'] as $user) {
            $this->service->properties()->where('key', 'cpanel_username')->update(['value' => $user]);
            $this->post(route('services.panel-login', $this->service), ['function' => 'getWhmUrl'])->assertRedirect(route('services.show', $this->service));
        }

        $this->assertCount(0, $this->whmSessions());
    }

    public function test_guard_another_customer_a_sub_user_a_not_active_service_or_arrays_are_refused(): void
    {
        $this->service->properties()->create(['key' => CPanel::RESELLER, 'name' => 'WHM reseller', 'value' => '1']);
        $this->resellers = ['resel1'];

        $this->asOwner(User::factory()->create());
        $this->assertContains($this->post(route('services.panel-login', $this->service), ['function' => 'getWhmUrl'])->status(), [403, 404]);

        $viewer = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $this->service->client_id, 'user_id' => $viewer->id, 'permissions' => ['products'], 'granted_at' => now()]);
        $this->asOwner($viewer);
        $this->post(route('services.panel-login', $this->service), ['function' => 'getWhmUrl']);

        $this->asOwner();
        foreach ([['function' => ['getWhmUrl']], ['function' => 'getWhmUrl', 'app' => ['x']], ['function' => 'getWhmUrl', 'app' => 'Email_Accounts']] as $input) {
            $this->post(route('services.panel-login', $this->service), $input)->assertRedirect(route('services.show', $this->service));
        }
        foreach (['suspended', 'pending', 'terminated', 'cancelled'] as $status) {
            $this->service->forceFill(['status' => $status])->save();
            $this->post(route('services.panel-login', $this->service), ['function' => 'getWhmUrl'])->assertRedirect(route('services.show', $this->service));
        }

        $this->assertCount(0, $this->whmSessions());
    }
}
