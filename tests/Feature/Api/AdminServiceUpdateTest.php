<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Admin API: moving a service to another product/plan (UpdateServiceRequest had a missing import). */
class AdminServiceUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['name' => 'test', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token), 'permissions' => ['admin.services.update']]);

        return $token;
    }

    public function test_service_can_move_to_a_plan_of_the_given_product(): void
    {
        $from = $this->createProduct();
        $to = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $from->product->id,
            'plan_id' => $from->plan->id,
        ]);

        $this->withToken($this->token())
            ->putJson(route('api.v1.admin.services.update', $service), ['product_id' => $to->product->id, 'plan_id' => $to->plan->id])
            ->assertOk();

        $this->assertSame($to->plan->id, $service->fresh()->plan_id);
    }

    public function test_plan_of_another_product_is_rejected(): void
    {
        $from = $this->createProduct();
        $to = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $from->product->id,
            'plan_id' => $from->plan->id,
        ]);

        $this->withToken($this->token())
            ->putJson(route('api.v1.admin.services.update', $service), ['product_id' => $to->product->id, 'plan_id' => $from->plan->id])
            ->assertUnprocessable();
    }
}
