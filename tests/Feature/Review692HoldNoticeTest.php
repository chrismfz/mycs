<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Code review of #692 (an addon follows its parent's hold), F5: the hold-ending notice (DunningRules, $holdDay) is not
 * sent for an addon whose own hold ends while its parent's holds it longer. Suspend Days 2. Fake data only.
 */
class Review692HoldNoticeTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 03:00:00');
        Queue::fake();
        Mail::fake();
        (new EmailTemplateSeeder)->run();
        config([
            'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 60, 'settings.cronjob_invoice' => 7,
            'settings.cronjob_suspend_requires_invoice' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
        ]);
        $this->client = User::factory()->create()->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Overdue since 11-01 with an unpaid renewal, held until $hold. */
    private function held(?string $hold, ?Service $parent = null): Service
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $this->client->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
            'expires_at' => '2026-11-01 00:00:00', 'suspend_hold_until' => $hold, 'parent_service_id' => $parent?->id]);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => '2026-11-01', 'created_at' => '2026-10-25']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);

        return $service;
    }

    private function noticesFor(Service $service): int
    {
        return EmailLog::query()->where('template_key', 'service_hold_ending')->where('body', 'like', "%{$service->product->name}%")->count();
    }

    public function test_an_addon_whose_hold_ends_under_a_parent_held_longer_gets_no_hold_ending_notice(): void
    {
        $parent = $this->held('2026-11-30');
        $addon = $this->held('2026-11-20', parent: $parent);
        $alone = $this->held('2026-11-20'); // the control: the same hold, no parent
        EmailLog::query()->delete();

        Carbon::setTestNow('2026-11-19 03:00:00'); // its hold ends tomorrow
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(1, EmailLog::query()->where('template_key', 'service_hold_ending')->count(), 'only the service with no parent is told');
        $this->assertSame(Carbon::parse('2026-11-20')->toDateString(), $alone->fresh()->suspend_hold_notice_for?->toDateString(), 'precondition: the control is told');
        $this->assertNull($addon->fresh()->suspend_hold_notice_for, 'the addon is held by its parent past its own hold: nothing ends tomorrow');
    }
}
