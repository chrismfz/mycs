<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Livewire\Invoices\Show as InvoiceShow;
use App\Livewire\Services\Cancel;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Paymenter\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * Adversarial review of feat/service-status-terminated (terminated ≠ cancelled).
 *
 * T1..T5 failed on 53a3522 (staff «Terminate» only flipped the status; the invoice page spent credit on an ended
 * service; money-check missed a paid upgrade of one) and pass after the fixes. Where a test needs an invoice LEFT open
 * on a terminated service (one holding gateway money stays open for staff), it sets the status directly. Findings ids
 * (T1..) match the review table in the PR.
 */
class ServiceTerminatedAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 10:00:00');
        Mail::fake();
        config([
            'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14,
            'settings.cronjob_terminate_min_suspended_days' => 1, 'settings.cronjob_invoice' => 7,
            'settings.credits_enabled' => true, 'settings.credits_auto_use' => true,
        ]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = [], array $product = []): Service
    {
        $created = $this->createProduct($product);
        Price::query()->where('plan_id', $created->plan->id)->update(['price' => 10.00]);

        return Service::factory()->create(array_merge([
            'user_id' => $this->user->id, 'plan_id' => $created->plan->id, 'product_id' => $created->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-10-15', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ], $attributes));
    }

    private function renewal(Service $service, string $start = '2026-10-15', string $end = '2026-11-15'): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $start]);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10, 'period_start' => $start, 'period_end' => $end]);

        return $invoice->fresh();
    }

    private function giveCredit(int $cents): void
    {
        (new CreditLedger)->add($this->user->id, 'USD', $cents, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');
    }

    private function balance(): string
    {
        return number_format((float) (Credit::query()->where('user_id', $this->user->id)->value('amount') ?? 0), 2, '.', '');
    }

    /** @return list<string> */
    private function rules(array $findings): array
    {
        return array_values(array_unique(array_column($findings, 'rule')));
    }

    // ------------------------------------------------------------------ staff «Terminate server» (markTerminated)

    /** T1 — EXPECTED TO FAIL: the cron's termination gives the stock back; staff's does not, and nothing does later. */
    public function test_t1_staff_terminate_gives_the_stock_back_like_the_cron(): void
    {
        $service = $this->service([], ['stock' => 5]);

        $service->markTerminated();

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertSame(6, (int) $service->product->fresh()->stock, 'staff terminate ended the service but kept its unit of stock');
    }

    /** T1b (control) — the cron's termination does give it back, once. */
    public function test_t1b_cron_termination_gives_the_stock_back_once(): void
    {
        Queue::fake();
        $service = $this->service(['status' => Service::STATUS_SUSPENDED, 'expires_at' => '2026-09-20', 'dunning_suspended_at' => '2026-09-23'], ['stock' => 5]);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertSame(6, (int) $service->product->fresh()->stock);
    }

    /**
     * T2 — EXPECTED TO FAIL: the cron's termination cancels the service's open invoices (with credit given back); staff's
     * «Terminate» leaves them open and payable, and no later step closes them (money-check only reports them).
     */
    public function test_t2_staff_terminate_closes_its_open_renewal_invoice_like_the_cron(): void
    {
        Queue::fake();
        $service = $this->service(['expires_at' => '2026-10-15']);
        $invoice = $this->renewal($service);

        $service->markTerminated();
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status,
            'an open renewal of a server staff destroyed stays payable (the customer can pay for nothing)');
    }

    /**
     * T3 — EXPECTED TO FAIL: the client invoice page spends credit on an open invoice of a terminated service.
     * CreditAutoApply::eligible refuses it, but Invoices\Show::payWithCredit calls payWithCredit() with no eligible
     * guard: the customer's credit turns into a paid invoice that renews nothing.
     */
    public function test_t3_the_invoice_page_does_not_spend_credit_on_a_terminated_service(): void
    {
        $service = $this->service();
        $invoice = $this->renewal($service);
        $service->update(['status' => Service::STATUS_TERMINATED]); // its invoice left open (gateway money on it)
        config(['settings.credits_auto_use' => false]);
        $this->giveCredit(5000);

        $this->livewireAs($this->user)->test(InvoiceShow::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'credit')
            ->call('processPayment');

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'never revived (guarded)');
        $this->assertSame('50.00', $this->balance(), 'credit spent on a terminated service');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    /**
     * T4 — EXPECTED TO FAIL: credit auto-applied in part before staff terminated stays on the open invoice: the cron's
     * termination gives it back (CreditAutoApply::giveBack via cancelInvoices), staff's never does.
     */
    public function test_t4_partial_credit_on_an_open_invoice_comes_back_after_staff_terminate(): void
    {
        Queue::fake();
        $service = $this->service();
        $invoice = $this->renewal($service);
        $this->giveCredit(400);
        $this->assertNotNull((new CreditAutoApply)->toInvoice($invoice));
        $this->assertSame('0.00', $this->balance());

        $service->markTerminated();
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame('4.00', $this->balance(), 'the 4.00 of credit stays stuck on the open invoice of a terminated service');
    }

    /** T4b (control) — the cron's termination gives the same partial credit back. */
    public function test_t4b_cron_termination_gives_partial_credit_back(): void
    {
        Queue::fake();
        $service = $this->service(['status' => Service::STATUS_SUSPENDED, 'expires_at' => '2026-09-20', 'dunning_suspended_at' => '2026-09-23']);
        $invoice = $this->renewal($service, '2026-09-20', '2026-10-20');
        config(['settings.credits_auto_use' => true]);
        $this->giveCredit(400);
        $this->assertNotNull((new CreditAutoApply)->toInvoice($invoice));

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame('4.00', $this->balance());
    }

    /**
     * T5 — EXPECTED TO FAIL: a pending upgrade of a staff-terminated service stays payable until the next cron; paid in
     * that window, ServiceUpgradeService cancels it (right) but the money stays on a paid invoice and money-check has
     * no rule for it (paid_for_cancelled looks only at Service lines). One system e-mail is the only trace.
     */
    public function test_t5_money_on_a_paid_upgrade_of_a_terminated_service_is_reported(): void
    {
        Queue::fake();
        $service = $this->service(['expires_at' => '2026-11-15'], ['stock' => 5]);
        $target = $this->createProduct(['stock' => 5]);
        $upInvoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-10-12']);
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id,
            'invoice_id' => $upInvoice->id]);
        $upInvoice->items()->create(['reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 5]);

        $service->markTerminated();
        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status, 'staff terminate cancels the pending upgrade');
        $this->assertSame(Invoice::STATUS_CANCELLED, $upInvoice->fresh()->status, 'and its invoice, as the cron does');
    }

    /** T5b — an upgrade paid after its service ended (a window the cron leaves): money-check reports the money on it. */
    public function test_t5b_money_on_a_paid_upgrade_of_an_ended_service_is_reported(): void
    {
        Queue::fake();
        $service = $this->service(['expires_at' => '2026-11-15'], ['stock' => 5]);
        $target = $this->createProduct(['stock' => 5]);
        $upInvoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-10-12']);
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id,
            'invoice_id' => $upInvoice->id]);
        $upInvoice->items()->create(['reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 5]);
        $service->update(['status' => Service::STATUS_TERMINATED]); // status only: the upgrade stays pending

        ExtensionHelper::addPayment($upInvoice->id, 'Stripe', 5.00, null, 'pi_up_1', currency: 'usd');

        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status, 'not applied to a dead service (guarded)');
        $this->assertSame(Invoice::STATUS_PAID, $upInvoice->fresh()->status);
        $this->assertNotEmpty((new MoneyCheck)->run(), 'paid 5.00 for an upgrade of a terminated service: money-check sees nothing');
    }

    // ------------------------------------------------------------------ guarded behaviour (expected to pass)

    /** G1 — a gateway payment on a terminated service's invoice: not revived, and money-check reports it. */
    public function test_g1_gateway_payment_for_a_terminated_service_is_reported(): void
    {
        $service = $this->service();
        $invoice = $this->renewal($service);
        $service->update(['status' => Service::STATUS_TERMINATED]); // its invoice left open

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_g1', currency: 'usd');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_g1', currency: 'usd'); // replay

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertSame('2026-10-15', $service->fresh()->expires_at->toDateString());
        $this->assertContains('paid_for_cancelled', $this->rules((new MoneyCheck)->run(['paid_for_cancelled'])));
    }

    /** G2 — staff Mark Paid (RecordPaymentService) does not revive it either. */
    public function test_g2_staff_payment_does_not_revive_a_terminated_service(): void
    {
        $service = $this->service(['status' => Service::STATUS_TERMINATED]);
        $invoice = $this->renewal($service);

        (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'staff-1');

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertSame('2026-10-15', $service->fresh()->expires_at->toDateString());
    }

    /** G3 — the cron never bills, suspends or re-terminates a terminated service, and gives stock back only once. */
    public function test_g3_cron_leaves_a_terminated_service_alone(): void
    {
        Queue::fake();
        $service = $this->service(['status' => Service::STATUS_TERMINATED, 'expires_at' => '2026-08-01', 'dunning_suspended_at' => '2026-08-05'], ['stock' => 5]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertSame(0, $service->invoices()->count(), 'billed');
        $this->assertSame(5, (int) $service->product->fresh()->stock);
        Queue::assertNotPushed(TerminateJob::class);
    }

    /** G4 — the daily credit sweep does not pay a terminated service's open invoice. */
    public function test_g4_credit_sweep_skips_a_terminated_service(): void
    {
        $service = $this->service();
        $invoice = $this->renewal($service);
        $service->update(['status' => Service::STATUS_TERMINATED]); // its invoice left open
        $this->giveCredit(5000);

        (new CreditAutoApply)->sweep();

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame('50.00', $this->balance());
    }

    /** G5 — a cancellation request on a terminated service: refused by the page; a direct row changes nothing. */
    public function test_g5_cancellation_request_on_a_terminated_service(): void
    {
        Queue::fake();
        $service = $this->service(['status' => Service::STATUS_TERMINATED], ['stock' => 5]);

        $this->livewireAs($this->user)->test(Cancel::class, ['service' => $service])
            ->set('type', 'immediate')->set('reason', 'bye')->call('cancelService');
        $this->assertSame(0, ServiceCancellation::query()->count());

        ServiceCancellation::create(['service_id' => $service->id, 'type' => 'immediate', 'reason' => 'direct']);

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'how it ended stays');
        $this->assertSame(5, (int) $service->product->fresh()->stock, 'no second give-back');
        Queue::assertNotPushed(TerminateJob::class);
    }

    /** G6 — not upgradable, and markTerminated twice / on a cron-terminated one is a no-op. */
    public function test_g6_not_upgradable_and_re_termination_is_a_no_op(): void
    {
        $service = $this->service(['status' => Service::STATUS_TERMINATED]);
        $this->assertFalse((bool) $service->upgradable);
        $this->assertFalse((bool) $service->cancellable);

        $service->markTerminated();
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
    }

    /** G7 — staff Terminate cancels the Stripe subscription (endedBySave). */
    public function test_g7_staff_terminate_cancels_the_stripe_subscription(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.stripe.com/*' => Http::response(['id' => 'sub_123'])]);
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();
        $service = $this->service(['subscription_id' => 'sub_123']);
        $service->properties()->create(['key' => 'has_stripe_subscription', 'name' => 'Subscription', 'value' => '1']);

        $service->fresh()->markTerminated();

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), '/subscriptions/sub_123'));
        $this->assertNull($service->fresh()->subscription_id);
    }

    /** G8 — an unknown status from the API validation or a save is refused. */
    public function test_g8_unknown_status_refused_on_create(): void
    {
        $this->expectException(\LogicException::class);
        $this->service(['status' => 'fraud']);
    }
}
