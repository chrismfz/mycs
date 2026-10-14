<?php

namespace Tests\Feature;

use App\Events\ServiceCancellation\Created;
use App\Exceptions\DisplayException;
use App\Listeners\CancellationCreatedListener;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Plans and products in use are never deleted (audit sweep BIL-6, as WHMCS). Deleting one set the service's plan or
 * product to null (the cron failed on it every day, a payment could not renew it) and deleted the upgrades on it with
 * their line still payable (A4 of #71). And a cancellation «at the end of the period» cancels pending upgrades at once
 * (CR-10 of #71).
 */
class PlanProductDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 7,
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(array_merge([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-11-15',
        ], $attributes));
    }

    public function test_a_plan_or_product_a_service_uses_is_not_deleted(): void
    {
        $service = $this->service(['status' => 'cancelled']); // history too

        foreach ([$service->plan, $service->product] as $model) {
            try {
                $model->delete();
                $this->fail('deleted while in use');
            } catch (DisplayException) {
            }
            $this->assertNotNull($model->fresh());
        }
        $this->assertNotNull($service->fresh()->plan_id);
    }

    /** A service is still deleted as before (a record staff remove, e.g. an account terminated straight from WHM). */
    public function test_a_service_is_still_deleted_whatever_its_status(): void
    {
        foreach (['active', 'suspended', 'cancelled'] as $status) {
            $service = $this->service(['status' => $status]);
            $target = $this->createProduct();
            ServiceUpgrade::query()->create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id,
                'invoice_id' => Invoice::factory()->create(['client_id' => $service->client_id, 'currency_code' => 'USD'])->id]);

            $service->delete();

            $this->assertNull(Service::find($service->id), $status);
            $this->assertNotNull(Plan::find($service->plan_id), 'its plan stays in the catalog');
        }
    }

    public function test_the_database_refuses_it_too(): void
    {
        $service = $this->service();

        $this->expectException(QueryException::class);
        DB::table('plans')->where('id', $service->plan_id)->delete(); // around the model: the foreign key holds
    }

    public function test_a_plan_a_pending_upgrade_targets_is_not_deleted(): void
    {
        $service = $this->service();
        $target = $this->createProduct();
        ServiceUpgrade::query()->create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id,
            'invoice_id' => Invoice::factory()->create(['client_id' => $service->client_id, 'currency_code' => 'USD'])->id]);

        $this->expectException(DisplayException::class);
        Plan::find($target->plan->id)->delete();
    }

    public function test_an_unused_plan_is_deleted(): void
    {
        $product = $this->createProduct();

        Plan::find($product->plan->id)->delete();

        $this->assertNull(Plan::find($product->plan->id));
    }

    public function test_the_cron_reports_a_service_without_a_plan_and_bills_the_others(): void
    {
        $orphan = $this->service(['expires_at' => '2026-10-22']);
        Schema::disableForeignKeyConstraints();
        DB::table('services')->where('id', $orphan->id)->update(['plan_id' => null]); // as a delete before BIL-6 left it
        Schema::enableForeignKeyConstraints();
        $other = $this->service(['expires_at' => '2026-10-22']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(1, $other->client()->invoices()->count(), 'the others are billed');
        $this->assertSame(0, $orphan->invoices()->count());
        $this->assertTrue(EmailLog::query()->where('body', 'like', "%Service #{$orphan->id} has no plan%")->exists(), 'staff are told');
    }

    public function test_a_cancellation_at_the_end_of_the_period_cancels_pending_upgrades_at_once(): void
    {
        $service = $this->service();
        $target = $this->createProduct();
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-20']);
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id,
            'invoice_id' => $invoice->id]);
        $invoice->items()->create(['reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 5]);

        $cancellation = ServiceCancellation::withoutEvents(fn () => ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'leaving']));
        (new CancellationCreatedListener)->handle(new Created($cancellation)); // queued: run here

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'it runs to the end of its period');
        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }
}
