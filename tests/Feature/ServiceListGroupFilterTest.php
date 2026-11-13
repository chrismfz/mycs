<?php

namespace Tests\Feature;

use App\Admin\Clusters\Services;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\ServiceResource\Pages\ListService;
use App\Models\Category;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Enums\SubNavigationPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The services list (owner 2026-11-13): filtered by a product group, its subgroups with it («all hosting packages»);
 * the cluster's pages as tabs above, not a column on the left.
 */
class ServiceListGroupFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_product_group_filter_takes_its_subgroups_too(): void
    {
        config(['settings.default_currency' => 'USD']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $hosting = Category::factory()->create(['name' => 'Hosting']);
        $wordpress = Category::factory()->create(['name' => 'WordPress', 'parent_id' => $hosting->id]);
        $licenses = Category::factory()->create(['name' => 'Licenses']);
        $service = function (Category $category): Service {
            $product = $this->createProduct();
            $product->product->forceFill(['category_id' => $category->id])->save();

            return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
                'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10]);
        };
        [$plain, $wp, $licence] = [$service($hosting), $service($wordpress), $service($licenses)];
        $this->actingAs(Staff::factory()->withPermissions(['admin.services.viewAny'])->create(), 'staff');

        $options = ServiceResource::categoryOptions();
        $this->assertSame(['Hosting', 'Hosting › WordPress', 'Licenses'], [$options[$hosting->id], $options[$wordpress->id], $options[$licenses->id]]);
        Livewire::test(ListService::class)->filterTable('category', $hosting->id)
            ->assertCanSeeTableRecords([$plain, $wp])->assertCanNotSeeTableRecords([$licence]);
        Livewire::test(ListService::class)->filterTable('category', $wordpress->id)
            ->assertCanSeeTableRecords([$wp])->assertCanNotSeeTableRecords([$plain, $licence]);
        $this->assertSame(SubNavigationPosition::Top, Services::getSubNavigationPosition());

        // Two groups each other's parent (review of #836): labelled, the list still shows.
        $licenses->forceFill(['parent_id' => $wordpress->id])->save();
        $hosting->forceFill(['parent_id' => $licenses->id])->save();
        $this->assertSame('Licenses › Hosting › WordPress', ServiceResource::categoryOptions()[$wordpress->id]);
        Livewire::test(ListService::class)->assertSuccessful()->filterTable('category', $hosting->id)
            ->assertCanSeeTableRecords([$plain, $wp, $licence]);
    }
}
