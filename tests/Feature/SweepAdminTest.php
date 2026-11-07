<?php

namespace Tests\Feature;

use App\Admin\Resources\CouponResource\Pages\EditCoupon;
use App\Admin\Resources\CouponResource\RelationManagers\ServicesRelationManager;
use App\Admin\Resources\OrderResource\Pages\EditOrder;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Adversarial sweep of the admin area (2026-11-07): each test fails on main for the reason in its docblock. */
class SweepAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function product(string $price = '10.00'): Product
    {
        $product = Product::factory()->create(['autosetup' => Product::AUTOSETUP_NEVER, 'server_id' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function staff(array $permissions): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    /** @return array{0: Service, 1: Invoice} */
    private function placed(?Coupon $coupon = null): array
    {
        $product = $this->product();
        $placed = (new PlaceOrderService)->place(User::factory()->create()->client(), 'USD',
            [new OrderLine($product, $product->plans()->first())], coupon: $coupon);

        return [$placed->order->services()->sole(), $placed->invoice];
    }

    /**
     * ADMIN-1: removing a service row from the order page's «Services» repeater deletes it with a plain delete():
     * DeleteService never runs (its unpaid invoice stays open, billing a service that is gone) and staff with only
     * the orders' permission delete services.
     */
    public function test_admin_1_the_order_page_deletes_a_service_without_delete_service(): void
    {
        [$service, $invoice] = $this->placed();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $staff = $this->staff(['admin.orders.viewAny', 'admin.orders.view', 'admin.orders.update']);

        $this->livewireAs($staff)->test(EditOrder::class, ['record' => $service->order_id])
            ->set('data.services', [])
            ->call('save');

        $gone = Service::query()->find($service->id) === null;
        $this->assertFalse($gone && $invoice->fresh()->status === Invoice::STATUS_UNPAID,
            'the service was deleted from the order page and its unpaid invoice was left open');
        $this->assertFalse($gone, 'staff without admin.services.delete deleted a service from the order page');
    }

    /**
     * ADMIN-2: saving the order page (e.g. for its notes) writes back the services' product, plan, price and
     * subscription as they were when the page loaded, over a change made meanwhile (an upgrade, the cron, a webhook).
     */
    public function test_admin_2_saving_the_order_writes_stale_service_fields_back(): void
    {
        [$service] = $this->placed();
        $service->update(['subscription_id' => 'sub_old']);
        $other = Plan::factory()->create(['priceable_id' => $service->product_id, 'priceable_type' => Product::class, 'name' => 'Yearly',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $other->id, 'price' => '100.00', 'currency_code' => 'USD']);
        $staff = $this->staff(['admin.orders.viewAny', 'admin.orders.view', 'admin.orders.update']);

        $page = $this->livewireAs($staff)->test(EditOrder::class, ['record' => $service->order_id]);
        // Meanwhile: an upgrade / the cron reprices it, a webhook clears its subscription.
        Service::query()->whereKey($service->id)->update(['plan_id' => $other->id, 'price' => '100.00', 'subscription_id' => null]);
        $page->set('data.notes', 'Called the customer.')->call('save')->assertHasNoFormErrors();

        $fresh = $service->fresh();
        $this->assertSame('Called the customer.', $fresh->order->notes);
        $this->assertSame([$other->id, '100.00', null], [(int) $fresh->plan_id, number_format((float) $fresh->price, 2, '.', ''), $fresh->subscription_id],
            'saving the order\'s notes put back the plan, price and subscription the page had loaded');
    }

    /**
     * ADMIN-3: changing the order's client moves its services to the other client (bypassing MoveToClient and
     * admin.services.move), while their unpaid invoice stays with the old client.
     */
    public function test_admin_3_changing_the_orders_client_moves_its_services_and_not_their_invoices(): void
    {
        [$service, $invoice] = $this->placed();
        $old = $service->client_id;
        $other = User::factory()->create()->client();
        $staff = $this->staff(['admin.orders.viewAny', 'admin.orders.view', 'admin.orders.update']);

        $this->livewireAs($staff)->test(EditOrder::class, ['record' => $service->order_id])
            ->set('data.client_id', $other->id)
            ->call('save');

        $moved = (int) $service->fresh()->client_id !== (int) $old;
        $this->assertFalse($moved && (int) $invoice->fresh()->client_id === (int) $old,
            'the service went to another client while its unpaid invoice stayed with the old one');
        $this->assertFalse($moved, 'staff without admin.services.move moved a service from the order page');
    }

    /**
     * ADMIN-4: a 3-cycle 50% coupon is in the service's renewal price; «Remove coupon» clears coupon_id and nothing
     * puts the full price back, so the discount becomes permanent (the cron only restores it while the coupon is set).
     */
    public function test_admin_4_removing_a_running_coupon_keeps_its_discount_for_ever(): void
    {
        $coupon = Coupon::create(['code' => 'HALF3', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 3]);
        [$service] = $this->placed($coupon);
        $this->assertSame('5.00', number_format((float) $service->price, 2, '.', ''));
        $this->assertSame($coupon->id, (int) $service->coupon_id);
        $staff = $this->staff(['*']);

        $this->livewireAs($staff)->test(ServicesRelationManager::class, ['ownerRecord' => $coupon, 'pageClass' => EditCoupon::class])
            ->callTableBulkAction('removeCoupon', [$service]);

        $fresh = $service->fresh();
        $this->assertNull($fresh->coupon_id);
        $this->assertSame('10.00', number_format((float) $fresh->price, 2, '.', ''),
            'the coupon is gone but the service keeps renewing at its 50% price, with nothing left to end it');
    }

    /** ADMIN-4 (deleting the coupon): the foreign key sets coupon_id null, with the same permanent discount. */
    public function test_admin_4_deleting_a_running_coupon_keeps_its_discount_for_ever(): void
    {
        $coupon = Coupon::create(['code' => 'HALF3D', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 3]);
        [$service] = $this->placed($coupon);
        $this->assertSame('5.00', number_format((float) $service->price, 2, '.', ''));

        $this->livewireAs($this->staff(['*']))->test(EditCoupon::class, ['record' => $coupon->id])->callAction('delete');

        $fresh = $service->fresh();
        $this->assertNull(Coupon::query()->find($coupon->id));
        $this->assertNull($fresh->coupon_id);
        $this->assertSame('10.00', number_format((float) $fresh->price, 2, '.', ''),
            'the deleted coupon\'s discount stays in the service\'s price for ever');
    }
}
