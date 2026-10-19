<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Code review of ec028f5 (suspension digest): failing tests for what was found. */
class SuspensionDigestReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 0,
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(array_merge([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ], $attributes));
    }

    /**
     * R1: the command object is reused between runs (the comment in runSteps() says so: «a test, a long-lived worker»),
     * and $suspended / $terminated are not reset with $failures / $notices: the next run mails yesterday's services again.
     */
    public function test_a_second_run_does_not_list_the_previous_runs_services(): void
    {
        $first = $this->service(['expires_at' => '2026-10-15']);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_SUSPENDED, $first->fresh()->status);

        Carbon::setTestNow('2026-10-21 03:00:00');
        $second = $this->service(['expires_at' => '2026-10-17']);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_SUSPENDED, $second->fresh()->status);

        $last = EmailLog::query()->where('subject', 'like', 'Cron Job: services %')->orderByDesc('id')->first();
        $this->assertSame('Cron Job: services 1 suspended', $last->subject, 'only the service of this run');
        $this->assertStringNotContainsString("/services/{$first->id}/edit", $last->body);
    }

    /**
     * R2: Service::invoices() is a HasManyThrough over invoice_items: an invoice with two lines of the service (renewal
     * + an addon/setup line, a WHMCS import) comes back twice, and the digest lists it twice (other callers ->unique('id')).
     */
    public function test_an_invoice_with_two_lines_of_the_service_is_listed_once(): void
    {
        $service = $this->service(['expires_at' => '2026-10-15']);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD',
            'due_at' => '2026-10-15', 'created_at' => '2026-10-08']);
        foreach (['Renewal', 'Setup'] as $description) {
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => $description, 'quantity' => 1, 'price' => 10]);
        }

        $this->artisan('app:cron-job')->assertExitCode(0);

        $body = EmailLog::query()->where('subject', 'like', 'Cron Job: services %')->sole()->body;
        $this->assertSame(1, substr_count($body, '#' . ($invoice->number ?? $invoice->id) . ' '), 'the invoice once');
    }
}
