<?php

namespace Tests\Feature;

use App\Admin\Resources\ProductResource;
use App\Admin\Resources\ProductResource\Pages\ListProducts;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The product list as WHMCS's (owner 2026-10-23): Type, Pay Type, Stock, Auto Setup, and whether it is shown. */
class ProductListColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_tells_type_pay_type_stock_auto_setup_and_visibility(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
        $server = Server::query()->create(['name' => 'Rigel', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $hosting = $this->createProduct()->product;
        $hosting->forceFill(['server_id' => $server->id, 'stock' => 5, 'autosetup' => Product::AUTOSETUP_ORDER, 'hidden' => true])->save();
        $hosting->plans()->update(['type' => 'recurring']);
        $free = $this->createProduct()->product;
        $free->forceFill(['autosetup' => Product::AUTOSETUP_NEVER, 'retired' => true])->save();
        $free->plans()->update(['type' => 'free']);

        Livewire::test(ListProducts::class)
            ->assertTableColumnStateSet('type', 'CPanel', $hosting)->assertTableColumnStateSet('pay_type', 'Recurring', $hosting)
            ->assertTableColumnStateSet('stock', '5', $hosting)->assertTableColumnFormattedStateSet('autosetup', 'On order', $hosting)
            ->assertTableColumnStateSet('visibility', 'Hidden', $hosting)
            ->assertTableColumnStateSet('type', 'Other', $free)->assertTableColumnStateSet('pay_type', 'Free', $free)
            ->assertTableColumnStateSet('stock', '∞', $free)->assertTableColumnFormattedStateSet('autosetup', 'Off', $free)
            ->assertTableColumnStateSet('visibility', 'Retired', $free);
        $this->assertSame('Free', ProductResource::payType($free->fresh()->load('plans')));
    }

    /** Owner 2026-11-14: how many are sold at a glance, active with the suspended beside them; every status in the tooltip. */
    public function test_the_list_counts_each_products_services(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
        $agent = $this->createProduct()->product;
        $controller = $this->createProduct()->product;
        $unsold = $this->createProduct()->product;
        $clientId = User::factory()->create()->client()->id;
        Product::query()->whereKey([$controller->id, $unsold->id])->update(['category_id' => $agent->category_id]); // one group, sorted within it
        foreach (['active', 'active', 'active', 'suspended', 'terminated', 'cancelled', 'pending'] as $status) {
            Service::factory()->create(['product_id' => $agent->id, 'plan_id' => $agent->plans()->first()->id, 'client_id' => $clientId, 'status' => $status]);
        }
        Service::factory()->create(['product_id' => $controller->id, 'plan_id' => $controller->plans()->first()->id, 'client_id' => $clientId, 'status' => 'active']);
        Service::factory()->create(['product_id' => $unsold->id, 'plan_id' => $unsold->plans()->first()->id, 'client_id' => $clientId, 'status' => 'terminated']);

        Livewire::test(ListProducts::class)
            ->assertTableColumnStateSet('active_services', '3 · 1 susp.', $agent)
            ->assertTableColumnStateSet('active_services', '1', $controller)
            ->assertTableColumnStateSet('active_services', '0', $unsold)
            ->assertSee('Active 3 · Suspended 1 · Pending 1 · Terminated 1 · Cancelled 1')
            ->sortTable('active_services', 'desc')
            ->assertCanSeeTableRecords([$agent, $controller, $unsold], inOrder: true);
    }
}
