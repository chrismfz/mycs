<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The staff email of what the daily cron suspended and terminated for non-payment (as WHMCS's «Cron Job Activity»):
 * one row per service, with a link to it, so staff unsuspend or hold («Do Not Suspend Until») the ones to keep.
 */
class SuspensionDigestTest extends TestCase
{
    use RefreshDatabase;

    private const SUBJECT = 'Cron Job: services %';

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

    private function unpaidInvoice(Service $service, string $due): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => $due,
            'created_at' => Carbon::parse($due)->subWeek()]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);

        return $invoice;
    }

    /** @return Collection<int, EmailLog> */
    private function digests()
    {
        return EmailLog::query()->where('subject', 'like', self::SUBJECT)->get();
    }

    public function test_one_email_lists_every_service_suspended(): void
    {
        $first = $this->service(['expires_at' => '2026-10-15', 'domain' => 'first.example']);
        $invoice = $this->unpaidInvoice($first, '2026-10-15');
        $second = $this->service(['expires_at' => '2026-10-17']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $first->fresh()->status);
        $this->assertSame(Service::STATUS_SUSPENDED, $second->fresh()->status);
        $digests = $this->digests();
        $this->assertCount(1, $digests);
        $mail = $digests->first();
        $this->assertSame('Cron Job: services 2 suspended', $mail->subject);
        $this->assertSame('staff@example.test', $mail->to);
        foreach ([$first, $second] as $service) {
            $this->assertStringContainsString("/admin/services/services/{$service->id}/edit", $mail->body);
            $this->assertStringContainsString(e($service->client->name), $mail->body);
        }
        $this->assertStringContainsString('first.example', $mail->body);
        $this->assertStringContainsString('#' . ($invoice->number ?? $invoice->id), $mail->body, 'its unpaid invoice');
        $this->assertStringContainsString('due 2026-10-15', $mail->body);
    }

    public function test_no_email_on_a_day_nothing_is_suspended(): void
    {
        $this->service(['expires_at' => '2026-10-19']); // within Suspend Days

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertCount(0, $this->digests());
    }

    public function test_a_held_service_is_not_suspended_nor_listed(): void
    {
        $held = $this->service(['expires_at' => '2026-10-10', 'suspend_hold_until' => '2026-10-25']);
        $due = $this->service(['expires_at' => '2026-10-10']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_ACTIVE, $held->fresh()->status);
        $body = $this->digests()->sole()->body;
        $this->assertStringContainsString("/services/{$due->id}/edit", $body);
        $this->assertStringNotContainsString("/services/{$held->id}/edit", $body);
    }

    public function test_the_setting_off_sends_none(): void
    {
        config(['settings.cronjob_suspension_digest' => false]);
        $service = $this->service(['expires_at' => '2026-10-10']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
        $this->assertCount(0, $this->digests());
    }

    public function test_terminations_are_listed_and_a_service_once(): void
    {
        // Suspended days ago, Termination Days passed: terminated today.
        $old = $this->service(['expires_at' => '2026-10-01', 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-10-04 03:00:00']);
        // «Minimum days suspended» 0: suspended and terminated in the same run, listed once, as terminated.
        config(['settings.cronjob_terminate_min_suspended_days' => 0]);
        $both = $this->service(['expires_at' => '2026-09-30']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_TERMINATED, $old->fresh()->status);
        $this->assertSame(Service::STATUS_TERMINATED, $both->fresh()->status);
        $mail = $this->digests()->sole();
        $this->assertSame('Cron Job: services 2 terminated', $mail->subject);
        $this->assertStringContainsString('Terminated for non-payment (2)', $mail->body);
        $this->assertStringNotContainsString('Suspended for non-payment', $mail->body);
    }

    public function test_what_staff_or_customers_typed_is_escaped(): void
    {
        $service = $this->service(['expires_at' => '2026-10-10', 'domain' => '<script>x</script>.example']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $body = $this->digests()->sole()->body;
        $this->assertStringNotContainsString('<script>x</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
    }
}
