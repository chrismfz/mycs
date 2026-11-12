<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Livewire\Domains\Show as DomainShow;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\Domain;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S10-CLIENT-PORTAL-1, the fix's edges: «Do not charge automatically» is accepted only where its page offers it
 * (ItemPaymentAccess::offersChoice). A running service or a billed domain keeps the switch (also
 * ChargeByGatewayMethodTest::test_the_customer_turns_it_off_and_on_on_the_service_and_domain_pages); one that has not
 * started, or has ended, is told «not available» and stays as it is.
 */
class Sweep10ClientPortalFixesTest extends TestCase
{
    use RefreshDatabase;

    private function service(User $user, string $status): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
    }

    public function test_a_running_or_suspended_service_keeps_the_switch_both_ways(): void
    {
        $user = User::factory()->create();
        foreach ([Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED] as $status) {
            $service = $this->service($user, $status);
            $this->assertSame('recurring', $service->plan->type);

            $page = $this->livewireAs($user)->test(ServiceShow::class, ['service' => $service]);
            $page->call('toggleAutoCharge');
            $this->assertTrue($service->fresh()->no_auto_charge, $status);
            $page->call('toggleAutoCharge');
            $this->assertFalse($service->fresh()->no_auto_charge, $status);
        }
    }

    public function test_a_service_that_has_not_started_or_has_ended_is_told_not_available(): void
    {
        $user = User::factory()->create();
        foreach ([Service::STATUS_PENDING, Service::STATUS_CANCELLED] as $status) {
            $service = $this->service($user, $status);

            $this->livewireAs($user)->test(ServiceShow::class, ['service' => $service])->call('toggleAutoCharge')
                ->assertDispatched('notify', ['message' => __('services.payment_method_not_available'), 'type' => 'error']);

            $this->assertFalse((bool) $service->fresh()->no_auto_charge, $status);
        }
    }

    public function test_a_domain_keeps_the_switch_once_billed_and_not_before_it_is_registered(): void
    {
        $user = User::factory()->create();
        $billed = Domain::factory()->create(['client_id' => $user->client()->id, 'currency_code' => 'USD', 'status' => DomainStatus::Active]);
        $page = $this->livewireAs($user)->test(DomainShow::class, ['domain' => $billed]);
        $page->call('toggleAutoCharge');
        $this->assertTrue($billed->fresh()->no_auto_charge);
        $page->call('toggleAutoCharge');
        $this->assertFalse($billed->fresh()->no_auto_charge);

        $pending = Domain::factory()->create(['client_id' => $user->client()->id, 'currency_code' => 'USD', 'status' => DomainStatus::PendingRegister]);
        $this->livewireAs($user)->test(DomainShow::class, ['domain' => $pending])->call('toggleAutoCharge')
            ->assertDispatched('notify', ['message' => __('services.payment_method_not_available'), 'type' => 'error']);
        $this->assertFalse((bool) $pending->fresh()->no_auto_charge);
    }
}
