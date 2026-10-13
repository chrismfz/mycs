<?php

namespace Tests\Feature;

use App\Jobs\Server\TerminateJob;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Cancellation requests «at the end of the billing period» are carried out (audit sweep BIL-1, SVC-13). They were
 * never processed: the service ran on unpaid, was suspended as if the customer had not paid (dunning marker and
 * email) and terminated months later; and a renewal invoice paid after the request renewed it.
 */
class EndOfPeriodCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $expires, string $status = 'active', ?int $stock = null): Service
    {
        $product = $this->createProduct();
        $product->product->update(['stock' => $stock]);

        return Service::factory()->create([
            'user_id' => User::factory()->create()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ]);
    }

    private function openInvoiceFor(Service $service): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $service->user_id, 'status' => 'pending', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);

        return $invoice;
    }

    private function request(Service $service, string $type = 'end_of_period'): void
    {
        ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => $type, 'reason' => 'Leaving']);
    }

    public function test_the_service_ends_on_its_due_date_as_requested(): void
    {
        Queue::fake(); // the request's listener and the server job: what the cron does is under test
        $service = $this->service('2026-10-20', stock: 5);
        $invoice = $this->openInvoiceFor($service);
        $this->request($service);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $service->refresh();
        $this->assertSame(Service::STATUS_CANCELLED, $service->status);
        $this->assertNull($service->dunning_suspended_at, 'the customer left, they did not fail to pay');
        Queue::assertPushed(TerminateJob::class, fn ($job) => $job->service->id === $service->id);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame(6, $service->product->fresh()->stock);
    }

    public function test_nothing_happens_before_the_due_date(): void
    {
        Queue::fake();
        $service = $this->service('2026-10-25');
        $this->request($service);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        Queue::assertNotPushed(TerminateJob::class);
    }

    public function test_a_service_already_suspended_for_non_payment_ends_too(): void
    {
        Queue::fake();
        $service = $this->service('2026-10-10', status: 'suspended');
        $service->forceFill(['dunning_suspended_at' => now()->subDays(5)])->save();
        $this->request($service);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        Queue::assertPushed(TerminateJob::class);
    }

    public function test_the_request_cancels_the_open_invoices_so_nothing_renews_the_service(): void
    {
        $service = $this->service('2026-10-25');
        $invoice = $this->openInvoiceFor($service);

        $this->request($service); // its listener runs (sync queue)

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'it runs until its due date');
    }

    public function test_an_immediate_cancellation_gives_back_stock_also_from_zero(): void
    {
        Queue::fake([TerminateJob::class]);
        $service = $this->service('2026-10-25', stock: 0);

        $this->request($service, 'immediate');

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(1, $service->product->fresh()->stock);
    }

    public function test_paying_the_renewal_after_the_request_does_not_renew_the_service(): void
    {
        $service = $this->service('2026-10-25');
        $invoice = $this->openInvoiceFor($service);
        $this->request($service); // cancels the open invoice

        (new RecordPaymentService)->handle($invoice->fresh(), null, 10.00, transactionId: 'late-transfer');

        $this->assertSame('2026-10-25', $service->fresh()->expires_at->toDateString(), 'not renewed past the requested end');
        $this->assertNotSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }
}
