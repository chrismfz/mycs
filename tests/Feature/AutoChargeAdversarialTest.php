<?php

namespace Tests\Feature;

use App\Exceptions\ChargeNeedsAction;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\PaymentGroup;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use MyCS\Extensions\Gateways\AdvPay\AdvPay;
use Tests\TestCase;

/**
 * Adversarial review of PR H1 (auto-charge on the due date, charge attempts, PAY-3). Each test names its finding (ADV-n)
 * and failed on feat/autocharge as reviewed.
 */
class AutoChargeAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private BillingAgreement $agreement;

    private Gateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true, 'settings.mass_pay_enabled' => true]);
        if (!class_exists(AdvPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\AdvPay; class AdvPay {
                /** @var list<array{invoice: int, amount: float, key: ?string, agreement: int}> */
                public static array $charges = [];
                /** @var \\Closure|null */
                public static $answer = null;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function supportsPaymentGroups() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    self::$charges[] = ["invoice" => $invoice->id, "amount" => (float) $amount, "key" => $invoice->chargeKey, "agreement" => $agreement->id];
                    return self::$answer === null ? true : (self::$answer)($invoice, $amount);
                } }');
        }
        AdvPay::$charges = [];
        AdvPay::$answer = null;
        MailFacade::fake();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->client = User::factory()->create()->client();
        $this->gateway = Gateway::query()->firstOrCreate(['extension' => 'AdvPay'], ['name' => 'AdvPay', 'type' => 'gateway', 'enabled' => true]);
        $this->agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->gateway->id,
            'name' => 'Card 0000', 'external_reference' => 'pm_adv_1', 'type' => 'card']);
        $this->client->refresh();
        $this->assertSame($this->agreement->id, $this->client->autoChargeAgreement()?->id, 'precondition: the first method is the default');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $due, string $price = '10.00'): Invoice
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => $price, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'subscription_id' => null, 'expires_at' => $due]);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => $price, 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    private function cronOn(string $day): int
    {
        Carbon::setTestNow($day . ' 09:00:00');

        return (new AutoCharge)->run();
    }

    private function declineAll(): void
    {
        AdvPay::$answer = fn () => throw new DisplayException('Card declined: insufficient_funds');
    }

    private function failedMails(): int
    {
        return count(MailFacade::queued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'invoice_payment_failed'));
    }

    /**
     * ADV-1 (Critical): a Mass Pay group's charge holds only its first invoice. The group total, which includes invoice B,
     * was taken (its payment still to come from the webhook), and the same day's cron charges B on its own: B is paid twice.
     */
    public function test_adv1_a_group_charge_on_its_way_holds_all_the_group_invoices(): void
    {
        $a = $this->invoice('2026-11-08');
        $b = $this->invoice('2026-11-10');
        $group = (new MassPay)->open($this->client, [$a->id, $b->id]);
        $this->assertSame($a->id, (int) $group->invoice_id);

        $attempt = (new SavedMethodCharge)->charge($group->invoice, $this->agreement, ChargeAttempt::SOURCE_GROUP, $group);
        $this->assertSame(ChargeAttempt::STATUS_SUCCEEDED, $attempt->status);
        $this->assertSame(20.0, AdvPay::$charges[0]['amount'], 'the group total, A and B');

        $this->cronOn('2026-11-10'); // a few seconds later, before Stripe's webhook

        $this->assertCount(1, AdvPay::$charges, 'invoice B charged again on its own while the group charge that pays it is on its way');
    }

    /**
     * ADV-1b (Critical): a group's processing payment (SEPA, a PayPal pending capture) sits on its first invoice; the cron
     * charges the group's other invoice anyway (MassPay::refusal() knows this case, SavedMethodCharge::onItsWay() does not).
     */
    public function test_adv1b_a_processing_group_payment_holds_the_groups_other_invoices_from_the_cron(): void
    {
        $a = $this->invoice('2026-11-08');
        $b = $this->invoice('2026-11-10');
        $group = (new MassPay)->open($this->client, [$a->id, $b->id]);
        $processing = ExtensionHelper::addProcessingPayment($a->id, 'AdvPay', 20.00, null, 'sepa_adv_1', currency: 'USD', paymentGroup: $group->ulid);
        PaymentGroup::query()->whereKey($group->id)->update(['invoice_transaction_id' => $processing->id ?? $a->transactions()->value('id')]);
        $this->assertNotNull(MassPay::refusal($b->fresh()), 'precondition: Mass Pay itself holds B');

        $this->cronOn('2026-11-10');

        $this->assertSame([], array_values(array_filter(AdvPay::$charges, fn ($c) => $c['invoice'] === $b->id)),
            'B charged while the group payment that covers it is processing');
    }

    /**
     * ADV-2 (High): a charge the gateway took but whose payment was not recorded (Stripe's webhook late, failing or the
     * endpoint down) holds the invoice for 15 minutes only. The next day's cron charges the customer again, with a new key.
     */
    public function test_adv2_a_succeeded_charge_not_yet_recorded_is_not_charged_again_the_next_day(): void
    {
        $invoice = $this->invoice('2026-11-10'); // AdvPay answers true without recording, as Stripe does

        $this->assertSame(1, $this->cronOn('2026-11-10'));
        $this->cronOn('2026-11-11');

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertCount(1, AdvPay::$charges, 'taken on the 10th and taken again on the 11th');
    }

    /**
     * ADV-3 (High): an ambiguous gateway error (a timeout, a 5xx: the charge may have gone through) is kept as «failed»: the
     * invoice is freed at once and the next click charges with a NEW idempotency key, so the gateway cannot deduplicate.
     */
    public function test_adv3_an_ambiguous_gateway_error_does_not_free_the_invoice_for_a_new_key(): void
    {
        $invoice = $this->invoice('2026-11-10');
        AdvPay::$answer = fn () => throw new \RuntimeException('cURL error 28: Operation timed out after 30001 milliseconds');

        $first = (new SavedMethodCharge)->charge($invoice, $this->agreement, ChargeAttempt::SOURCE_CLIENT);
        AdvPay::$answer = null;
        try {
            (new SavedMethodCharge)->charge($invoice->fresh(), $this->agreement, ChargeAttempt::SOURCE_CLIENT);
        } catch (DisplayException) {
            // held: fine
        }

        $keys = array_unique(array_column(AdvPay::$charges, 'key'));
        $this->assertCount(1, $keys, "status after the timeout: {$first->fresh()->status}; two different idempotency keys sent for the same invoice");
    }

    /**
     * ADV-3b (High): the same through the real Stripe extension. A 500 from Stripe (the request may have been processed) is
     * turned into «Could not process payment», the attempt fails, and a second click sends another Idempotency-Key.
     */
    public function test_adv3b_stripe_5xx_then_a_second_click_reuses_the_idempotency_key(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa',
            'external_reference' => 'pm_s', 'type' => 'card']);
        $this->client->properties()->updateOrCreate(['key' => 'stripe_id'], ['value' => 'cus_adv']);
        Http::fake([
            'api.stripe.com/v1/customers/*' => Http::response(['id' => 'cus_adv']),
            'api.stripe.com/v1/payment_intents' => Http::sequence()
                ->push(['error' => ['type' => 'api_error', 'message' => 'An unknown error occurred']], 500)
                ->push(['id' => 'pi_2', 'status' => 'succeeded'], 200),
        ]);
        $invoice = $this->invoice('2026-11-10');

        (new SavedMethodCharge)->charge($invoice, $agreement, ChargeAttempt::SOURCE_CLIENT);
        try {
            (new SavedMethodCharge)->charge($invoice->fresh(), $agreement, ChargeAttempt::SOURCE_CLIENT);
        } catch (DisplayException) {
            // held: fine
        }

        $keys = [];
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/payment_intents')) {
                $keys[] = $request->header('Idempotency-Key')[0] ?? null;
            }
        }
        $this->assertCount(1, array_unique($keys), 'two PaymentIntents with different keys after a 5xx: ' . json_encode($keys));
    }

    /**
     * ADV-4 (High): the gateway is charged the caller's (stale) `$invoice->remaining`, not the amount SavedMethodCharge
     * re-read under the locks and wrote on the attempt. A payment recorded after the caller loaded the invoice (credit, a
     * bank transfer, a partial payment in another tab) is charged again.
     */
    public function test_adv4_the_gateway_is_charged_the_amount_read_under_the_locks(): void
    {
        $invoice = $this->invoice('2026-11-10');
        $this->assertSame(10.0, (float) $invoice->remaining); // the caller has read it (loads its transactions)
        ExtensionHelper::addPayment($invoice->id, 'AdvPay', 4.00, null, 'bank_adv_1', currency: 'USD'); // meanwhile

        $attempt = (new SavedMethodCharge)->charge($invoice, $this->agreement, ChargeAttempt::SOURCE_CLIENT);

        $this->assertSame('6.00', (string) $attempt->amount, 'the attempt has the amount re-read under the locks');
        $this->assertSame(6.0, AdvPay::$charges[0]['amount'], 'the card was charged the stale amount');
    }

    /**
     * ADV-5 (Medium): no lower bound on the due date. An invoice due months ago whose service is still active (an old
     * WHMCS import, a service held by staff) is charged on the first run after go-live, and every day after, with no
     * customer mail after its first decline (its «last try» day is long past). WHMCS stops retrying once past its window.
     */
    public function test_adv5_an_invoice_overdue_for_months_is_not_charged_at_go_live(): void
    {
        $this->invoice('2026-06-01');

        $this->cronOn('2026-11-10');

        $this->assertSame([], AdvPay::$charges, 'an invoice 162 days overdue charged by the first run (past the suspension day, 8)');
    }

    /**
     * ADV-6 (Medium): the reminder before the due date says «it will be charged» (card_payment_due) for an invoice the cron
     * will not charge: the method is past autocharge_max_failures. The customer waits for a charge that never comes.
     */
    public function test_adv6_a_capped_method_is_not_announced_as_charged_on_its_own(): void
    {
        config(['settings.autocharge_max_failures' => 3]);
        BillingAgreement::query()->whereKey($this->agreement->id)->update(['failed_count' => 3]);
        $invoice = $this->invoice('2026-11-14');

        $this->cronOn('2026-11-14');
        $this->assertSame([], AdvPay::$charges, 'precondition: the cron does not charge it');

        $this->assertFalse(PaymentReminders::chargedOnItsOwn($invoice->fresh()), 'reminder says it will be charged');
    }

    /**
     * ADV-7 (Medium): after the bank asked to confirm a charge on card 1, the customer saves card 2 as the default. The
     * invoice is never auto-charged again (the check is the invoice's latest attempt, whatever its card): it goes on to
     * suspension though the customer has a working default card and «Auto-charge» on.
     */
    public function test_adv7_a_new_default_card_after_action_required_is_charged(): void
    {
        $this->invoice('2026-11-10');
        AdvPay::$answer = fn () => throw new ChargeNeedsAction('confirm');
        $this->cronOn('2026-11-10');
        $this->assertSame(ChargeAttempt::STATUS_ACTION_REQUIRED, ChargeAttempt::query()->sole()->status);

        $new = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->gateway->id,
            'name' => 'Card 1111', 'external_reference' => 'pm_adv_2', 'type' => 'card']);
        $this->client->update(['default_billing_agreement_id' => $new->id, 'auto_charge' => true]);
        AdvPay::$answer = null;
        $this->cronOn('2026-11-11');

        $this->assertSame([$this->agreement->id, $new->id], array_column(AdvPay::$charges, 'agreement'), 'the new default card is never charged');
    }

    /**
     * ADV-8 (Low): with retry days set (1,3,5,7) and suspension on day 8, the last try before suspension is day 7, but the
     * «last try» mail goes only when the offset equals cronjob_order_suspend (8), a day with no try: the customer is told
     * once (day 0) and then suspended.
     */
    public function test_adv8_the_last_try_before_suspension_is_mailed_with_retry_days_set(): void
    {
        config(['settings.autocharge_retry_days' => '1,3,5,7']);
        $this->invoice('2026-11-10');
        $this->declineAll();

        foreach (range(10, 18) as $day) {
            $this->cronOn("2026-11-{$day}");
        }

        $this->assertCount(5, AdvPay::$charges, 'precondition: days 0,1,3,5,7');
        $this->assertSame(2, $this->failedMails(), 'the first decline and the last try before suspension (day 7)');
    }

    /**
     * ADV-9 (Low): «declined N times in a row» counts invoices, not days. A customer with three renewals due the same day
     * reaches autocharge_max_failures = 3 on the first day and is never retried, though the card was declined once.
     */
    public function test_adv9_three_invoices_declined_on_one_day_do_not_exhaust_the_retries(): void
    {
        config(['settings.autocharge_max_failures' => 3]);
        $this->invoice('2026-11-10');
        $this->invoice('2026-11-10');
        $this->invoice('2026-11-10');
        $this->declineAll();

        $this->cronOn('2026-11-10');
        $this->cronOn('2026-11-11');

        $this->assertCount(6, AdvPay::$charges, 'one day of declines stopped the auto-charge for good');
    }
}
