<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceUsage;
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

/** The service's page, compact as WHMCS's (owner 2026-10-23, the mockup), and staff signing into its panel. */
class ServicePageCompactTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    /** @var list<array<string, mixed>> what WHM was asked for a session */
    private array $sessions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['settings.default_currency' => 'USD']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
        $server = Server::query()->create(['name' => 'Orion', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10,
            'expires_at' => now()->addMonth(), 'domain' => 'zonecloud.io', 'username' => 'zonecl']);
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'zonecl']);
        ServiceUsage::query()->create(['service_id' => $this->service->id, 'disk_used_mb' => 4222, 'disk_limit_mb' => 20480,
            'bandwidth_used_mb' => 60, 'bandwidth_limit_mb' => null, 'server_ip' => '192.0.2.10', 'read_at' => now()]);
        Http::fake(function (Request $request) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/create_user_session')) {
                $this->sessions[] = $request->data();

                return Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['url' => 'https://orion.test:2083/cpsess1/login/?session=x']]);
            }

            return Http::response([], 404);
        });
    }

    private function staff(array $permissions = ['*']): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    public function test_its_usage_on_one_line_billing_and_account_side_by_side_and_the_commands_in_a_row(): void
    {
        $this->staff();

        $this->get(ServiceResource::getUrl('edit', ['record' => $this->service]))->assertOk()
            ->assertSeeInOrder(['Disk', '20 GB (21%)', 'Bandwidth', 'unlimited', '192.0.2.10', 'Billing', 'Account on the server'])
            ->assertSee('Suspend')->assertSee('Change package')->assertSee('More');
    }

    public function test_a_command_button_opens_the_module_action_at_that_command(): void
    {
        $this->staff();

        Livewire::test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->mountAction('changeStatus', ['action' => 'suspend'])->assertActionMounted('changeStatus')
            ->assertSchemaStateSet(['action' => 'suspend', 'sendNotification' => false], 'mountedActionSchema0');
    }

    public function test_staff_sign_into_its_panel_audited_and_only_with_the_permission(): void
    {
        $staff = $this->staff();

        Livewire::test(EditService::class, ['record' => $this->service->getRouteKey()])->assertActionVisible('loginToPanel')
            ->callAction('loginToPanel')->assertRedirect('https://orion.test:2083/cpsess1/login/?session=x');

        $this->assertSame('zonecl', $this->sessions[0]['user'] ?? null);
        $this->assertSame('cpaneld', $this->sessions[0]['service'] ?? null);
        $audit = Audit::query()->where('event', 'panel_login')->sole();
        $this->assertSame($staff->id, $audit->new_values['staff_id'] ?? null);

        $this->staff(['admin.services.viewAny', 'admin.services.update']);
        Livewire::test(EditService::class, ['record' => $this->service->getRouteKey()])->assertActionHidden('loginToPanel');
        $this->assertCount(1, $this->sessions);
    }

    public function test_not_for_an_ended_service(): void
    {
        $this->staff();
        $this->service->update(['status' => 'terminated']);

        Livewire::test(EditService::class, ['record' => $this->service->getRouteKey()])->assertActionHidden('loginToPanel');
    }
}
