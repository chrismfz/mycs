<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\CreateService;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Probes for review of fix/license-key-domain (Edit Service: hidden quantity / subscription id). */
class LicenseKeyDomainReviewTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function service(array $attrs = []): Service
    {
        $p = $this->createProduct();

        return Service::factory()->create(array_merge([
            'client_id' => User::factory()->create()->client()->id,
            'plan_id' => $p->plan->id, 'product_id' => $p->product->id,
            'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1,
        ], $attrs));
    }

    public function test_create_page_saves_the_quantity_typed(): void
    {
        $p = $this->createProduct();
        $client = User::factory()->create()->client();
        $this->livewireAs($this->staff())->test(CreateService::class)
            ->fillForm(['product_id' => $p->product->id, 'plan_id' => $p->plan->id, 'client_id' => $client->id, 'status' => 'pending',
                'quantity' => 3, 'currency_code' => 'USD', 'price' => 10])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame(3, (int) Service::query()->latest('id')->first()->quantity);
    }

    public function test_edit_keeps_hidden_quantity_and_subscription_id(): void
    {
        $s = $this->service(['quantity' => 1]);
        $this->livewireAs($this->staff())->test(EditService::class, ['record' => $s->getRouteKey()])
            ->assertFormFieldIsHidden('quantity')->assertFormFieldIsHidden('subscription_id')
            ->fillForm(['notes' => 'x'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(1, (int) $s->fresh()->quantity);
    }

    public function test_edit_shows_and_saves_quantity_above_one_and_a_set_subscription_id(): void
    {
        $s = $this->service(['quantity' => 3, 'subscription_id' => 'I-ABC']);
        $this->livewireAs($this->staff())->test(EditService::class, ['record' => $s->getRouteKey()])
            ->assertFormFieldIsVisible('quantity')->assertFormFieldIsVisible('subscription_id')
            ->fillForm(['quantity' => 4])->call('save')->assertHasNoFormErrors();
        $this->assertSame(4, (int) $s->fresh()->quantity);
        $this->assertSame('I-ABC', $s->fresh()->subscription_id);
    }
}
