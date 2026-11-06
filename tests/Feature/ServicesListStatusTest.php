<?php

namespace Tests\Feature;

use App\Livewire\Services\Index as ServicesIndex;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
        Carbon::setTestNow(Carbon::now()->addMinute()); // each its own created_at: the list is the newest first

        return Service::factory()->create(['client_id' => $this->user->client()->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'status' => $status, 'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'domain' => $domain, 'expires_at' => '2026-12-03']);
    }

    public function test_each_status_in_words_and_no_renewal_for_an_ended_one(): void
    {
        $active = $this->service(Service::STATUS_ACTIVE, 'active.example.gr');
        $suspended = $this->service(Service::STATUS_SUSPENDED, 'suspended.example.gr');
        $cancelled = $this->service(Service::STATUS_CANCELLED, 'cancelled.example.gr');

        $html = $this->livewireAs($this->user)->test(ServicesIndex::class)->html();

        $this->assertStringContainsString(__('services.statuses.cancelled'), $this->card($html, $cancelled));
        $this->assertStringNotContainsString(__('services.renews_in'), $this->card($html, $cancelled));
        $this->assertStringContainsString(__('services.statuses.suspended'), $this->card($html, $suspended));
        $this->assertStringContainsString(__('services.renews_in'), $this->card($html, $suspended), 'a suspended one renews once paid');
        $this->assertStringContainsString(__('services.statuses.active'), $this->card($html, $active));
        $this->assertStringContainsString(__('services.renews_in'), $this->card($html, $active));
    }

    /** Review of #619: an active one being cancelled says so, as its page does, and renews no more on either. */
    public function test_an_active_one_being_cancelled_says_so_and_no_renewal(): void
    {
        $ending = $this->service(Service::STATUS_ACTIVE, 'ending.example.gr');
        ServiceCancellation::query()->create(['service_id' => $ending->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);

        $card = $this->card($this->livewireAs($this->user)->test(ServicesIndex::class)->html(), $ending);
        $this->assertStringContainsString(__('services.statuses.cancellation_pending'), $card);
        $this->assertStringNotContainsString('>' . __('services.statuses.active') . '<', $card);
        $this->assertStringNotContainsString(__('services.renews_in'), $card);
        $this->livewireAs($this->user)->test(ServiceShow::class, ['service' => $ending->fresh()])
            ->assertSee(__('services.statuses.cancellation_pending'))->assertDontSee(__('services.renews_on') . ':');
    }

    /** One service's card on the list: from its link to the link's end, whatever the order. */
    private function card(string $html, Service $service): string
    {
        $card = Str::betweenFirst($html, 'href="' . route('services.show', $service) . '"', '</a>');
        $this->assertNotSame($html, $card, 'its card is on the list');

        return $card;
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
