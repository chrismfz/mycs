<?php

namespace Tests\Feature;

use App\Mail\ResentMail;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Sweep 3, review of the «notifications» fixes: what the fixes still leave open. */
class Rev3LNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        Queue::fake();
        config(['settings.mail_disable' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();
        $user = User::factory()->create();

        return Service::factory()->create($attributes + ['client_id' => $user->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1,
            'expires_at' => '2026-11-20']);
    }

    private function failedLog(int $clientId, string $key, string $type, int $id): EmailLog
    {
        $log = EmailLog::query()->create(['client_id' => $clientId, 'subject' => $key, 'to' => 'customer@example.com',
            'body' => '<p>pay or be suspended</p>', 'template_key' => $key, 'related_type' => $type, 'related_id' => $id,
            'trigger' => EmailLog::TRIGGER_AUTO]);
        DB::table('email_logs')->where('id', $log->id)->update(['status' => EmailLog::STATUS_FAILED, 'sending_at' => null]);

        return $log;
    }

    /**
     * REV3-LN-1 (L-NOTIFICATIONS-4): a hold's warning («your hold ends, pay or the service is suspended on D») of a service
     * whose renewal was paid since: the service is still active (the fix's only check), nothing is owed, and the batch
     * resend still tells the customer they will be suspended.
     */
    public function test_resend_failed_skips_a_hold_warning_of_a_service_paid_since(): void
    {
        // Paid since: its next due date moved a period on, no open invoice left; the hold stays on file (nothing clears it).
        $service = $this->service(['expires_at' => '2026-12-20', 'suspend_hold_until' => '2026-10-18']);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-10']);
        $invoice->items()->create(['description' => 'Renewal', 'quantity' => 1, 'price' => 10, 'reference_type' => Service::class, 'reference_id' => $service->id]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => now()]);
        $this->failedLog($service->client_id, 'service_hold_ending', $service->getMorphClass(), $service->id);
        Mail::fake();

        $this->artisan('app:mail-resend-failed')->assertSuccessful();

        Mail::assertNotQueued(ResentMail::class);
    }

    /**
     * REV3-LN-2 (L-NOTIFICATIONS-4): an overdue notice of an invoice staff gave more time since (its due date moved after
     * today): still unpaid, so the fix resends «your invoice is overdue, pay by X or be suspended» for one not due yet.
     */
    public function test_resend_failed_skips_an_overdue_notice_of_an_invoice_no_longer_overdue(): void
    {
        $service = $this->service();
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-30']);
        $invoice->items()->create(['description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
        $this->failedLog($service->client_id, 'invoice_overdue', $invoice->getMorphClass(), $invoice->id);
        Mail::fake();

        $this->artisan('app:mail-resend-failed')->assertSuccessful();

        Mail::assertNotQueued(ResentMail::class);
    }
}
