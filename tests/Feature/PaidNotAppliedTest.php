<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Paid but not applied (audit sweep BIL-9). The service is renewed after the payment's commit; a failure there (a
 * crash, a server error) left the invoice paid and the service unrenewed, and the cron then suspended the customer
 * who paid. Now the paid invoice keeps apply_pending_since until it is applied, and the cron applies it again first.
 * Only what MyCS marked paid: the WHMCS import and older rows (applied_at NULL) are never renewed again.
 */
class PaidNotAppliedTest extends TestCase
{
    use RefreshDatabase;

    private bool $failRenewal = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 0,
            'settings.credits_auto_use' => false]);
        Service::updating(function (Service $service) {
            if ($this->failRenewal && $service->isDirty('expires_at')) {
                throw new RuntimeException('the server went away');
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-15',
        ]);
    }

    private function renewal(Service $service): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-15',
            'created_at' => '2026-10-08']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);

        return $invoice;
    }

    /** Pays it with the work after commit failing, as a crash there would. */
    private function payFailingAfterCommit(Invoice $invoice): void
    {
        $this->failRenewal = true;
        try {
            (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'tx-' . $invoice->id);
        } catch (RuntimeException) {
            // the payment committed; its application did not
        }
        $this->failRenewal = false;
    }

    public function test_a_paid_invoice_whose_renewal_failed_is_applied_by_the_cron_and_the_customer_not_suspended(): void
    {
        $service = $this->service();
        $invoice = $this->renewal($service);
        $this->payFailingAfterCommit($invoice);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('2026-10-15', $service->fresh()->expires_at->toDateString(), 'not renewed');
        $this->assertNotNull($invoice->fresh()->apply_pending_since);

        Carbon::setTestNow('2026-10-20 03:30:00');
        $this->artisan('app:cron-job')->assertExitCode(0);

        $service->refresh();
        $this->assertSame(Service::STATUS_ACTIVE, $service->status, 'paid: not suspended');
        $this->assertTrue($service->expires_at->greaterThan(Carbon::parse('2026-10-15')), 'renewed');
        $this->assertNull($invoice->fresh()->apply_pending_since);
    }

    public function test_a_payment_applied_at_once_leaves_nothing_pending(): void
    {
        $service = $this->service();
        $invoice = $this->renewal($service);

        (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'tx-ok');

        $this->assertNull($invoice->fresh()->apply_pending_since);
        $this->assertTrue($service->fresh()->expires_at->greaterThan(Carbon::parse('2026-10-15')));
    }

    public function test_applying_again_renews_once(): void
    {
        $service = $this->service();
        $invoice = $this->renewal($service);
        (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'tx-once');
        $renewedTo = $service->fresh()->expires_at->toDateString();

        // As if the marker survived (a crash after the renewal, before it was cleared).
        DB::table('invoices')->where('id', $invoice->id)->update(['apply_pending_since' => '2026-10-20 02:00:00']);
        Carbon::setTestNow('2026-10-20 03:30:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame($renewedTo, $service->fresh()->expires_at->toDateString(), 'one payment, one period');
        $this->assertNull($invoice->fresh()->apply_pending_since);
    }

    public function test_a_payment_being_applied_right_now_is_left_to_finish(): void
    {
        $service = $this->service();
        $invoice = $this->renewal($service);
        $this->payFailingAfterCommit($invoice); // marked at 03:00

        Carbon::setTestNow('2026-10-20 03:05:00');
        $this->artisan('app:cron-job');

        $this->assertNotNull($invoice->fresh()->apply_pending_since, 'five minutes old: not yet');
    }

    public function test_imported_and_older_paid_invoices_are_never_applied_again(): void
    {
        $service = $this->service();
        $id = DB::table('invoices')->insertGetId(['client_id' => $service->client_id, 'status' => 'paid', 'currency_code' => 'USD',
            'due_at' => '2026-09-15', 'created_at' => '2026-09-08', 'updated_at' => '2026-09-08']);
        DB::table('invoice_items')->insert(['invoice_id' => $id, 'reference_type' => Service::class, 'reference_id' => $service->id,
            'description' => 'WHMCS renewal', 'quantity' => 1, 'price' => 10, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('services')->where('id', $service->id)->update(['expires_at' => '2026-11-15']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString());
    }

    public function test_an_invoice_no_longer_paid_is_not_applied(): void
    {
        $service = $this->service();
        $invoice = $this->renewal($service);
        $this->payFailingAfterCommit($invoice);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'unpaid']); // staff took the payment back

        Carbon::setTestNow('2026-10-20 03:30:00');
        $this->artisan('app:cron-job');

        $this->assertSame('2026-10-15', $service->fresh()->expires_at->toDateString());
    }

    public function test_a_pending_upgrade_on_a_paid_invoice_is_done_once(): void
    {
        $service = $this->service();
        $target = $this->createProduct();
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id]);
        $id = DB::table('invoices')->insertGetId(['client_id' => $service->client_id, 'status' => 'paid', 'currency_code' => 'USD',
            'due_at' => '2026-10-19', 'created_at' => '2026-10-19', 'updated_at' => '2026-10-19', 'apply_pending_since' => '2026-10-19 12:00:00']);
        DB::table('invoice_items')->insert(['invoice_id' => $id, 'reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id,
            'description' => 'Upgrade', 'quantity' => 1, 'price' => 5, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('invoice_transactions')->insert(['invoice_id' => $id, 'amount' => 5, 'status' => 'succeeded', 'transaction_id' => 'up-1',
            'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
        $this->assertSame($target->product->id, (int) $service->fresh()->product_id);
        $this->assertNull(Invoice::find($id)->apply_pending_since);
    }
}
