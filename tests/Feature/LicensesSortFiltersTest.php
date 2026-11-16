<?php

namespace Tests\Feature;

use App\Admin\Resources\LicenseResource\Pages\ListLicenses;
use App\Models\License;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Owner 2026-11-16: the licenses' list sorts by client, product, API and status, and filters by product, client and API. */
class LicensesSortFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
    }

    private function license(string $key, string $company, string $productName, ?string $endpoint, string $status = License::STATUS_ACTIVE): License
    {
        $product = $this->createProduct();
        $product->product->update(['name' => $productName]);
        $client = User::factory()->create()->client();
        $client->primaryContact->update(['type' => 'business', 'company_name' => $company]);
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1]);

        return License::query()->create(['service_id' => $service->id, 'license_key' => $key, 'status' => $status, 'reissues' => 0,
            'last_access' => now(), 'last_endpoint' => $endpoint]);
    }

    public function test_it_sorts_by_client_product_api_and_status(): void
    {
        $b = $this->license('ZCAgent-b', 'Beta Host', 'Zonecloud Node', 'v1', License::STATUS_EXPIRED);
        $a = $this->license('ZCAgent-a', 'Alpha Host', 'Zonecloud Agent', 'v0');

        $list = fn (string $column, string $direction = 'asc') => Livewire::test(ListLicenses::class)->set('activeTab', 'all')->sortTable($column, $direction);

        $list('service.client.name')->assertCanSeeTableRecords([$a, $b], inOrder: true);
        $list('service.client.name', 'desc')->assertCanSeeTableRecords([$b, $a], inOrder: true);
        $list('service.product.name')->assertCanSeeTableRecords([$a, $b], inOrder: true);
        $list('last_endpoint')->assertCanSeeTableRecords([$a, $b], inOrder: true);
        $list('status')->assertCanSeeTableRecords([$a, $b], inOrder: true);
    }

    public function test_it_filters_by_product_client_and_api_on_the_page(): void
    {
        $agent = $this->license('ZCAgent-1', 'Alpha Host', 'Zonecloud Agent', 'v1');
        $node = $this->license('ZCNode-1', 'Beta Host', 'Zonecloud Node', null);

        $page = Livewire::test(ListLicenses::class)->set('activeTab', 'all');
        $this->assertSame(FiltersLayout::AboveContentCollapsible, $page->instance()->getTable()->getFiltersLayout());

        $page->filterTable('product', [$agent->service->product_id])->assertCanSeeTableRecords([$agent])->assertCanNotSeeTableRecords([$node]);
        Livewire::test(ListLicenses::class)->set('activeTab', 'all')->filterTable('client', $node->service->client_id)
            ->assertCanSeeTableRecords([$node])->assertCanNotSeeTableRecords([$agent]);
        Livewire::test(ListLicenses::class)->set('activeTab', 'all')->filterTable('last_endpoint', 'none')
            ->assertCanSeeTableRecords([$node])->assertCanNotSeeTableRecords([$agent]);
        Livewire::test(ListLicenses::class)->set('activeTab', 'all')->filterTable('last_endpoint', 'v1')
            ->assertCanSeeTableRecords([$agent])->assertCanNotSeeTableRecords([$node]);
    }
}
