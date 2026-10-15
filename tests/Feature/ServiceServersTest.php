<?php

namespace Tests\Feature;

use App\Admin\Resources\ServerGroupResource\Pages\CreateServerGroup;
use App\Admin\Resources\ServerResource\Pages\EditServer;
use App\Admin\Resources\ServerResource\Pages\ListServers;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Helpers\ExtensionHelper;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Service\ServerSelector;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A service's own server and server groups (WHMCS's tblhosting.server, tblservergroups): new accounts go to the group's
 * server its fill rule picks, within max accounts; every module action goes to the service's server.
 */
class ServiceServersTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create();
        $created = $this->createProduct();
        $this->product = $created->product;
        $this->product->update(['server_id' => $this->server('Licensing A')->id]);
        foreach (['key_prefix' => 'ZC-', 'key_length' => '16', 'secret_key' => 'x'] as $key => $value) {
            $this->product->settings()->create(['key' => $key, 'value' => $value]);
        }
    }

    private function server(string $name, ?int $max = null, bool $enabled = true, string $extension = 'NixpalLicensing'): Server
    {
        return Server::query()->create(['name' => $name, 'extension' => $extension, 'type' => 'server', 'enabled' => $enabled, 'max_accounts' => $max]);
    }

    private function service(array $attributes = []): Service
    {
        return Service::factory()->create(array_merge(['client_id' => $this->user->client()->id, 'product_id' => $this->product->id,
            'plan_id' => $this->product->plans()->first()->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1], $attributes));
    }

    private function group(string $fill, Server ...$servers): ServerGroup
    {
        $group = ServerGroup::query()->create(['name' => 'Group', 'fill_type' => $fill]);
        foreach ($servers as $position => $server) {
            $group->servers()->attach($server->id, ['position' => $position]);
        }
        $this->product->update(['server_group_id' => $group->id]);

        return $group;
    }

    public function test_without_a_group_a_new_account_goes_to_the_products_server_and_keeps_it(): void
    {
        $service = $this->service(['status' => 'pending']);

        ExtensionHelper::createServer($service);
        $this->assertSame($this->product->server_id, $service->fresh()->server_id);

        $this->product->update(['server_id' => $this->server('Licensing B')->id]); // the product moves on
        $this->assertNotSame($this->product->server_id, $service->fresh()->server_id, 'the account stays where it was made');
    }

    public function test_least_full_picks_the_lowest_share_of_max_accounts_and_skips_full_or_disabled(): void
    {
        $busy = $this->server('Busy', max: 10);
        $quiet = $this->server('Quiet', max: 100);
        $full = $this->server('Full', max: 1);
        $off = $this->server('Off', enabled: false);
        $this->group(ServerGroup::FILL_LEAST_FULL, $busy, $quiet, $full, $off);
        $this->service(['server_id' => $busy->id]);
        $this->service(['server_id' => $busy->id]);
        $this->service(['server_id' => $quiet->id]);
        $this->service(['server_id' => $full->id]);

        $this->assertSame($quiet->id, ServerSelector::forNewAccount($this->product->fresh())->id); // 1/100 < 2/10
    }

    public function test_in_order_fills_the_first_server_until_full(): void
    {
        $first = $this->server('First', max: 2);
        $second = $this->server('Second', max: 2);
        $this->group(ServerGroup::FILL_IN_ORDER, $first, $second);

        $this->assertSame($first->id, ServerSelector::forNewAccount($this->product->fresh())->id);
        $this->service(['server_id' => $first->id]);
        $this->service(['server_id' => $first->id]);
        $this->assertSame($second->id, ServerSelector::forNewAccount($this->product->fresh())->id);
    }

    public function test_a_group_of_another_module_or_all_full_falls_back_to_the_products_server(): void
    {
        $this->group(ServerGroup::FILL_LEAST_FULL, $this->server('cPanel', extension: 'CPanel'), $this->server('Tiny', max: 1));
        $this->service(['server_id' => Server::query()->where('name', 'Tiny')->value('id')]);

        $this->assertSame($this->product->server_id, ServerSelector::forNewAccount($this->product->fresh())->id);
    }

    public function test_module_actions_go_to_the_services_own_server(): void
    {
        require_once __DIR__ . '/../Fixtures/FalseServer.php';
        $legacy = $this->server('Legacy', extension: 'FalseServer');
        $service = $this->service(['server_id' => $legacy->id]);

        $this->expectExceptionMessage('(FalseServer)');
        ExtensionHelper::suspendServer($service->fresh());
    }

    public function test_existing_services_take_their_products_server(): void
    {
        $service = $this->service();
        DB::table('services')->where('id', $service->id)->update(['server_id' => null]);

        (require database_path('migrations/2026_11_03_000000_service_servers_and_server_groups.php'))->backfill();

        $this->assertSame($this->product->server_id, (int) DB::table('services')->where('id', $service->id)->value('server_id'));
    }

    public function test_staff_make_a_server_group(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $a = $this->server('A', max: 300);

        $this->livewireAs($staff)->test(CreateServerGroup::class)
            ->fillForm(['name' => 'cPanel EU', 'fill_type' => ServerGroup::FILL_LEAST_FULL, 'servers' => [$a->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame([$a->id], ServerGroup::query()->where('name', 'cPanel EU')->sole()->servers->modelKeys());
    }

    public function test_staff_record_a_move_to_another_server_of_the_same_module_and_it_is_audited(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $service = $this->service(['server_id' => $this->product->server_id, 'expires_at' => now()->addMonth()]);
        $other = $this->server('Licensing B');
        $this->server('cPanel 1', extension: 'CPanel');
        config(['audit.console' => true]);

        $page = $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()]);
        $options = $page->instance()->form->getComponent('data.server_id')?->getOptions()
            ?? collect($page->instance()->form->getFlatComponents())->first(fn ($c) => $c instanceof Select && $c->getName() === 'server_id')->getOptions();
        $this->assertContains('Licensing B', $options);
        $this->assertNotContains('cPanel 1', $options, 'another module is not offered');

        $page->fillForm(['server_id' => $other->id])->call('save')->assertHasNoFormErrors();

        $this->assertSame($other->id, $service->fresh()->server_id);
        $this->assertTrue(DB::table('audits')->where('auditable_type', (new Service)->getMorphClass())->where('auditable_id', $service->id)
            ->where('new_values', 'like', '%server_id%')->exists(), 'the move is in the audit');
    }

    public function test_staff_cannot_delete_a_server_with_accounts_on_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $busy = $this->server('Busy');
        $service = $this->service(['server_id' => $busy->id]);

        $this->livewireAs($staff)->test(EditServer::class, ['record' => $busy->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified('Servers in use are not deleted');
        $this->livewireAs($staff)->test(ListServers::class)
            ->callTableBulkAction(DeleteBulkAction::class, [$busy]);
        $this->assertNotNull(Server::query()->find($busy->id));

        $service->update(['status' => 'cancelled']); // ended: its server may go
        $this->livewireAs($staff)->test(EditServer::class, ['record' => $busy->getRouteKey()])->callAction(DeleteAction::class);
        $this->assertNull(Server::query()->find($busy->id));
    }
}
