<?php

namespace Tests\Feature;

use App\Admin\Resources\ProductResource;
use App\Admin\Resources\ProductResource\Pages\ListProducts;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\Staff;
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
}
