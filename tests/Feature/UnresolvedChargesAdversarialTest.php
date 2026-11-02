<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\GatewayReference;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SavedMethodCharge;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Str;
use MyCS\Extensions\Gateways\AdvUnresPay\AdvUnresPay;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Adversarial review of PR #581 (an unsettled charge holds its invoice until settled, predeploy S1–S3). Each test fails on
 * the PR's head and passes once its finding is fixed. Gateways are synthetic (AdvUnresPay) or PayPal over Http::fake.
 */
#[Group('adversarial')]
class UnresolvedChargesAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $reviewUser;

    private Gateway $gateway;

    private BillingAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true, 'settings.mass_pay_enabled' => true]);
        if (!class_exists(AdvUnresPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\AdvUnresPay; class AdvUnresPay {
                /** @var list<array{invoice: int, amount: float, key: ?string}> */
                public static array $charges = [];
                /** @var \\Closure|null */
                public static $answer = null;
                public function __construct($config = []) {}
                public function pay($invoice, $amount) { return "https://example.test/checkout"; }
                public function supportsBillingAgreements() { return true; }
                public function supportsPaymentGroups(): bool { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    self::$charges[] = ["invoice" => $invoice->id, "amount" => (float) $amount, "key" => $invoice->chargeKey];
                    return self::$answer === null ? true : (self::$answer)($invoice, $amount);
                } }');
        }
        AdvUnresPay::$charges = [];
        AdvUnresPay::$answer = null;
        MailFacade::fake();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->reviewUser = User::factory()->create();
        $this->client = $this->reviewUser->client();
        $this->gateway = Gateway::query()->firstOrCreate(['extension' => 'AdvUnresPay'], ['name' => 'AdvUnresPay', 'type' => 'gateway', 'enabled' => true]);
        $this->agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_adv1', 'type' => 'card']);
        $this->client->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An open renewal invoice of 10 USD of an active service. */
    private function invoice(string $due = '2026-11-10'): Invoice
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

    /** A charge of the saved card whose answer was lost (a timeout after the gateway may have taken it). */
    private function unknownCharge(Invoice $invoice): ChargeAttempt
    {
        AdvUnresPay::$answer = fn () => throw new \RuntimeException('timeout after the gateway accepted the charge');
        $attempt = (new SavedMethodCharge)->charge($invoice, $this->agreement, ChargeAttempt::SOURCE_CLIENT);
        AdvUnresPay::$answer = null;
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $attempt->fresh()->status, 'precondition: outcome unknown');
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()), 'precondition: the invoice is held');

        return $attempt;
    }

    /**
     * ADV-UNRES-1: recordedSince() counts any transaction of the gateway, failed ones too. A declined payment of another
     * PaymentIntent of the same invoice (Stripe's payment_intent.payment_failed → addFailedPayment), recorded after the
     * unknown charge was sent, «settles» it: the invoice is released and another card takes the debt again.
     */
    public function test_adv_unres_1_a_failed_payment_of_the_gateway_releases_an_unknown_charge(): void
    {
        $invoice = $this->invoice();
        $this->unknownCharge($invoice);
        Carbon::setTestNow('2026-11-10 09:00:30');
        // The webhook of an earlier declined try (another card, a checkout) arrives now: money NOT taken.
        ExtensionHelper::addFailedPayment($invoice->id, 'AdvUnresPay', 10, null, 'pi_declined_other', 'USD');

        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()), 'a failed payment is not the unknown charge\'s money: still held');

        $other = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->gateway->id,
            'name' => 'Mastercard 5555', 'external_reference' => 'pm_adv2', 'type' => 'card']);
        try {
            (new SavedMethodCharge)->charge($invoice->fresh(), $other, ChargeAttempt::SOURCE_CLIENT);
        } catch (DisplayException) {
        }
        $this->assertCount(1, AdvUnresPay::$charges, 'the first card may have paid: no second card is charged');
    }

    /**
     * ADV-UNRES-2: PayPal's one-time checkout captures on our server (capture()), with no look at the hold. A PayPal
     * order made before the hold (the invoice page left open) is captured after a saved-card charge of unknown outcome:
     * the debt is taken twice, the second as account credit.
     */
    public function test_adv_unres_2_paypal_capture_takes_money_on_a_held_invoice(): void
    {
        Http::preventStrayRequests();
        $api = 'https://api-m.sandbox.paypal.com';
        $paypalGateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        foreach (['test_mode' => '1', 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'wh'] as $key => $value) {
            $paypalGateway->settings()->create(['key' => $key, 'value' => $value]);
        }
        $invoice = $this->invoice();
        PayPal::rememberOrder('ORDER-ADV', $invoice); // the customer opened PayPal's checkout first
        $this->unknownCharge($invoice);               // then the saved card was charged: outcome unknown

        $order = ['id' => 'ORDER-ADV', 'status' => 'APPROVED',
            'purchase_units' => [['invoice_id' => GatewayReference::paypalInvoiceId($invoice), 'amount' => ['currency_code' => 'USD', 'value' => '10.00']]]];
        Http::fake([
            $api . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            $api . '/v2/checkout/orders/ORDER-ADV/capture' => Http::response(['id' => 'ORDER-ADV', 'status' => 'COMPLETED',
                'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-ADV', 'status' => 'COMPLETED',
                    'amount' => ['value' => '10.00', 'currency_code' => 'USD']]]]]]], 201),
            $api . '/v2/checkout/orders/ORDER-ADV' => Http::response($order),
        ]);

        try {
            ExtensionHelper::getExtension('gateway', 'PayPal', $paypalGateway->fresh()->settings)->capture($invoice->fresh(), 'ORDER-ADV');
        } catch (HttpException|DisplayException) {
        }

        Http::assertNotSent(fn (Request $request) => $request->url() === $api . '/v2/checkout/orders/ORDER-ADV/capture');
        $this->assertSame(0, $invoice->transactions()->where('gateway_id', $paypalGateway->id)->count(), 'nothing captured while a charge of it is unsettled');
    }

    /**
     * ADV-UNRES-3: «Resolve charge» offers, and marks «not taken», a charge sent seconds ago whose gateway call is still
     * running (status started, not yet past HOLD_MINUTES). Staff cannot have checked it at the gateway; the invoice is
     * released while the money may be taken a moment later, and the call's finish() then overwrites staff's «failed».
     */
    public function test_adv_unres_3_resolve_charge_releases_a_charge_still_in_flight(): void
    {
        $invoice = $this->invoice();
        $attempt = ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $invoice->id,
            'client_id' => $this->client->id, 'billing_agreement_id' => $this->agreement->id,
            'amount' => 10, 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CLIENT,
            'status' => ChargeAttempt::STATUS_STARTED, 'started_at' => now()->subSeconds(5), 'sent_at' => now()->subSeconds(5)]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        try {
            $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
                ->callAction('resolveCharge', ['attempt' => (string) $attempt->id]);
        } catch (\Throwable) {
        }

        $this->assertSame(ChargeAttempt::STATUS_STARTED, $attempt->fresh()->status, 'a charge still on its way is not resolved by staff');
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()), 'still held while the gateway call runs');
    }

    /**
     * ADV-UNRES-4: the PR's release path «staff record the payment with the gateway's transaction id» fails when staff
     * date it the day the gateway took it (the form's «Payment date», MT-1c): created_at gets that day at the current
     * time, before the attempt's sent_at, and recordedSince() never matches. The invoice is paid, yet the charge stays
     * «unsettled» forever (staff mailed every 3 days); the only way out is to mark a TAKEN charge «not taken».
     */
    public function test_adv_unres_4_staff_recording_the_payment_dated_the_day_taken_does_not_settle_it(): void
    {
        Carbon::setTestNow('2026-11-10 20:00:00'); // the customer pays in the evening; the answer is lost
        $invoice = $this->invoice();
        $this->unknownCharge($invoice);

        Carbon::setTestNow('2026-11-11 09:00:00'); // next morning staff find it taken at the gateway and record it
        (new RecordPaymentService)->handle($invoice->id, $this->gateway, '10.00', null, 'ch_taken_adv4', enteredByStaff: true,
            paidAt: Carbon::parse('2026-11-10')->setTimeFrom(now())); // as TransactionsRelationManager / MarkInvoicePaid do
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'precondition: recorded');

        $this->assertTrue(SavedMethodCharge::unresolved([$invoice->id])->isEmpty(), 'its payment, with the gateway\'s id, is recorded: settled');
    }

    /**
     * ADV-UNRES-5: related() holds every invoice ever put in a Mass Pay group with the held one, whatever the attempt.
     * A charge of invoice A alone (no group: it can only pay A) now holds B forever: the customer cannot pay B by any
     * means, B's service is never suspended, and B's page has no «Resolve charge» (the attempt is on A).
     */
    public function test_adv_unres_5_a_charge_of_one_invoice_alone_holds_another_once_grouped_with_it(): void
    {
        $a = $this->invoice('2026-11-09');
        $b = $this->invoice('2026-11-10');
        (new MassPay)->open($this->client, [$a->id, $b->id]); // chosen together once, then paid one by one
        $attempt = $this->unknownCharge($a);
        $this->assertNull($attempt->payment_group_id, 'precondition: a charge of A alone');

        try {
            (new SavedMethodCharge)->charge($b->fresh(), $this->agreement, ChargeAttempt::SOURCE_CLIENT);
        } catch (DisplayException) {
        }
        $this->assertCount(2, AdvUnresPay::$charges, 'A\'s charge cannot pay B: B is paid on its own');
    }

    /**
     * ADV-UNRES-6: tellOfTakenNotRecorded() runs on every AutoCharge run (hourly and daily) over EVERY succeeded attempt
     * ever (no time bound any more), with one recordedSince() query per attempt: a history of N recorded charges costs N
     * queries an hour, forever (before: the last 3 days only).
     */
    public function test_adv_unres_6_the_staff_notice_reads_every_recorded_charge_ever_on_every_run(): void
    {
        Carbon::setTestNow('2026-09-01 09:00:00');
        for ($i = 0; $i < 40; $i++) {
            $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-09-01']);
            ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $invoice->id,
                'client_id' => $this->client->id, 'billing_agreement_id' => $this->agreement->id,
                'amount' => 10, 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON,
                'status' => ChargeAttempt::STATUS_SUCCEEDED, 'started_at' => now(), 'sent_at' => now(), 'finished_at' => now()]);
            DB::table('invoice_transactions')->insert(['invoice_id' => $invoice->id, 'gateway_id' => $this->gateway->id, 'amount' => 10,
                'transaction_id' => 'ch_hist_' . $i, 'status' => 'succeeded', 'is_credit_transaction' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->assertTrue(SavedMethodCharge::unresolved(ChargeAttempt::query()->pluck('invoice_id')->all())->isEmpty(), 'precondition: all recorded');

        DB::flushQueryLog();
        DB::enableQueryLog();
        (new AutoCharge)->run();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(20, $queries, "an AutoCharge run with nothing due and nothing unsettled ran {$queries} queries over the charge history");
    }
}
