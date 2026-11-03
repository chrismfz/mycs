<?php

namespace Tests\Feature;

use App\Livewire\Services\Index as ServicesIndex;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The services list says each one's status in words (owner 2026-11-03: a suspended and a cancelled one had the same grey
 * icon), and an ended one says no renewal (a cancelled one said «Renews in 4 weeks»), on the list and on its page.
 */
class ServicesListStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $status, string $domain): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => $this->user->client()->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'status' => $status, 'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'domain' => $domain, 'expires_at' => '2026-12-03']);
    }

    public function test_each_status_in_words_and_no_renewal_for_an_ended_one(): void
    {
        $active = $this->service(Service::STATUS_ACTIVE, 'active.example.gr');
        $suspended = $this->service(Service::STATUS_SUSPENDED, 'suspended.example.gr');
        $cancelled = $this->service(Service::STATUS_CANCELLED, 'cancelled.example.gr');

        $html = $this->livewireAs($this->user)->test(ServicesIndex::class)
            ->assertSeeInOrder(['cancelled.example.gr', __('services.statuses.cancelled')])
            ->assertSeeInOrder(['suspended.example.gr', __('services.statuses.suspended')])
            ->assertSeeInOrder(['active.example.gr', __('services.statuses.active')])->html();

        // The newest first: the cancelled one's card ends where the suspended one's begins.
        $card = fn (Service $service) => substr($html, strpos($html, route('services.show', $service)), 2500);
        $this->assertStringNotContainsString(__('services.renews_in'), strstr($card($cancelled), 'suspended.example.gr', true) ?: $card($cancelled));
        $this->assertStringContainsString(__('services.renews_in'), $card($suspended));
        $this->assertStringContainsString(__('services.renews_in'), $card($active));
    }

    public function test_an_ended_services_page_says_no_renewal(): void
    {
        $cancelled = $this->service(Service::STATUS_CANCELLED, 'cancelled.example.gr');
        $active = $this->service(Service::STATUS_ACTIVE, 'active.example.gr');

        $this->livewireAs($this->user)->test(ServiceShow::class, ['service' => $cancelled])->assertDontSee(__('services.renews_on') . ':');
        $this->livewireAs($this->user)->test(ServiceShow::class, ['service' => $active])->assertSee(__('services.renews_on') . ':');
    }

    public function test_greek_words(): void
    {
        app()->setLocale('el');
        $this->service(Service::STATUS_CANCELLED, 'cancelled.example.gr');
        $this->service(Service::STATUS_SUSPENDED, 'suspended.example.gr');

        $this->livewireAs($this->user)->test(ServicesIndex::class)->assertSee('Ακυρωμένη')->assertSee('Σε αναστολή');
    }
}
