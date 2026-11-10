<?php

namespace Tests\Feature;

use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Service\DunningRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\AcrPay\AcrPay;
use Tests\TestCase;

/**
 * Sweep 7, adversarial verification of «cron-billing» (main at 44532e53, after #770: nothing is suspended for an
 * invoice issued fewer than Suspend Days ago). Each test fails for the reason its finding reports (S7-CRON-BILLING-n):
 * the rest of the dunning still counts from the due date only. Invented fixtures only.
 */
class Sweep7CronBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 03:00:00');
        config(['settings.cronjob_suspend_requires_invoice' => false, 'settings.cronjob_suspend_enabled' => true,
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_order_terminate' => 60, 'settings.cronjob_invoice' => 7,
            'settings.cronjob_terminate_min_suspended_days' => 1, 'settings.credits_auto_use' => false,
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.cronjob_overdue_reminders' => '1,2']);
        $this->user = User::factory()->create();
        Queue::fake();
        MailFacade::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + [
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ]);
    }

    private function cronOn(string $day): void
    {
        Carbon::setTestNow($day . ' 03:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /** @return Collection<int, Invoice> the service's open renewal invoices */
    private function open(Service $service)
    {
        return Invoice::query()->where('status', Invoice::STATUS_UNPAID)
            ->whereHas('items', fn ($line) => $line->where('reference_type', Service::class)->where('reference_id', $service->id))
            ->orderBy('id')->get();
    }

    /**
     * S7-CRON-BILLING-1. An active service whose date is long past and that was not billed for it (staff lifted their own
     * suspension, during which nothing is billed): the run of 11-10 bills 09-01..10-01, due 09-01, issued today. #770
     * gives its customer Suspend Days from the issue: suspended on 11-19. DunningRules::terminable() still counts from
     * the due date alone (09-01 + 60 < 11-20) and from «minimum days suspended» (1): terminated on 11-20, one day after
     * the suspension, its account deleted. A service billed on time gets about 52 days suspended before that.
     */
    public function test_s7_cron_billing_1_a_service_billed_late_is_not_terminated_the_day_after_its_first_suspension(): void
    {
        $service = $this->service(['expires_at' => '2026-09-01']);

        $this->cronOn('2026-11-10');
        $invoice = $this->open($service)->sole();
        $this->assertSame('2026-09-01', $invoice->due_at->toDateString(), 'precondition: billed late, due at the period start');
        $this->assertSame('2026-11-10', $invoice->created_at->toDateString(), 'precondition: issued today');
        foreach (range(11, 18) as $day) {
            $this->cronOn("2026-11-{$day}");
        }
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'precondition: Suspend Days from the issue (#770)');
        $this->cronOn('2026-11-19');
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'precondition: suspended 9 days after the issue');
        $this->assertNotNull($service->fresh()->dunning_suspended_at);

        $this->cronOn('2026-11-20');

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status,
            'invoice issued 11-10, suspended 11-19, terminated 11-20: one day suspended (Termination Days 60 counted from the due date 09-01)');
    }

    /**
     * S7-CRON-BILLING-2. Suspended for non-payment since 10-10, paid until 10-01, one open renewal (10-01..11-01, issued
     * 09-24). On 11-10 its customer pays it: paid until 11-01, nothing open. Service::stillOverdue() keeps it suspended
     * («unsuspended, it would be suspended again the same night»), on the date alone. Since #770 the cron does not do
     * that: the same service, active, is billed 11-01..12-01 tonight and left alone for Suspend Days from that issue.
     * So the customer paid all they were billed and stays offline, though the cron's own rule would not suspend them.
     */
    public function test_s7_cron_billing_2_a_customer_who_pays_all_they_were_billed_is_back_when_the_cron_would_not_suspend_them_again(): void
    {
        $service = $this->service(['expires_at' => '2026-10-01', 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-10-10']);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => '2026-10-01']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10, 'period_start' => '2026-10-01', 'period_end' => '2026-11-01']);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => '2026-09-24 03:00:00']);

        (new RecordPaymentService)->handle($invoice->fresh(), null, 10.0, transactionId: 'wire-s7-2');

        $paid = $service->fresh();
        $this->assertSame('2026-11-01', $paid->expires_at->toDateString(), 'precondition: the payment renewed it');
        $this->assertCount(0, $this->open($service), 'precondition: nothing left to pay');
        $afterPayment = $paid->status;

        // What the cron does with the same service, running: billed tonight, not suspended for Suspend Days from then.
        Service::query()->whereKey($service->id)->update(['status' => Service::STATUS_ACTIVE, 'dunning_suspended_at' => null, 'suspend_hold_until' => null]);
        $this->cronOn('2026-11-10');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'precondition: the cron does not suspend it again tonight');
        $this->assertSame('2026-11-10', $this->open($service)->sole()->created_at->toDateString(), 'precondition: its next period is billed tonight');
        $this->assertFalse(DunningRules::suspendable(Carbon::parse('2026-11-17'))->whereKey($service->id)->exists(), 'precondition: nor in the next 7 days');
        $this->assertTrue(DunningRules::suspendable(Carbon::parse('2026-11-19'))->whereKey($service->id)->exists(), 'precondition: on 11-19');

        $this->assertSame(Service::STATUS_ACTIVE, $afterPayment,
            'paid all it was billed (until 11-01, nothing open): kept suspended as «suspended again tonight», while the cron leaves it alone until 11-19');
    }

    /**
     * S7-CRON-BILLING-3. A customer with «Auto-charge» on and a saved card; their active service's date is 20 days past
     * with nothing billed for it (staff lifted their own suspension). The run of 11-10 bills the period, due 10-21. The
     * card is charged for invoices due within Suspend Days only, and overdue notices go within (last notice day + 3)
     * days of the due date only: this invoice gets neither, and on 11-19 the service is suspended without one attempt
     * on the card and without one overdue notice.
     */
    public function test_s7_cron_billing_3_a_renewal_issued_late_is_charged_to_the_saved_card_and_reminded_before_the_suspension(): void
    {
        if (!class_exists(AcrPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\AcrPay; class AcrPay {
                /** @var list<array{invoice: int, amount: float, key: ?string}> */
                public static array $charges = [];
                /** @var \\Closure|null */
                public static $answer = null;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function supportsPaymentGroups(): bool { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    self::$charges[] = ["invoice" => $invoice->id, "amount" => (float) $amount, "key" => $invoice->chargeKey];
                    return self::$answer === null ? true : (self::$answer)($invoice, $amount);
                } }');
        }
        AcrPay::$charges = [];
        AcrPay::$answer = fn () => false; // declined each time: the invoice stays open, as for a customer whose card fails
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcrPay'], ['name' => 'AcrPay', 'type' => 'gateway', 'enabled' => true]);
        $onTime = User::factory()->create()->client();
        foreach ([$client = $this->user->client(), $onTime] as $n => $account) {
            $agreement = BillingAgreement::query()->create(['client_id' => $account->id, 'gateway_id' => $gateway->id,
                'name' => 'Visa 4242', 'external_reference' => "pm_s7_{$n}", 'type' => 'card']);
            $account->forceFill(['auto_charge' => true])->save();
            $this->assertSame($agreement->id, $account->fresh()->autoChargeAgreement()?->id, 'precondition: a saved card, Auto-charge on');
        }

        $service = $this->service(['expires_at' => '2026-10-21']);
        // The control: another customer's service, billed on time (due 11-15): its card is tried and it is reminded.
        $control = $this->service(['expires_at' => '2026-11-15', 'client_id' => $onTime->id]);
        $this->cronOn('2026-11-10');
        $invoice = $this->open($service)->sole();
        $this->assertSame('2026-10-21', $invoice->due_at->toDateString(), 'precondition: billed late, due 20 days ago');
        foreach (range(11, 18) as $day) {
            $this->cronOn("2026-11-{$day}");
        }
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'precondition: Suspend Days from the issue');
        $tried = fn (Invoice $of) => ChargeAttempt::query()->where('invoice_id', $of->id)->count()
            + count(array_filter(AcrPay::$charges, fn (array $charge) => $charge['invoice'] === $of->id));
        $reminded = fn (Invoice $of) => DB::table('invoice_reminders')->where('invoice_id', $of->id)->where('kind', 'overdue')->count();
        $controlInvoice = $this->open($control)->sole();
        $this->assertGreaterThan(0, $tried($controlInvoice), 'precondition (control): the card of an invoice billed on time is tried');
        $this->assertGreaterThan(0, $reminded($controlInvoice), 'precondition (control): an invoice billed on time gets its overdue notice');
        $attempts = $tried($invoice);
        $overdue = $reminded($invoice);
        $this->cronOn('2026-11-19');
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'precondition: suspended on 11-19');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: never paid');

        $this->assertSame(['card tried' => true, 'overdue notice' => true], ['card tried' => $attempts > 0, 'overdue notice' => $overdue > 0],
            'Auto-charge on, a saved card, the invoice open 9 days: suspended without a try on the card and without an overdue notice');
    }
}
