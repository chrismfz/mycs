<?php

namespace Tests\Feature;

use App\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Exceptions\DisplayException;
use App\Models\Coupon;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Service\DeleteService;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Adversarial verification, sweep 3, lens l-data-integrity. */
class Sweep3LDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function admin(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function coupon(): Coupon
    {
        return Coupon::query()->create([
            'type' => 'percentage', 'code' => 'LAUNCH50', 'value' => 50, 'recurring' => true, 'customers' => 'all',
        ]);
    }

    /**
     * L-DATA-INTEGRITY-1. A coupon limited to one product, that product deleted from its page (no service, no upgrade:
     * allowed): the pivot row goes by cascade, and an empty product list reads as «every product».
     */
    public function test_deleting_a_coupons_only_product_does_not_open_it_to_every_product(): void
    {
        $only = $this->createProduct()->product;
        $other = $this->createProduct()->product;
        $coupon = $this->coupon();
        $coupon->products()->attach($only->id);
        $this->assertFalse($coupon->fresh()->covers($other->id), 'limited to the one product');

        $this->livewireAs($this->admin())->test(EditProduct::class, ['record' => $only->getRouteKey()])->callAction(DeleteAction::class);

        $this->assertFalse($coupon->fresh()->covers($other->id), 'a coupon for a deleted product must not become a coupon for all');
    }

    /** L-DATA-INTEGRITY-1, the «Requires» half: the required product deleted, the requirement is gone for everyone. */
    public function test_deleting_a_coupons_required_product_does_not_drop_the_requirement(): void
    {
        $required = $this->createProduct()->product;
        $coupon = $this->coupon();
        $coupon->requiredProducts()->attach($required->id);
        $client = User::factory()->create()->client();
        $this->assertFalse($coupon->fresh()->requirementHeldBy($client), 'the client holds nothing it requires');

        $this->livewireAs($this->admin())->test(EditProduct::class, ['record' => $required->getRouteKey()])->callAction(DeleteAction::class);

        $this->assertFalse($coupon->fresh()->requirementHeldBy($client), 'the requirement must not vanish with the product');
    }

    /**
     * L-DATA-INTEGRITY-2. A cancelled service whose termination on the server failed (an open «terminate» in Pending
     * Module Actions) is deleted: no server call (it has ended), and the open action goes by cascade with it, so nobody
     * is left to see that the account still runs on the server.
     */
    public function test_deleting_an_ended_service_keeps_its_open_termination_visible(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_CANCELLED, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => now()->addMonth(),
        ]);
        $open = ModuleAction::recordFailure($service, 'terminate', 'cpanel', new \RuntimeException('server unreachable'));
        $this->assertSame(ModuleAction::STATUS_OPEN, $open->fresh()->status);

        try {
            (new DeleteService)->handle($service->fresh());
        } catch (DisplayException) {
            // Refusing while the termination is open is one acceptable answer.
        }

        $this->assertTrue(
            Service::query()->whereKey($service->id)->exists() || ModuleAction::query()->whereKey($open->id)->exists(),
            'the open termination (an account still live on the server) vanished with the service'
        );
    }

    /** L-DATA-INTEGRITY-1, the model's own guard (a bulk delete, code): refused with the coupon named, nothing cascaded. */
    public function test_a_product_named_in_a_coupon_is_refused_by_the_model_too(): void
    {
        $product = $this->createProduct()->product;
        $coupon = $this->coupon();
        $coupon->requiredProducts()->attach($product->id);

        try {
            $product->delete();
            $this->fail('a product a coupon names is not deleted');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('LAUNCH50', $e->getMessage());
        }
        $this->assertNotNull($product->fresh());
        $this->assertSame([$product->id], $coupon->fresh()->requiredProducts->pluck('id')->all());

        $coupon->requiredProducts()->detach();
        $product->delete();
        $this->assertNull($product->fresh(), 'taken out of the coupon, it can go');
    }

    /** L-DATA-INTEGRITY-2: once staff close the open termination, the delete goes through. */
    public function test_an_ended_service_is_deleted_once_its_termination_is_closed(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_CANCELLED, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => now()->addMonth(),
        ]);
        $open = ModuleAction::recordFailure($service, 'terminate', 'cpanel', new \RuntimeException('server unreachable'));

        try {
            (new DeleteService)->handle($service->fresh());
            $this->fail('refused while the termination is open');
        } catch (DisplayException $e) {
            $this->assertSame(__('services.delete_open_task'), $e->getMessage());
        }
        $this->assertSame(ModuleAction::STATUS_OPEN, $open->fresh()->status);

        $open->update(['status' => ModuleAction::STATUS_RESOLVED, 'resolved_at' => now()]);
        (new DeleteService)->handle($service->fresh());
        $this->assertNull(Service::query()->find($service->id));
    }

    /** An open failure of another action (a create that never made anything) does not hold the delete back. */
    public function test_an_open_create_failure_does_not_hold_the_delete(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_PENDING, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ]);
        ModuleAction::recordFailure($service, 'create', 'cpanel', new \RuntimeException('server unreachable'));

        (new DeleteService)->handle($service->fresh());

        $this->assertNull(Service::query()->find($service->id));
    }

    /** An addon's termination is a task for staff on its parent's machine: deleting it with terminate keeps it, terminated. */
    public function test_deleting_an_addon_with_terminate_keeps_its_by_hand_task(): void
    {
        $client = User::factory()->create()->client();
        $main = $this->createProduct();
        $parent = Service::factory()->create([
            'client_id' => $client->id, 'plan_id' => $main->plan->id, 'product_id' => $main->product->id, 'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => now()->addMonth(), 'domain' => 'vm.example.test',
        ]);
        $extra = $this->createProduct();
        $extra->product->update(['name' => 'Extra IPv4', 'is_addon' => true]);
        $addon = Service::factory()->create([
            'client_id' => $client->id, 'plan_id' => $extra->plan->id, 'product_id' => $extra->product->id, 'parent_service_id' => $parent->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 3.00, 'quantity' => 1, 'expires_at' => now()->addMonth(),
        ]);

        try {
            (new DeleteService)->handle($addon->fresh(), true);
            $this->fail('kept while its by-hand task is open');
        } catch (DisplayException $e) {
            $this->assertStringContainsString(__('services.delete_open_task'), $e->getMessage());
        }

        $this->assertSame(Service::STATUS_TERMINATED, $addon->fresh()?->status);
        $this->assertTrue(ModuleAction::query()->open()->where('service_id', $addon->id)->where('action', 'terminate')
            ->where('subject', ModuleAction::SUBJECT_BY_HAND)->exists());
    }
}
