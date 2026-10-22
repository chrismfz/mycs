<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Models\ModuleAction;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Servers\CPanel\CPanel;
use Tests\TestCase;

/**
 * WHM reseller accounts (owner 2026-10-22: myip's 22 resellers, «Reseller 30/70/120»): the product made a reseller with
 * its ACL and limits; suspend/unsuspend its accounts with it; its accounts deleted with it only when staff typed its
 * username, never by a cron or a cancellation.
 */
class CPanelResellerTest extends TestCase
{
    use RefreshDatabase;

    private const WHM = 'https://whm.test:2087/json-api';

    private Server $server;

    private Product $product;

    private Service $service;

    /** @var list<string> accounts the reseller owns, other than its own */
    private array $owned = [];

    /** @var list<string> WHM's resellers */
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
        foreach (['package' => 'Business10', 'reseller' => '1', 'reseller_acl' => 'resellers', 'reseller_disk_mb' => '70000',
            'reseller_bandwidth_mb' => '4000000', 'reseller_owns_itself' => '1'] as $key => $value) {
            $this->product->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $this->product->id, 'server_id' => $this->server->id, 'status' => 'active', 'expires_at' => now()->addMonth(),
            'currency_code' => 'USD', 'price' => 10.00, 'domain' => 'reseller.test']);
        Http::fake(fn (Request $request) => $this->whm($request));
    }

    private function whm(Request $request)
    {
        $call = (string) parse_url($request->url(), PHP_URL_PATH);
        $ok = fn (array $data = []) => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => $data]);

        return match (true) {
            str_ends_with($call, '/createacct') => $ok(),
            str_ends_with($call, '/listpkgs') => Http::response(['package' => [['name' => 'Business10']]]),
            str_ends_with($call, '/setupreseller') => tap($ok(), fn () => $this->resellers[] = (string) $request['user']),
            str_ends_with($call, '/listresellers') => $ok(['reseller' => $this->resellers]),
            str_ends_with($call, '/listaccts') => $ok(['acct' => array_map(fn ($u) => ['user' => $u, 'owner' => (string) $request['search']], $this->owned)]),
            str_ends_with($call, '/accountsummary') => $ok(['acct' => [['user' => 'resel1', 'domain' => 'reseller.test', 'plan' => 'Business10']]]),
            default => $ok(),
        };
    }

    private function sent(string $call): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/' . $call))->values()->all();
    }

    private function made(): void
    {
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'resel1']);
        $this->service->properties()->create(['key' => CPanel::RESELLER, 'name' => 'WHM reseller', 'value' => '1']);
        $this->resellers = ['resel1'];
    }

    public function test_a_reseller_product_is_made_a_reseller_with_its_acl_and_limits(): void
    {
        ExtensionHelper::createServer($this->service->fresh());

        $this->assertCount(1, $this->sent('createacct'));
        [$setup] = $this->sent('setupreseller');
        $this->assertSame('1', (string) $setup['makeowner']);
        [$acl] = $this->sent('setacls');
        $this->assertSame('resellers', $acl['acllist']);
        [$limits] = $this->sent('setresellerlimits');
        $this->assertSame(['1', '70000', '4000000', '0'], [(string) $limits['enable_resource_limits'], (string) $limits['diskspace_limit'],
            (string) $limits['bandwidth_limit'], (string) $limits['enable_account_limit']]);
        $this->assertSame('1', $this->service->properties()->where('key', CPanel::RESELLER)->value('value'));
    }

    public function test_a_reseller_on_a_server_not_logged_in_as_root_is_made_and_staff_told(): void
    {
        $this->server->settings()->where('key', 'username')->update(['value' => 'nixpalres']);

        ExtensionHelper::createServer($this->service->fresh());

        $this->assertSame([], $this->sent('setupreseller'));
        $this->assertNotNull($this->service->properties()->where('key', 'cpanel_username')->value('value'), 'the account is made all the same');
        $this->assertStringContainsString('root', (string) ModuleAction::query()->where('action', 'upgrade')->sole()->last_error);
    }

    public function test_suspend_and_unsuspend_take_its_accounts_with_it(): void
    {
        $this->made();

        ExtensionHelper::suspendServer($this->service->fresh());
        ExtensionHelper::unsuspendServer($this->service->fresh());

        $this->assertCount(1, $this->sent('suspendreseller'));
        $this->assertCount(1, $this->sent('unsuspendreseller'));
        $this->assertSame([], [...$this->sent('suspendacct'), ...$this->sent('unsuspendacct')]);
    }

    public function test_its_accounts_are_never_deleted_by_a_cron_or_a_cancellation(): void
    {
        $this->made();
        $this->owned = ['cust1', 'cust2'];
        $this->service->update(['status' => Service::STATUS_TERMINATED]);

        try {
            (new TerminateJob($this->service->fresh(), false))->handle();
            $this->fail('terminated a reseller with accounts on its own');
        } catch (Exception $e) {
            $this->assertStringContainsString('owns 2 other account(s)', $e->getMessage());
        }
        $this->assertSame([], [...$this->sent('terminatereseller'), ...$this->sent('removeacct')]);
        $this->assertSame('resel1', $this->service->properties()->where('key', 'cpanel_username')->value('value'));
    }

    public function test_staff_typing_its_username_deletes_it_with_its_accounts(): void
    {
        $this->made();
        $this->owned = ['cust1'];
        CPanel::confirmTermination($this->service, 'resel1');
        try {
            ExtensionHelper::terminateServer($this->service->fresh());
        } finally {
            CPanel::clearConfirmation();
        }

        [$terminate] = $this->sent('terminatereseller');
        $this->assertSame(['resel1', '1'], [$terminate['user'], (string) $terminate['terminatereseller']]);
        $this->assertSame([], $this->sent('removeacct'));
        $this->assertSame(0, $this->service->properties()->whereIn('key', [CPanel::CONFIRM, CPanel::RESELLER, 'cpanel_username'])->count());
    }

    public function test_a_wrong_username_typed_deletes_nothing(): void
    {
        $this->made();
        $this->owned = ['cust1'];
        CPanel::confirmTermination($this->service, 'resel2');

        $this->expectException(Exception::class);
        try {
            ExtensionHelper::terminateServer($this->service->fresh());
        } finally {
            CPanel::clearConfirmation();
            $this->assertSame([], [...$this->sent('terminatereseller'), ...$this->sent('removeacct')]);
        }
    }

    public function test_a_reseller_with_no_accounts_is_removed_as_any_account(): void
    {
        $this->made();

        ExtensionHelper::terminateServer($this->service->fresh());

        $this->assertCount(1, $this->sent('removeacct'));
        $this->assertSame([], $this->sent('terminatereseller'));
    }

    public function test_an_upgrade_sets_its_new_limits_and_never_makes_it_again(): void
    {
        $this->made();
        $this->product->settings()->where('key', 'reseller_disk_mb')->update(['value' => '120000']);

        ExtensionHelper::upgradeServer($this->service->fresh());

        $this->assertSame([], $this->sent('setupreseller'));
        [$limits] = $this->sent('setresellerlimits');
        $this->assertSame('120000', (string) $limits['diskspace_limit']);
    }

    public function test_a_product_no_longer_a_reseller_is_unmade_only_without_accounts(): void
    {
        $this->made();
        $this->product->settings()->where('key', 'reseller')->update(['value' => '0']);
        $this->owned = ['cust1'];

        try {
            ExtensionHelper::upgradeServer($this->service->fresh());
            $this->fail('unmade a reseller that owns accounts');
        } catch (Exception $e) {
            $this->assertStringContainsString('owns 1 account', $e->getMessage());
        }
        $this->assertSame([], $this->sent('unsetupreseller'));

        $this->owned = [];
        ExtensionHelper::upgradeServer($this->service->fresh());
        $this->assertCount(1, $this->sent('unsetupreseller'));
        $this->assertNull($this->service->properties()->where('key', CPanel::RESELLER)->value('value'));
    }

    public function test_a_plain_account_never_calls_a_reseller_api(): void
    {
        $this->product->settings()->where('key', 'reseller')->update(['value' => '0']);

        ExtensionHelper::createServer($this->service->fresh());
        ExtensionHelper::upgradeServer($this->service->fresh());
        ExtensionHelper::suspendServer($this->service->fresh());
        ExtensionHelper::terminateServer($this->service->fresh());

        // Reads only (listaccts before a removal, listresellers on suspend: A1, A5 of #315); never a reseller write.
        foreach (['setupreseller', 'suspendreseller', 'terminatereseller', 'setresellerlimits', 'setacls'] as $call) {
            $this->assertSame([], $this->sent($call), $call);
        }
    }
}
