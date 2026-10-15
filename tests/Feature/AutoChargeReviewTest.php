<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Invoice\PaymentReminders;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use MyCS\Extensions\Gateways\AcrPay\AcrPay;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Independent code review of PR H1 (feat/autocharge): auto-charge on the due date, retries, charge attempts (PAY-3).
 * test_cr1..cr5 failed on 92279680 and pass on cfe38e3 (fixed by ADV-1..ADV-9); test_cr7 and test_cr8 FAIL on cfe38e3.
 * test_guard_* pin a guarantee (CR-6 failed on 92279680, holds on cfe38e3).
 */
class AutoChargeReviewTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private BillingAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true, 'settings.mass_pay_enabled' => true]);
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
        AcrPay::$answer = null; // by default: the money is taken, its payment is recorded later (Stripe's webhook)
        MailFacade::fake();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->client = User::factory()->create()->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcrPay'], ['name' => 'AcrPay', 'type' => 'gateway']);
        $this->agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_r1', 'type' => 'card']);
        $this->client->refresh();
        $this->assertSame($this->agreement->id, $this->client->autoChargeAgreement()?->id, 'precondition: the first method is the default');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An open renewal invoice of an active service, due on $due. */
    private function invoice(string $due): Invoice
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $due]);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    /** An open invoice staff made (no service line): not the cron's to charge. */
    private function staffInvoice(string $due): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Staff line', 'price' => '5.00', 'quantity' => 1]);

        return $invoice->fresh();
    }

    private function cronOn(string $day, string $time = '09:00:00'): int
    {
        Carbon::setTestNow($day . ' ' . $time);

        return (new AutoCharge)->run();
    }

    private function declineAll(): void
    {
        AcrPay::$answer = fn () => throw new DisplayException('Card declined: insufficient_funds');
    }

    /** @return list<array{invoice: int, amount: float, key: ?string}> */
    private function chargesOf(Invoice $invoice): array
    {
        return array_values(array_filter(AcrPay::$charges, fn (array $charge) => (int) $charge['invoice'] === (int) $invoice->id));
    }

    private function failedMails(): int
    {
        return count(MailFacade::queued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'invoice_payment_failed'));
    }

    /**
     * CR-1: the gateway took the money (attempt «succeeded») but the payment is not recorded yet: Stripe's webhook is late,
     * lost, or the endpoint was down; PayPal's synchronous addPayment threw (it is only reported, PayPal.php). The hold
     * lasts HOLD_MINUTES only, so the next day's cron charges the invoice again under a new key: charged twice.
     */
    public function test_cr1_money_taken_but_not_recorded_is_charged_again_the_next_day(): void
    {
        $invoice = $this->invoice('2026-11-10');

        $this->cronOn('2026-11-10');
        $this->assertSame(ChargeAttempt::STATUS_SUCCEEDED, ChargeAttempt::query()->sole()->status, 'precondition: the gateway took it');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: its payment is still to be recorded');

        $this->cronOn('2026-11-11');

        $this->assertCount(1, $this->chargesOf($invoice), 'a charge the gateway took must hold the invoice until its payment is recorded or fails');
    }

    /** CR-1, the customer's side: 20 minutes after the cron's charge was taken, «pay with my saved card» charges it again. */
    public function test_cr1_a_click_after_the_hold_charges_a_taken_charge_again(): void
    {
        $invoice = $this->invoice('2026-11-10');
        $this->cronOn('2026-11-10');

        Carbon::setTestNow('2026-11-10 09:20:00');
        try {
            (new SavedMethodCharge)->charge($invoice->fresh(), $this->agreement, ChargeAttempt::SOURCE_CLIENT);
        } catch (DisplayException) {
            // refused: the expected outcome
        }

        $this->assertCount(1, $this->chargesOf($invoice), 'the money of the first charge was taken: a second one pays the invoice twice');
    }

    /**
     * CR-2: the outcome of a charge that timed out is unknown (Stripe may well have charged it), yet the attempt is marked
     * «failed», which frees the invoice at once, and the next charge goes out under a NEW idempotency key. The key only
     * helps if the same attempt is retried with it; nothing ever does.
     */
    public function test_cr2_a_timed_out_charge_frees_the_invoice_and_the_next_one_uses_a_new_key(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa',
            'external_reference' => 'pm_s', 'type' => 'card']);
        $this->client->properties()->updateOrCreate(['key' => 'stripe_id'], ['value' => 'cus_1']);
        $intents = 0;
        $keys = []; // every PaymentIntent request's Idempotency-Key, also one that never got an answer
        Http::fake(function (Request $request) use (&$intents, &$keys) {
            if (str_contains($request->url(), '/customers/')) {
                return Http::response(['id' => 'cus_1']);
            }
            if (str_contains($request->url(), '/payment_intents')) {
                $keys[] = $request->header('Idempotency-Key')[0] ?? null;
                if (++$intents === 1) {
                    throw new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds');
                }

                return Http::response(['id' => 'pi_' . $intents, 'status' => 'succeeded']);
            }

            return Http::response([], 404);
        });
        $invoice = $this->invoice('2026-11-10');

        $first = (new SavedMethodCharge)->charge($invoice, $agreement, ChargeAttempt::SOURCE_CLIENT);
        $this->assertNotSame(ChargeAttempt::STATUS_SUCCEEDED, $first->status, 'precondition: no answer from Stripe');
        try {
            (new SavedMethodCharge)->charge($invoice->fresh(), $agreement, ChargeAttempt::SOURCE_CLIENT);
        } catch (DisplayException) {
            // refused while the first one's outcome is unknown: fine
        }

        $this->assertCount(1, array_unique($keys), 'a timed-out charge may have gone through: a second PaymentIntent under a new key charges twice');
    }

    /**
     * CR-3: the gateway is charged the remaining of the caller's Invoice object (ExtensionHelper::charge, $total null →
     * $invoice->remaining, from relations loaded earlier), not the amount read again under the locks and written on the
     * attempt. AutoCharge::chargeOne reads $invoice->remaining before the lock, so a payment or credit landing in between
     * is charged again; the attempt says 6.00, the card is charged 10.00.
     */
    public function test_cr3_the_gateway_is_charged_the_stale_remaining_not_the_locked_one(): void
    {
        $invoice = $this->invoice('2026-11-10');
        $stale = Invoice::query()->with('client')->findOrFail($invoice->id);
        $this->assertSame(10.0, (float) $stale->remaining, 'precondition: read before the lock, as AutoCharge::chargeOne does');

        // Meanwhile (another process): 4.00 is paid.
        ExtensionHelper::addPayment($invoice->id, 'AcrPay', 4.00, null, 'tx_partial', currency: 'USD');
        $this->assertSame(6.0, (float) $invoice->fresh()->remaining);

        $attempt = (new SavedMethodCharge)->charge($stale, $this->agreement, ChargeAttempt::SOURCE_CRON);

        $this->assertSame('6.00', (string) $attempt->amount, 'the attempt read it again under the locks');
        $this->assertSame(6.0, AcrPay::$charges[0]['amount'], 'the card must be charged what the attempt says, not the stale 10.00');
    }

    /**
     * CR-4a: a Mass Pay charge holds only its lead invoice. The cron, while the group's charge is on its way (money taken,
     * the webhook to come), charges the group's other invoice on its own: that invoice is paid twice.
     */
    public function test_cr4_a_group_charge_on_its_way_does_not_hold_the_groups_other_invoices(): void
    {
        $lead = $this->invoice('2026-11-10');
        $other = $this->invoice('2026-11-10');
        $group = (new MassPay)->open($this->client, [$lead->id, $other->id]);
        $this->assertSame($lead->id, (int) $group->invoice_id, 'precondition: the lead');

        $attempt = (new SavedMethodCharge)->charge($group->invoice, $this->agreement, ChargeAttempt::SOURCE_GROUP, $group);
        $this->assertSame(ChargeAttempt::STATUS_SUCCEEDED, $attempt->status, 'precondition: the group\'s 20.00 is taken');

        $this->cronOn('2026-11-10', '09:05:00');

        $this->assertSame([], $this->chargesOf($other), 'its share is in the group charge just taken: charged twice');
    }

    /** CR-4b: the other way round: the cron's charge of an invoice is on its way, and the customer pays it again in a group. */
    public function test_cr4_a_group_charge_is_not_held_by_a_charge_on_its_way_on_a_non_lead_invoice(): void
    {
        $lead = $this->staffInvoice('2026-11-09'); // due first: the group's lead; not the cron's
        $renewal = $this->invoice('2026-11-10');
        $this->cronOn('2026-11-10');
        $this->assertCount(1, $this->chargesOf($renewal), 'precondition: the cron took the renewal\'s 10.00, webhook to come');

        Carbon::setTestNow('2026-11-10 09:05:00');
        try {
            $group = (new MassPay)->open($this->client, [$lead->id, $renewal->id]);
            (new SavedMethodCharge)->charge($group->invoice, $this->agreement, ChargeAttempt::SOURCE_GROUP, $group);
        } catch (DisplayException) {
            // refused: the expected outcome
        }

        $this->assertSame([10.0], array_column(AcrPay::$charges, 'amount'), 'the group charged 15.00 more, 10.00 of it for the renewal already charged');
    }

    /**
     * CR-5: once the method has failed autocharge_max_failures times the cron stops charging it, but chargedOnItsOwn()
     * still says the invoice is paid automatically: the new-invoice mail says «there is nothing you need to do» and
     * hides the pay button, and the reminder before the due date is «card_payment_due». Nothing charges it.
     */
    public function test_cr5_an_invoice_the_cron_will_not_charge_is_not_told_it_will_be_charged(): void
    {
        config(['settings.autocharge_max_failures' => 2]);
        BillingAgreement::query()->whereKey($this->agreement->id)->update(['failed_count' => 2]);
        $invoice = $this->invoice('2026-11-17');

        $this->cronOn('2026-11-17');
        $this->assertSame([], AcrPay::$charges, 'precondition: auto-charging stopped for this method');

        $this->assertFalse(PaymentReminders::chargedOnItsOwn($invoice->fresh()->load('client')),
            'the customer is told «nothing you need to do» for an invoice nothing will charge');
    }

    /**
     * CR-6 (fixed in cfe38e3, kept as a GUARD): the «last try before suspension» mail must go out on the day of the last
     * charge. Before the fix it went out on day cronjob_order_suspend (8), but the cron still charged on day 9 (the
     * suspension day: CronJob::suspendable is expires_at < today − 8, and invoices_charged runs before services_suspended),
     * silently.
     */
    public function test_guard_cr6_the_last_try_notice_is_sent_on_the_day_of_the_last_charge(): void
    {
        $invoice = $this->invoice('2026-11-10');
        $service = Service::query()->findOrFail($invoice->items()->sole()->reference_id);
        $this->declineAll();
        $cron = app(CronJob::class);
        $suspendable = new ReflectionMethod($cron, 'suspendable');

        $mailsOn = [];
        foreach (range(10, 25) as $day) {
            $before = $this->failedMails();
            $this->cronOn("2026-11-{$day}");
            $mailsOn[$day] = $this->failedMails() - $before;
            if ($suspendable->invoke($cron)->whereKey($service->id)->exists()) {
                break; // services_suspended suspends it in this very run, after invoices_charged
            }
        }

        $lastCharge = Carbon::parse(ChargeAttempt::query()->max('started_at'))->day;
        $this->assertSame(1, $mailsOn[$lastCharge], "the last charge (day {$lastCharge}) is not the one the customer is told of: mails per day " . json_encode($mailsOn));
    }

    /**
     * CR-7: a charge the gateway took holds the invoice until «a payment is recorded on its invoice after it»
     * (SavedMethodCharge::recordedSince), but ANY transaction counts: credit applied by the cron's credit_applied step
     * (which runs before invoices_charged), a partial bank transfer staff record, a refund line. The hold is released while
     * the taken charge is still unrecorded, and the cron charges the rest again: 10.00 taken, 9.00 charged on top.
     */
    public function test_cr7_an_unrelated_payment_releases_the_hold_of_a_charge_taken_and_not_recorded(): void
    {
        $invoice = $this->invoice('2026-11-10');
        $this->cronOn('2026-11-10');
        $this->assertSame(ChargeAttempt::STATUS_SUCCEEDED, ChargeAttempt::query()->sole()->status, 'precondition: 10.00 taken, webhook to come');

        Carbon::setTestNow('2026-11-11 08:00:00');
        ExtensionHelper::addPayment($invoice->id, 'AcrPay', 1.00, null, 'bank_1', currency: 'USD'); // e.g. credit, or a transfer
        $this->cronOn('2026-11-11');

        $this->assertCount(1, $this->chargesOf($invoice), 'the 10.00 taken yesterday is still to be recorded: charging 9.00 more pays it twice');
    }

    /**
     * CR-8: an attempt with an unknown outcome is sent again with its key (ADV-3), by updating the old row back to
     * «started» without touching started_at. The once-a-day rule reads started_at >= today on cron attempts, so the retry
     * of last night's unknown attempt does not count as today's: a second cron run the same day charges again.
     */
    public function test_cr8_a_resent_unknown_attempt_does_not_count_as_todays_charge(): void
    {
        $invoice = $this->invoice('2026-11-10');
        $calls = 0;
        AcrPay::$answer = function () use (&$calls) {
            if (++$calls === 1) {
                throw new \RuntimeException('cURL error 28: Operation timed out'); // no answer: unknown
            }
            throw new DisplayException('Card declined: insufficient_funds');
        };

        $this->cronOn('2026-11-10', '23:30:00');
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, ChargeAttempt::query()->sole()->status, 'precondition');
        $this->cronOn('2026-11-11', '09:00:00'); // sent again with its key, declined
        $this->cronOn('2026-11-11', '15:00:00'); // the cron runs again the same day

        $this->assertCount(2, $this->chargesOf($invoice), 'charged twice on 2026-11-11: «never twice a day»');
    }
}
