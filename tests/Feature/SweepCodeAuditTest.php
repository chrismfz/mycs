<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\CronStat;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\MergeInvoices;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SavedMethodCharge;
use App\Services\ServiceUpgrade\ChangeCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use MyCS\Extensions\Gateways\TakenPay\TakenPay;
use Tests\TestCase;

/** Cross-piece audit of the billing/dunning chain at 5bc5e859. */
class SweepCodeAuditTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        MailFacade::fake();
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test',
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.cronjob_order_suspend' => 2, 'settings.cronjob_suspend_enabled' => true]);
        if (!class_exists(TakenPay::class)) {
            // Stripe's charge(): the PaymentIntent succeeded, the payment is recorded later by its webhook.
            eval('namespace MyCS\\Extensions\\Gateways\\TakenPay; class TakenPay {
                public static array $charges = [];
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    self::$charges[] = $invoice->id;
                    return true;
                } }');
        }
        TakenPay::$charges = [];
        $this->client = User::factory()->create()->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Service, 1: Invoice} */
    private function renewal(string $due): array
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $due]);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id, 'period_start' => $due, 'period_end' => Carbon::parse($due)->addMonth()->toDateString()]);

        return [$service, $invoice->fresh()];
    }

    /**
     * H13. The hourly run charges the card first «so a card that pays today comes before a suspension» (round 2 of #411),
     * then suspends in the same breath. Stripe's charge() answers «succeeded» and the payment is recorded by its webhook
     * a few seconds later: SavedMethodCharge::onItsWay holds that invoice as paid-on-its-way for 3 days, but
     * CronJob::suspendable() only knows a Processing InvoiceTransaction. The service is suspended a second after its
     * card was charged.
     */
    public function test_a_card_charged_by_the_hourly_run_is_not_suspended_the_same_second(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'TakenPay'], ['name' => 'TakenPay', 'type' => 'gateway', 'enabled' => true]);
        BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_t', 'type' => 'card']);
        $this->client->refresh();
        $this->client->forceFill(['auto_charge' => true])->save();
        // Weekdays from 10:00; the daily run at 22:00 (it charged nothing yet today).
        config(['settings.cronjob_dunning_days' => [1, 2, 3, 4, 5], 'settings.cronjob_dunning_from' => '10:00']);
        [$service, $invoice] = $this->renewal('2026-10-21'); // Wed; Suspend Days 2 → Sat 24 → postponed to Mon 26 10:00

        Carbon::setTestNow('2026-10-26 10:05:00');
        Setting::query()->updateOrCreate(['key' => 'last_cron_run', 'settingable_type' => CronStat::class],
            ['value' => '2026-10-25 22:00:00', 'type' => 'string']);
        $this->artisan('app:cron-job', ['--dunning-only' => true]);

        $this->assertSame([$invoice->id], TakenPay::$charges, 'precondition: charged on the morning of the suspension');
        $this->assertSame(ChargeAttempt::STATUS_SUCCEEDED, ChargeAttempt::query()->where('invoice_id', $invoice->id)->value('status'));
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status,
            'suspended in the same run, one step after its card was charged successfully (payment not recorded yet)');
    }

    /** H13b. The same in the daily run, with no window: retry days past Suspend Days («to the last retry day set»). */
    public function test_a_card_charged_by_the_daily_run_on_a_retry_day_past_suspend_days_is_not_suspended_in_the_same_run(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'TakenPay'], ['name' => 'TakenPay', 'type' => 'gateway', 'enabled' => true]);
        BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_t', 'type' => 'card']);
        $this->client->refresh();
        $this->client->forceFill(['auto_charge' => true])->save();
        config(['settings.autocharge_retry_days' => '1,3']);
        [$service, $invoice] = $this->renewal('2026-10-21'); // Suspend Days 2: suspended on the 24th = retry day 3

        Carbon::setTestNow('2026-10-24 00:00:30');
        $this->artisan('app:cron-job');

        $this->assertSame([$invoice->id], TakenPay::$charges, 'precondition: charged on retry day 3');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'charged, then suspended by the same run');
    }

    /**
     * H12. A paid service set up by staff ten days later (Σ1-b, datesOnStart) has its expiry moved ten days, its paid
     * line not. A change of cycle «now» credits the unused part of the paid line only (ChangeCycle::unused) and sets the
     * expiry to today: the ten paid days the customer waited are lost, neither delivered nor credited.
     */
    public function test_a_cycle_change_now_after_a_delayed_start_credits_every_paid_day_left(): void
    {
        $this->markTestIncomplete('Open (sweep 2026-10-24, finding 3): the days a paid service waited for staff are not credited by a cycle change «now» / a downgrade; for the owner.');
        config(['settings.credits_enabled' => true, 'settings.cronjob_order_suspend' => 8]);
        Carbon::setTestNow('2026-01-01 10:00:00');
        $product = $this->createProduct()->product;
        $product->update(['autosetup' => Product::AUTOSETUP_NEVER]);
        $yearly = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Yearly',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $yearly->id, 'price' => 100, 'currency_code' => 'USD']);
        $product = $product->fresh();
        (new PlaceOrderService)->place($this->client, 'USD', [new OrderLine($product, $product->plans()->where('billing_unit', 'month')->first(), price: '10.00')]);
        $service = Service::query()->latest('id')->firstOrFail();
        $invoice = $service->invoices()->firstOrFail();
        $invoice->transactions()->create(['amount' => $invoice->remaining]); // paid 1/1, waits for staff
        $this->assertSame('pending', $service->fresh()->status, 'precondition: paid, waiting for staff');

        Carbon::setTestNow('2026-01-11 10:00:00');
        $service->fresh()->applyStaffChanges(['status' => 'active']);
        $this->assertSame('2026-02-11', $service->fresh()->expires_at->toDateString(), 'precondition: ten days waited, moved on');

        Carbon::setTestNow('2026-01-20 10:00:00');
        $quote = ChangeCycle::quote($service->fresh(), $yearly, ChangeCycle::NOW);
        // Paid 10.00 for 31 days; 9 delivered (11th to 20th), 22 left to the 11th of February: 7.10.
        $this->assertSame('7.10', $quote['credit'], 'only the paid line\'s days to 1 February (12) are credited; the ten days waited are lost');
    }

    /**
     * H51. A payment that leaves a service still overdue keeps it suspended and tells staff «unsuspend it by hand to give
     * it time» (RenewServiceService::activate). Staff do (the Unsuspend command: applyStaffChanges status active): the
     * next cron run suspends it again at once, the expiry still past Suspend Days. The time promised is one night.
     */
    public function test_unsuspending_by_hand_as_the_staff_mail_says_gives_the_service_time(): void
    {
        config(['settings.cronjob_order_suspend' => 8, 'settings.cronjob_order_terminate' => 60]);
        [$service, $invoice] = $this->renewal('2026-08-01');
        $service->forceFill(['status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-08-10 00:00:00', 'suspend_reason' => 'Overdue on Payment'])->save();

        Carbon::setTestNow('2026-09-15 10:00:00'); // pays August: paid until 1 September, still past Suspend Days
        $invoice->transactions()->create(['amount' => $invoice->remaining]);
        $this->assertSame('2026-09-01', $service->fresh()->expires_at->toDateString());
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'precondition: still overdue, kept suspended, staff told');

        // Fixed by telling staff what gives time (the mail now says «set Do Not Suspend Until»), as they do here.
        $this->assertTrue(EmailLog::query()->where('body', 'like', '%Do Not Suspend Until%')->exists(), 'the staff mail says how to give it time');
        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE, 'suspend_hold_until' => '2026-09-30']);

        Carbon::setTestNow('2026-09-16 00:00:30');
        $this->artisan('app:cron-job');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'given time, as the mail says');
    }

    /**
     * H54. An upgrade ordered two days before the expiry gets an invoice due in seven days (Livewire Upgrade:
     * now()->addDays(7)); the cron cancels a pending upgrade and its invoice once the service reaches its expiry
     * (CronJob::refreshUpgrade: expires_at < now()). The customer is told «pay by the 31st» and it is cancelled on the
     * 26th; paid on the 28th, the payment becomes credit and the upgrade is not made.
     */
    public function test_an_upgrade_invoice_is_not_due_after_the_service_expires(): void
    {
        [$service] = $this->renewal('2026-10-26');
        Carbon::setTestNow('2026-10-24 12:00:00');

        // Fixed by its due date (the cron still gives up a pending upgrade at the expiry: its price is for the days left).
        $this->assertSame('2026-10-26', ServiceUpgrade::invoiceDueAt($service->fresh())->toDateString());
        $service->forceFill(['expires_at' => '2026-12-01'])->save();
        $this->assertSame('2026-10-31', ServiceUpgrade::invoiceDueAt($service->fresh())->toDateString());
    }

    /**
     * H55. A saved-method charge taken by the gateway and not recorded yet holds its invoice (SavedMethodCharge::onItsWay:
     * no second charge for 3 days). Merge (and split) do not look at it: the lines move to an invoice with no attempt, and
     * the next cron charges that one in full. The late webhook then lands on the merged (cancelled) invoice and becomes
     * credit: the customer's card is charged twice.
     */
    public function test_an_invoice_whose_charge_is_on_its_way_is_not_merged_into_one_the_cron_charges_again(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'TakenPay'], ['name' => 'TakenPay', 'type' => 'gateway', 'enabled' => true]);
        $agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_t', 'type' => 'card']);
        $this->client->refresh();
        $this->client->forceFill(['auto_charge' => true])->save();
        Carbon::setTestNow('2026-10-21 00:00:10');
        [, $first] = $this->renewal('2026-10-21');
        [, $second] = $this->renewal('2026-10-22');
        // The cron charged the first: Stripe took it, its webhook not here yet.
        ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $first->id, 'client_id' => $this->client->id, 'billing_agreement_id' => $agreement->id,
            'amount' => '10.00', 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON, 'status' => ChargeAttempt::STATUS_SUCCEEDED,
            'started_at' => now(), 'sent_at' => now(), 'finished_at' => now()]);
        $this->assertTrue(SavedMethodCharge::onItsWay($first->fresh()), 'precondition: held');

        Carbon::setTestNow('2026-10-21 09:00:00');
        $refused = false;
        try {
            (new MergeInvoices)->handle($second->id, [$first->id]);
        } catch (DisplayException) {
            $refused = true;
        }
        if (!$refused) {
            Carbon::setTestNow('2026-10-22 00:00:10');
            (new AutoCharge)->run();
        }
        $this->assertTrue($refused, 'merged while its charge was on its way; the merged invoice was charged again: ' . json_encode(TakenPay::$charges));
    }

    /**
     * H56. Δ3a: staff cancel a renewal («not this period»): it is not billed again and «the service falls to the
     * suspension and termination rules». With «Suspend only with an overdue invoice» on, there is no invoice: never
     * suspended (a notice every night), so never terminated either (terminable needs a suspension for non-payment). The
     * service runs unpaid for good.
     */
    public function test_a_renewal_given_up_on_still_ends_in_suspension_with_suspend_only_with_an_overdue_invoice(): void
    {
        $this->markTestIncomplete('Open (sweep 2026-10-24, finding 6): only with «Suspend only with an overdue invoice» on (not the default); for the owner.');
        config(['settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_requires_invoice' => true]);
        [$service, $invoice] = $this->renewal('2026-10-01');
        Carbon::setTestNow('2026-09-28 10:00:00');
        $invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED]); // staff: «not this period»
        $this->assertNotNull($invoice->items()->value('forgone_at'), 'precondition: given up on');

        foreach (['2026-10-12', '2026-11-12', '2026-12-12'] as $day) {
            Carbon::setTestNow("{$day} 00:00:30");
            $this->artisan('app:cron-job');
        }
        $this->assertNotSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'still active 72 days past due, nothing billed, never suspended');
    }
}
