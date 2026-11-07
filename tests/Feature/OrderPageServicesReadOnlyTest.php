<?php

namespace Tests\Feature;

use App\Admin\Resources\OrderResource\Pages\EditOrder;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Sweep ADMIN-1/2/3: the order page shows its services and never writes them, nor the order's client or currency. */
class OrderPageServicesReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_currency_of_a_placed_order_and_its_services_cannot_be_changed_from_the_order_page(): void
    {
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        $product = Product::factory()->create(['autosetup' => Product::AUTOSETUP_NEVER, 'server_id' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => '10.00', 'currency_code' => 'USD']);
        $order = (new PlaceOrderService)->place(User::factory()->create()->client(), 'USD', [new OrderLine($product->fresh(), $plan)])->order;
        $service = $order->services()->sole();
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Orders', 'permissions' => ['admin.orders.viewAny', 'admin.orders.view', 'admin.orders.update']])->id]);

        $this->livewireAs($staff)->test(EditOrder::class, ['record' => $order->id])
            ->assertFormFieldDisabled('client_id')
            ->assertFormFieldDisabled('currency_code')
            ->set('data.currency_code', 'EUR')
            ->set('data.services.record-' . $service->id . '.price', '1.00')
            ->set('data.notes', 'Checked.')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['USD', 'Checked.'], [$order->fresh()->currency_code, $order->fresh()->notes]);
        $this->assertSame(['USD', '10.00'], [$service->fresh()->currency_code, number_format((float) $service->fresh()->price, 2, '.', '')]);
    }
}
