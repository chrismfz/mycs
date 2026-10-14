<?php

namespace Tests\Feature;

use App\Admin\Resources\ServerGroupResource\Pages\CreateServerGroup;
use App\Helpers\ExtensionHelper;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Service\ServerSelector;
use Exception;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Independent review of c2da0ee (service servers, server groups): failing tests for the findings. */
class ServiceServersReviewTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        require_once __DIR__ . '/../Fixtures/FalseServer.php';
        $this->user = User::factory()->create();
        $this->product = $this->createProduct()->product;
        $this->product->update(['server_id' => $this->server('Product server')->id]);
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

    /**
     * F1: an account's server deleted in the admin (a soft delete: the FK's restrict never fires) sends the account's
     * module actions to the product's server, another machine: a terminate there hits whatever account has that
     * username.
     */
    public function test_f1_a_deleted_servers_accounts_are_not_sent_to_the_products_server(): void
    {
        $this->product->update(['server_id' => $this->server('Other machine', extension: 'FalseServer')->id]);
        $old = $this->server('Old machine');
        $service = $this->service(['server_id' => $old->id]);

        // Deleted anyway (the admin now refuses it, F1b: a row deleted before that, or by hand).
        DB::table('extensions')->where('id', $old->id)->update(['deleted_at' => now()]);

        $this->assertNotSame($this->product->server_id, $service->fresh()->provisioningServer()?->id,
            'the account is on the deleted server, not on the product\'s');
        try {
            ExtensionHelper::terminateServer($service->fresh());
            $this->fail('terminate of an account whose server is gone went through');
        } catch (Exception $e) {
            $this->assertStringNotContainsString('(FalseServer)', $e->getMessage(), 'terminate went to the product\'s server');
            $this->assertStringContainsString('no longer exists', $e->getMessage(), 'not the «no server» the jobs skip quietly');
        }
    }

    /** F1b: «a server with accounts on it is not deleted (restrict)»: the admin's delete is a soft delete and goes through. */
    public function test_f1b_a_server_with_accounts_is_not_deleted(): void
    {
        $server = $this->server('Busy');
        $this->service(['server_id' => $server->id]);

        try {
            $server->delete();
        } catch (\Throwable) {
            // refused: fine
        }

        $this->assertNotNull(Server::query()->find($server->id), 'a server with live accounts was deleted');
    }

    /**
     * F2: the in-order fill rule orders by the pivot's position, but the admin form (a multi-select synced by Filament)
     * never sets it: every server gets position 0 and «in order» has no order.
     */
    public function test_f2_the_admin_form_gives_the_servers_an_order(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $a = $this->server('A', max: 300);
        $b = $this->server('B', max: 300);

        $this->livewireAs($staff)->test(CreateServerGroup::class)
            ->fillForm(['name' => 'cPanel EU', 'fill_type' => ServerGroup::FILL_IN_ORDER, 'servers' => [$b->id, $a->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        $positions = DB::table('server_group_server')->pluck('position')->all();
        $this->assertCount(2, array_unique($positions), 'every server has position 0: «in order» is the database\'s order');
    }

    /**
     * F3: a product without a server (its group field is only hidden in the form, its value kept) still gets a group
     * server, of any module: the group's module filter is skipped when the product has no server.
     */
    public function test_f3_a_product_without_a_server_gets_no_group_server_of_another_module(): void
    {
        $cpanel = $this->server('cPanel', extension: 'CPanel');
        $group = ServerGroup::query()->create(['name' => 'G', 'fill_type' => ServerGroup::FILL_LEAST_FULL]);
        $group->servers()->attach($cpanel->id, ['position' => 0]);
        $this->product->update(['server_id' => null, 'server_group_id' => $group->id]);

        $this->assertNull(ServerSelector::forNewAccount($this->product->fresh()));
    }

    /**
     * F4: the service keeps its server when its product changes (an upgrade to another product): a product of another
     * module sends its settings to a server of the old module.
     */
    public function test_f4_the_provisioning_server_is_of_the_products_module(): void
    {
        $service = $this->service(['server_id' => $this->product->server_id]); // NixpalLicensing
        $other = $this->createProduct(['name' => 'Test Product'])->product;
        $other->update(['server_id' => $this->server('Other module', extension: 'FalseServer')->id]);

        $service->update(['product_id' => $other->id]); // ServiceUpgradeService does this on a product change

        $this->assertSame($other->server->extension, $service->fresh()->provisioningServer()?->extension);
    }

    /**
     * F5: least full mixes units: an unlimited server scores its account count, a limited one its share, so an unlimited
     * server with 1 account (score 1) loses to one at 90 of 100 (0.9).
     */
    public function test_f5_least_full_does_not_treat_an_unlimited_server_with_one_account_as_full(): void
    {
        $unlimited = $this->server('Unlimited');
        $almostFull = $this->server('Almost full', max: 100);
        $group = ServerGroup::query()->create(['name' => 'G', 'fill_type' => ServerGroup::FILL_LEAST_FULL]);
        $group->servers()->attach([$unlimited->id => ['position' => 0], $almostFull->id => ['position' => 1]]);
        $this->product->update(['server_group_id' => $group->id]);
        $this->service(['server_id' => $unlimited->id]);
        DB::table('services')->insert(array_fill(0, 90, ['client_id' => $this->user->client()->id, 'product_id' => $this->product->id,
            'plan_id' => $this->product->plans()->first()->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1,
            'server_id' => $almostFull->id, 'created_at' => now(), 'updated_at' => now()]));

        $this->assertSame($unlimited->id, ServerSelector::forNewAccount($this->product->fresh())->id);
    }

    /**
     * F6: products.server_id has no foreign key: a product pointing at a server row no longer there makes the backfill
     * break the new services.server_id key (on MariaDB the migration stops half done: the new columns and tables stay,
     * and a rerun fails on them).
     */
    public function test_f6_the_backfill_survives_a_product_pointing_at_a_missing_server(): void
    {
        $service = $this->service();
        DB::table('services')->where('id', $service->id)->update(['server_id' => null]);
        DB::table('products')->where('id', $this->product->id)->update(['server_id' => 999999]);

        (require database_path('migrations/2026_11_03_000000_service_servers_and_server_groups.php'))->backfill();

        $this->assertNull(DB::table('services')->where('id', $service->id)->value('server_id'));
    }
}
