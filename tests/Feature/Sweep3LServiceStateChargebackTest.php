<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\ProcessPaidInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * Sweep 3, lens «l-service-state» (adversarial verifier), finding L-SERVICE-STATE-1 (fixed with lens l-ledger-invariant:
 * the same root, a reopened invoice whose lines stay applied). Copied from adv/sweep3-l-service-state @ e40385b8.
 */
class Sweep3LServiceStateChargebackTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-10-12 10:00:00');
        config([
            'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14,
            'settings.cronjob_terminate_min_suspended_days' => 1, 'settings.cronjob_invoice' => 7,
            'settings.credits_auto_use' => false,
        ]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function stripe(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        foreach (['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => 'whsec_test'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        (new Stripe(['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => 'whsec_test']))->boot();
        Http::fake();
    }

    private function cronUntil(string $day): void
    {
        while (now()->toDateString() < $day) {
            Carbon::setTestNow(now()->addDay());
            $this->artisan('app:cron-job')->assertExitCode(0);
        }
    }

    /**
     * L-SERVICE-STATE-1. A paid renewal charged back is unpaid again, its line still applied. Its open invoice keeps the
     * next period from being billed; the service is suspended past Suspend Days; the customer then pays that invoice
     * (all they were ever billed) and the payment neither unsuspends it (the line was applied already) nor lets the next
     * period be billed (paidSinceSuspended reads the old applied_at). Termination Days later the cron terminates it,
     * with nothing ever open for the customer to pay.
     */
    public function test_l_service_state_1_a_chargeback_paid_again_does_not_end_in_termination_with_nothing_billed(): void
    {
        $this->stripe();
        $created = $this->createProduct();
        Price::query()->where('plan_id', $created->plan->id)->update(['price' => 10.00]);
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $created->plan->id, 'product_id' => $created->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-10-15', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ]);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-10-15']);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10, 'period_start' => '2026-10-15', 'period_end' => '2026-11-15']);

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, transactionId: 'pi_l1', currency: 'usd');
        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString(), 'renewed by the payment');

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_l1', 'dp_l1', 10.00, 'usd'));
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'owed again');

        $this->cronUntil('2026-11-19'); // expires_at + 4 > Suspend Days 2
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'suspended for the unpaid (charged-back) invoice');

        // The customer pays the only invoice they were sent.
        ExtensionHelper::addPayment($invoice->id, null, 10.00, null, 'bank-l1');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        // Corrected by the fixer: the verifier asserted «not terminated» with the next period still unpaid, which the
        // dunning rules terminate whatever was billed. The bug is that the next period was never billed (nothing to pay):
        // billed now, paid, the service is back and stays.
        $this->cronUntil('2026-11-20');
        $next = $service->fresh()->invoiceItems()->whereDate('period_start', '2026-11-15')->first();
        $this->assertNotNull($next, 'the payment of the charged-back invoice must let the next period be billed: else nothing is open to pay');
        ExtensionHelper::addPayment($next->invoice_id, null, 10.00, null, 'bank-l1-next');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'paid up: back');
        $this->assertSame('2026-12-15', $service->fresh()->expires_at->toDateString());

        $this->cronUntil('2026-12-01'); // expires_at + 16 > Termination Days 14
        $this->assertNotSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'terminated although the customer paid everything billed');
    }

    /** The fix's other side: a replay of the same payment (the cron's, BIL-9) applies nothing again, suspended or not. */
    public function test_a_replay_of_an_applied_renewal_changes_nothing(): void
    {
        $created = $this->createProduct();
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $created->plan->id, 'product_id' => $created->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-10-15', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ]);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-10-15']);
        $line = $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10, 'period_start' => '2026-10-15', 'period_end' => '2026-11-15']);
        ExtensionHelper::addPayment($invoice->id, null, 10.00, null, 'bank-replay');
        $applied = $line->fresh()->applied_at;
        $this->assertNotNull($applied);

        // Suspended by staff for non-payment of something else meanwhile; the invoice's marker kept (a failure after it).
        Carbon::setTestNow(now()->addHour());
        $service->forceFill(['status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => now()])->saveQuietly();
        Invoice::query()->whereKey($invoice->id)->update(['apply_pending_since' => $applied->subMinute()]);
        app(ProcessPaidInvoiceService::class)->handle($invoice->fresh());

        $this->assertEquals($applied, $line->fresh()->applied_at, 'a replay: the line keeps when it was applied');
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'a replay unsuspends nothing');
        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString());
    }
}
