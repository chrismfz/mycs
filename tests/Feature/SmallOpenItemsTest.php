<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource;
use App\Exceptions\NothingToDoOnServer;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\EmailLog;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Small items left open by the reviews of #411 (owner 2026-10-24). */
class SmallOpenItemsTest extends TestCase
{
    use RefreshDatabase;

    private function service(string $status): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => $status, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
    }

    /** Paid before the worker reached its suspension: no «unsuspended» mail for a suspension that never happened. */
    public function test_a_suspension_the_payment_overtook_sends_no_unsuspended_mail(): void
    {
        config(['settings.mail_disable' => false]);
        $service = $this->service(Service::STATUS_ACTIVE); // the cron set it suspended, the payment set it active again
        (new SuspendJob($service))->handle();               // the worker: active, skipped
        (new UnsuspendJob($service))->handle();             // the payment's: nothing to undo

        $this->assertFalse(EmailLog::query()->whereIn('template_key', ['server_suspended', 'server_unsuspended'])->exists(), 'neither «suspended» nor «unsuspended»');
        $this->assertNull(Cache::get(SuspendJob::neverSuspendedKey($service->id)), 'used once');
    }

    /** A real suspension afterwards (staff, from the service page) makes its unsuspension a real one again. */
    public function test_a_real_suspension_clears_the_mark(): void
    {
        $service = $this->service(Service::STATUS_ACTIVE);
        (new SuspendJob($service))->handle();
        $this->assertTrue(Cache::get(SuspendJob::neverSuspendedKey($service->id)));

        try {

            ExtensionHelper::suspendServer($service->fresh());

        } catch (NothingToDoOnServer) {

            // no module in the test: the mark goes all the same, before the server is asked

        }

        $this->assertNull(Cache::get(SuspendJob::neverSuspendedKey($service->id)));
    }

    /** The cancel's choices say what happens to the service (owner 2026-10-24). */
    public function test_the_cancel_choices_say_what_happens_to_the_service(): void
    {
        $choices = InvoiceResource::cancelPeriods();

        $this->assertSame(['auto', 'keep', 'give_up'], array_keys($choices));
        $this->assertStringContainsString('suspended on its date', $choices['auto']);
        $this->assertStringStartsWith('Re-issue', $choices['keep']);
    }

    public function test_the_setting_nothing_reads_is_gone(): void
    {
        DB::table('settings')->insert(['key' => 'cronjob_invoice_reminder', 'value' => '3', 'type' => 'string']);

        (require database_path('migrations/2027_01_05_000000_forget_cronjob_invoice_reminder.php'))->up();

        $this->assertFalse(DB::table('settings')->where('key', 'cronjob_invoice_reminder')->exists());
    }
}
