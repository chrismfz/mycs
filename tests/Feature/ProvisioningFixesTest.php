<?php

namespace Tests\Feature;

use App\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Admin\Resources\ServerGroupResource\Pages\CreateServerGroup;
use App\Exceptions\DisplayException;
use App\Exceptions\ServerHasAccounts;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\UpgradeJob;
use App\Models\ModuleAction;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Exception;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\FlakyServer\FlakyServer;
use Tests\TestCase;

/** Sweep §2/§4/§7 (docs/sweeps/modules.md): the provisioning fixes besides cPanel's. */
class ProvisioningFixesTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Fixtures/FlakyServer.php';
        FlakyServer::$failures = 0;
        FlakyServer::$calls = [];
        Queue::fake();
        $this->server = $this->server('Flaky A');
        $this->product = $this->createProduct()->product;
        $this->product->update(['server_id' => $this->server->id]);
    }

    private function server(string $name, ?int $max = null, bool $enabled = true, string $extension = 'FlakyServer'): Server
    {
        return Server::query()->create(['name' => $name, 'extension' => $extension, 'type' => 'server', 'enabled' => $enabled, 'max_accounts' => $max]);
    }

    private function service(array $attributes = []): Service
    {
        return Service::factory()->create(array_merge(['client_id' => User::factory()->create()->client()->id, 'product_id' => $this->product->id,
            'plan_id' => $this->product->plans()->first()->id, 'status' => 'pending', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1], $attributes));
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner ' . uniqid(), 'permissions' => ['*']])->id]);
    }

    public function test_a_change_package_retried_after_a_terminate_does_nothing(): void
    {
        $service = $this->service(['status' => 'terminated', 'server_id' => $this->server->id]);

        (new UpgradeJob($service))->handle();

        $this->assertSame([], FlakyServer::$calls);
        $this->assertSame(0, ModuleAction::query()->count());
    }

    public function test_upgrades_are_only_to_products_of_the_same_module(): void
    {
        $same = $this->createProduct(['name' => 'Test Product'])->product;
        $same->update(['server_id' => $this->server('Flaky B')->id]);
        $other = $this->createProduct(['name' => 'Test Product'])->product;
        $other->update(['server_id' => $this->server('Licensing', extension: 'NixpalLicensing')->id]);
        $this->product->upgrades()->sync([$same->id, $other->id]);
        $service = $this->service(['status' => 'active']);

        $this->assertSame([$same->id], $service->fresh()->productUpgrades()->pluck('id')->values()->all());

        $this->livewireAs($this->staff())->test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->set('data.upgrades', [$other->id])->call('save')
            ->assertHasErrors(['data.upgrades']);
    }

    public function test_a_full_or_disabled_server_takes_no_new_account_and_the_create_says_why(): void
    {
        $this->server->update(['max_accounts' => 1]);
        $this->service(['status' => 'active', 'server_id' => $this->server->id]);
        $new = $this->service();

        try {
            ExtensionHelper::createServer($new);
            $this->fail('no server with room');
        } catch (Exception $e) {
            $this->assertStringContainsString('No enabled server with room', $e->getMessage());
        }
        $this->assertNull($new->fresh()->server_id);
        $this->assertSame('pending', $new->fresh()->status, 'not started without an account');
        $this->assertSame('create', ModuleAction::query()->open()->sole()->action, 'staff see it in Pending Module Actions');
        $this->assertSame([], FlakyServer::$calls);

        $this->server->update(['max_accounts' => null, 'enabled' => false]);
        $this->expectExceptionMessage('No enabled server with room');
        ExtensionHelper::createServer($new->fresh());
    }

    public function test_a_group_holds_one_module_and_is_not_deleted_while_products_use_it(): void
    {
        $this->livewireAs($this->staff())->test(CreateServerGroup::class)
            ->fillForm(['name' => 'Mixed', 'fill_type' => ServerGroup::FILL_LEAST_FULL,
                'servers' => [$this->server->id, $this->server('Licensing', extension: 'NixpalLicensing')->id]])
            ->call('create')
            ->assertHasFormErrors(['servers']);

        $group = ServerGroup::query()->create(['name' => 'G', 'fill_type' => ServerGroup::FILL_LEAST_FULL]);
        $this->product->update(['server_group_id' => $group->id]);
        $this->expectException(DisplayException::class);
        $group->delete();
    }

    public function test_a_server_products_make_their_accounts_on_is_not_deleted(): void
    {
        $this->expectException(ServerHasAccounts::class);
        $this->expectExceptionMessage('is the server of 1 product(s)');

        $this->server->delete();
    }
}
