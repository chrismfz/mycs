<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Exceptions\DisplayException;
use App\Models\ApiKey;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * billing-cursor §5 (owner 2026-10-23): a paying service on a plan billed every cycle is never moved to a free or
 * one-time plan without staff saying it is never billed again; a plan services pay for does not become free under them.
 */
class PlanBecomesFreeLockTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private Plan $free;

    protected function setUp(): void
    {
        parent::setUp();
        $product = $this->createProduct();
        $product->plan->update(['type' => 'recurring']);
        $this->free = (new Plan)->forceFill(['name' => 'Free', 'type' => 'free', 'priceable_id' => $product->product->id, 'priceable_type' => Product::class]);
        $this->free->save();
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addMonth()]);
    }

    public function test_staff_must_say_it_is_never_billed_again(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $original = $this->service->plan_id;

        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->fillForm(['plan_id' => $this->free->id])->call('save')->assertNotified(__('Not saved'));
        $this->assertSame($original, $this->service->fresh()->plan_id);

        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->fillForm(['plan_id' => $this->free->id, 'ends_billing' => true])->call('save')->assertHasNoFormErrors();
        $this->assertSame($this->free->id, $this->service->fresh()->plan_id);
    }

    public function test_the_api_too(): void
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 't', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $token), 'permissions' => ['admin.services.update']]);

        $this->withToken($token)->putJson(route('api.v1.admin.services.update', $this->service), ['plan_id' => $this->free->id])->assertStatus(422);
        $this->withToken($token)->putJson(route('api.v1.admin.services.update', $this->service), ['plan_id' => $this->free->id, 'ends_billing' => true])->assertSuccessful();
        $this->assertSame($this->free->id, $this->service->fresh()->plan_id);
    }

    public function test_a_plan_services_pay_for_does_not_become_free_under_them(): void
    {
        $plan = $this->service->plan;
        $this->assertInstanceOf(DisplayException::class, rescue(fn () => $plan->update(['type' => 'free']), fn ($e) => $e, false));
        $this->assertSame('recurring', $plan->fresh()->type);

        $this->service->update(['status' => Service::STATUS_CANCELLED]);
        $plan->fresh()->update(['type' => 'free']);
        $this->assertSame('free', $plan->fresh()->type, 'nobody pays for it any more');
    }
}
