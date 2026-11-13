<?php

namespace Tests\Feature;

use App\Admin\Resources\DomainResource\Pages\ListDomains;
use App\Admin\Resources\ServerResource;
use App\Admin\Resources\ServerResource\Pages\ListServers;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\ServiceResource\Pages\ListService;
use App\Admin\TopMenu;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What runs where (owner 2026-11-13): a server's services from the servers list and the dashboard; Utilities › Offline
 * services (offline modules) and Offline domains (a «manual» registrar, its own or its TLD's).
 */
class ServersServicesOfflineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function serviceOn(Server $server): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10]);
    }

    public function test_a_servers_services_and_the_offline_ones(): void
    {
        $orion = Server::query()->create(['name' => 'Orion', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $manual = Server::query()->create(['name' => 'Manual (offline)', 'extension' => 'OfflineDedicated', 'type' => 'server', 'enabled' => true]);
        [$hosting, $machine] = [$this->serviceOn($orion), $this->serviceOn($manual)];
        $this->actingAs(Staff::factory()->withPermissions(['admin.servers.viewAny', 'admin.servers.update', 'admin.services.viewAny', 'admin.domains.viewAny'])->create(), 'staff');

        Livewire::test(ListService::class)->filterTable('server', $manual->id)
            ->assertCanSeeTableRecords([$machine])->assertCanNotSeeTableRecords([$hosting]);
        Livewire::test(ListService::class)->filterTable('offline')
            ->assertCanSeeTableRecords([$machine])->assertCanNotSeeTableRecords([$hosting]);

        $url = ServerResource::servicesUrl($manual);
        $this->assertStringContainsString('filters', urldecode((string) $url));
        Livewire::test(ListServers::class)->assertActionVisible(TestAction::make('services')->table($manual))
            ->assertActionHasUrl(TestAction::make('services')->table($manual), $url);
        // The URL's filter is the one the list applies.
        Livewire::withQueryParams(['filters' => ['server' => ['value' => $manual->id]]])->test(ListService::class)
            ->assertCanSeeTableRecords([$machine])->assertCanNotSeeTableRecords([$hosting]);

        $utilities = collect(collect(TopMenu::groups())->firstWhere('label', 'Utilities')['items'])->keyBy('label');
        $this->assertStringContainsString('offline', urldecode($utilities['Offline Services']['url']));
        $this->assertStringContainsString('manual_registrar', urldecode($utilities['Offline Domains']['url']));

        // On Offline Services only that item is lit, not Clients › Products/Services too (review of #841); a filter
        // ticked in the table («true») is the same as the link's («1»).
        foreach (['1', 'true'] as $ticked) {
            $this->get(ServiceResource::getUrl('index', ['filters' => ['offline' => ['isActive' => $ticked]]]));
            $lit = collect(TopMenu::groups())->flatMap(fn ($g) => collect($g['items'])->where('active', true)->map(fn ($i) => $g['label'] . '/' . $i['label']))->values()->all();
            $this->assertSame(['Utilities/Offline Services'], $lit, $ticked);
        }
    }

    public function test_staff_who_may_not_see_services_get_no_link(): void
    {
        $server = Server::query()->create(['name' => 'Orion', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $this->actingAs(Staff::factory()->withPermissions(['admin.servers.viewAny'])->create(), 'staff');

        $this->assertNull(ServerResource::servicesUrl($server));
        Livewire::test(ListServers::class)->assertActionHidden(TestAction::make('services')->table($server));
    }

    public function test_offline_domains_are_those_at_a_manual_registrar_own_or_tlds(): void
    {
        $manual = DomainRegistrarConnection::query()->create(['registrar' => 'manual', 'label' => 'By hand', 'is_active' => true, 'mode' => 'production']);
        $api = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $byHandTld = DomainTld::query()->create(['tld' => 'old', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $manual->id]);
        $apiTld = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 2, 'registrar_connection_id' => $api->id]);
        $own = Domain::factory()->create(['domain_tld_id' => $apiTld->id, 'registrar_connection_id' => $manual->id]);
        $viaTld = Domain::factory()->create(['domain_tld_id' => $byHandTld->id]);
        $overridden = Domain::factory()->create(['domain_tld_id' => $byHandTld->id, 'registrar_connection_id' => $api->id]);
        $plain = Domain::factory()->create(['domain_tld_id' => $apiTld->id]);
        $this->actingAs(Staff::factory()->withPermissions(['admin.domains.viewAny'])->create(), 'staff');

        Livewire::test(ListDomains::class)->filterTable('manual_registrar')
            ->assertCanSeeTableRecords([$own, $viaTld])->assertCanNotSeeTableRecords([$overridden, $plain]);
    }
}
