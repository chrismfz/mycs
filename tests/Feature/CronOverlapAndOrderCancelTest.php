<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BIL-16: one cron run at a time (a manual run overlapping the scheduled one cancelled orders and gave stock back
 * twice), and no run time in the hour the clock changes. BIL-12: the auto-cancel of unpaid orders took any pending
 * service with no paid invoice: one staff created without an invoice, an order partly paid.
 */
class CronOverlapAndOrderCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
        config(['settings.cronjob_order_cancel' => 7]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(bool $withInvoice = true): array
    {
        $product = $this->createProduct(['stock' => 5]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'pending', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => null,
            'created_at' => '2026-10-01',
        ]);
        $invoice = null;
        if ($withInvoice) {
            $invoice = Invoice::factory()->create(['user_id' => $service->user_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-01']);
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Order', 'quantity' => 1, 'price' => 10]);
        }

        return [$service, $invoice];
    }

    private function money(Invoice $invoice, InvoiceTransactionStatus $status, float $amount = 4): void
    {
        DB::table('invoice_transactions')->insert(['invoice_id' => $invoice->id, 'amount' => $amount, 'status' => $status->value,
            'transaction_id' => 'tx-' . $invoice->id . $status->value, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_an_unpaid_order_is_cancelled(): void
    {
        [$service, $invoice] = $this->order();

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame(6, (int) $service->product->fresh()->stock);
    }

    public function test_a_pending_service_staff_created_without_an_invoice_is_left_alone(): void
    {
        [$service] = $this->order(withInvoice: false);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
    }

    public function test_an_order_with_money_on_it_is_left_to_staff(): void
    {
        [$partly, $invoice] = $this->order();
        $this->money($invoice, InvoiceTransactionStatus::Succeeded);
        [$processing, $sepa] = $this->order();
        $this->money($sepa, InvoiceTransactionStatus::Processing, 10);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_PENDING, $partly->fresh()->status, 'part paid');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(Service::STATUS_PENDING, $processing->fresh()->status, 'a payment on its way');
    }

    public function test_a_run_does_not_start_while_another_runs(): void
    {
        [$service] = $this->order();
        $lock = Cache::lock('app:cron-job', 60);
        $this->assertTrue($lock->get());

        $this->artisan('app:cron-job')->assertExitCode(1);
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status, 'nothing done');

        $lock->release();
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
    }

    public function test_a_failed_run_releases_the_lock(): void
    {
        [$service] = $this->order();
        Service::updating(function () {
            throw new \RuntimeException('down');
        });
        try {
            $this->artisan('app:cron-job');
        } catch (\Throwable) {
        }

        $this->assertTrue(Cache::lock('app:cron-job', 60)->get(), 'released');
    }

    public function test_the_run_time_is_not_in_the_hour_the_clock_changes(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = User::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        config(['settings.system_email_address' => 'billing@example.test']);

        Livewire::actingAs($owner)->test(Settings::class)->set('data.cronjob_time', '03:30')->call('save')->assertHasErrors('data.cronjob_time');
        Livewire::actingAs($owner)->test(Settings::class)->set('data.cronjob_time', '02:30')->call('save')->assertHasNoErrors();
    }
}
