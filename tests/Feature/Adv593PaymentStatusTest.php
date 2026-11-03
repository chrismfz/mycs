<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Invoices\Show;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Invoice\PaymentStatus;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Str;
use MyCS\Extensions\Gateways\Adv593Pay\Adv593Pay;
use Tests\TestCase;

/**
 * Adversarial review of PR #593 (the invoice's status card, docs/pay-flow-ux.md PR 2). Each test names its finding
 * (ADV-593-n), its severity and the scenario. Those marked «pins» passed on the PR head and keep a rule in place.
 */
class Adv593PaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Gateway $gateway;

    private BillingAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '',
            'settings.autocharge_max_failures' => 0, 'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true,
            'settings.mass_pay_enabled' => true]);
        if (!class_exists(Adv593Pay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\Adv593Pay; class Adv593Pay {
                /** @var list<array{invoice: int, day: string}> */
                public static array $charges = [];
                /** @var \\Closure|null */
                public static $answer = null;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function supportsPaymentGroups() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    self::$charges[] = ["invoice" => $invoice->id, "day" => now()->toDateString()];
                    return self::$answer === null ? true : (self::$answer)($invoice, $amount);
                } }');
        }
        Adv593Pay::$charges = [];
        Adv593Pay::$answer = null;
        MailFacade::fake();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $this->gateway = Gateway::query()->firstOrCreate(['extension' => 'Adv593Pay'], ['name' => 'Adv593Pay', 'type' => 'gateway', 'enabled' => true]);
        $this->agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_593', 'type' => 'card']);
        $this->client->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An open renewal invoice of an active service, due (and the service expiring) on $due. */
    private function invoice(string $due = '2026-11-12', ?Client $client = null): Invoice
    {
        $client ??= $this->client;
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'subscription_id' => null, 'expires_at' => $due]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    /** An invoice the cron never charges: staff made it (no service line). */
    private function manual(?string $due = '2026-11-12', ?Client $client = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => ($client ?? $this->client)->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Setup', 'price' => '10.00', 'quantity' => 1]);

        return $invoice->fresh();
    }

    private function attempt(Invoice $invoice, string $status, string $source = ChargeAttempt::SOURCE_CLIENT, ?BillingAgreement $agreement = null, ?int $group = null): ChargeAttempt
    {
        $agreement ??= $this->agreement;

        return ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'client_id' => $agreement->client_id,
            'billing_agreement_id' => $agreement->id, 'payment_group_id' => $group, 'amount' => '10.00', 'currency_code' => 'USD', 'source' => $source,
            'status' => $status, 'started_at' => now(), 'sent_at' => now()]);
    }

    private function processing(Invoice $invoice, ?int $gatewayId = null): int
    {
        return DB::table('invoice_transactions')->insertGetId(['invoice_id' => $invoice->id, 'gateway_id' => $gatewayId ?? $this->gateway->id, 'amount' => 10,
            'status' => InvoiceTransactionStatus::Processing->value, 'transaction_id' => 'sepa_' . Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function payButton(Invoice $invoice): string
    {
        return e(__('invoices.pay_amount', ['amount' => $invoice->formattedRemaining]));
    }

    private function declineAll(): void
    {
        Adv593Pay::$answer = fn () => throw new DisplayException('Card declined');
    }

    private function cronOn(string $day): void
    {
        Carbon::setTestNow($day . ' 09:00:00');
        (new AutoCharge)->run();
    }

    /** @return list<string> the days the cron sent a charge of $invoice */
    private function chargeDays(Invoice $invoice): array
    {
        return array_values(array_unique(array_column(array_filter(Adv593Pay::$charges, fn ($charge) => $charge['invoice'] === $invoice->id), 'day')));
    }

    /**
     * ADV-593-1 (Medium): charged days before its due date (autocharge_days_before 3) and declined, with a retry every day
     * (autocharge_retry_days empty). chargeOne retries every day from the next one (retryOn of a negative offset is true
     * with no retry days set), but nextAttempt starts its loop at max(0, …), the due date: the card says «We will try
     * again on 13/11» and the cron charges the card on 11/11.
     */
    public function test_adv593_1_a_decline_before_the_due_date_is_retried_on_the_day_the_card_says(): void
    {
        config(['settings.autocharge_days_before' => 3]);
        $invoice = $this->invoice('2026-11-13');
        $this->declineAll();
        $this->cronOn('2026-11-10');
        $this->assertSame(['2026-11-10'], $this->chargeDays($invoice), 'precondition: tried 3 days before its due date');

        $promised = AutoCharge::nextAttempt($invoice->fresh())?->toDateString();
        $status = PaymentStatus::for($invoice->fresh());
        $this->assertSame(PaymentStatus::RETRY, $status->state);

        $this->cronOn('2026-11-11');
        $this->assertSame(['2026-11-10', '2026-11-11'], $this->chargeDays($invoice), 'the cron retried the next day');

        $this->assertSame('2026-11-11', $promised, 'the card promised another day than the one the cron charges');
    }

    /**
     * ADV-593-2 (High): retry days after the suspension day (cronjob_order_suspend 3, autocharge_retry_days «5»), suspension
     * on. The card (and willCharge, so the reminders and the bank details) says «We will try again on 15/11», but the
     * service is suspended on 14/11 and the run never picks an invoice of a suspended service (due()): no charge comes.
     * docs/payments.md: retried «until it is paid or the service is suspended».
     */
    public function test_adv593_2_no_retry_is_promised_after_the_service_is_suspended(): void
    {
        config(['settings.cronjob_order_suspend' => 3, 'settings.autocharge_retry_days' => '5']);
        $invoice = $this->invoice('2026-11-10');
        $this->declineAll();
        $this->cronOn('2026-11-10');

        Carbon::setTestNow('2026-11-11 09:00:00');
        $promised = AutoCharge::nextAttempt($invoice->fresh())?->toDateString();
        $willCharge = AutoCharge::willCharge($invoice->fresh());
        $state = PaymentStatus::for($invoice->fresh())->state;

        foreach (['2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15'] as $day) {
            Carbon::setTestNow($day . ' 09:00:00');
            $this->artisan('app:cron-job');
        }
        $service = Service::query()->findOrFail($invoice->items()->value('reference_id'));
        $this->assertSame(Service::STATUS_SUSPENDED, $service->status, 'precondition: suspended on day 4');
        $this->assertSame(['2026-11-10'], $this->chargeDays($invoice), 'precondition: the cron never retried it');

        $this->assertNull($promised, 'the card promised a retry on ' . $promised . ' that never comes');
        $this->assertFalse($willCharge, 'willCharge said the cron charges it');
        $this->assertNotSame(PaymentStatus::RETRY, $state);
    }

    /**
     * ADV-593-3 (Medium): a charge taken by the gateway, its payment not recorded yet (TAKEN). The card says «no other
     * payment can be made for this invoice until it is settled» and hides the button, but processPayment with credit
     * does not check the hold (RecordPaymentService::payWithCredit): a crafted Livewire call pays it a second time from
     * the balance. docs/payments.md «Χρέωση που δεν έχει διευθετηθεί»: it holds the invoice «for every method and every way
     * of paying».
     */
    public function test_adv593_3_a_held_invoice_is_not_paid_from_the_balance_by_a_crafted_call(): void
    {
        config(['settings.credits_enabled' => true]);
        Credit::query()->create(['client_id' => $this->client->id, 'currency_code' => 'USD', 'amount' => '50.00']);
        $invoice = $this->manual();
        $this->attempt($invoice, ChargeAttempt::STATUS_SUCCEEDED, ChargeAttempt::SOURCE_CLIENT);
        $this->assertSame(PaymentStatus::TAKEN, PaymentStatus::for($invoice)->state, 'precondition: held');

        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->assertDontSeeHtml($this->payButton($invoice))
            ->set('selectedMethod', 'credit')->call('processPayment');

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'paid from the balance while the card charge is taken');
        $this->assertEquals(50.00, (float) Credit::query()->where('client_id', $this->client->id)->value('amount'));
    }

    /**
     * ADV-593-4 (Medium): an invoice moved by staff to another account (allowed: nothing on its way, no payment). Its last
     * charge attempt is the first account's, declined with «confirm with your bank» (action_required). The new account's
     * page reads that attempt (any account's) and shows the first account's method name, and tells the new customer
     * their bank asks them to confirm a charge they never made.
     */
    public function test_adv593_4_another_accounts_attempt_is_not_shown_after_a_move(): void
    {
        $other = User::factory()->create()->client();
        $theirs = BillingAgreement::query()->create(['client_id' => $other->id, 'gateway_id' => $this->gateway->id,
            'name' => 'PayPal first.owner@example.test', 'external_reference' => 'ba_other', 'type' => 'paypal']);
        $invoice = $this->manual('2026-11-12', $other);
        $this->attempt($invoice, ChargeAttempt::STATUS_ACTION_REQUIRED, ChargeAttempt::SOURCE_CLIENT, $theirs);

        $invoice->update(['client_id' => $this->client->id]);
        $this->assertSame($this->client->id, $invoice->fresh()->client_id, 'precondition: moved');

        $status = PaymentStatus::for($invoice->fresh());
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice->fresh()])
            ->assertDontSee('first.owner@example.test');
        $this->assertNotSame(PaymentStatus::ACTION, $status->state, 'the new account was never asked to confirm anything');
    }

    /**
     * ADV-593-5 (Low): a Mass Pay group (A, B) the customer tried to pay with a card and was declined (the failed attempt
     * sits on A, the lead). Later they pay A alone by SEPA (processing, A's own checkout). B is shown «A payment that pays
     * this invoice together with others is being checked», with no button, and the server refuses it too, for the days the
     * SEPA payment takes: onItsWay counts any processing payment of a lead of any attempt of the group, a declined one
     * included. ADV-UNRES-5 already says a charge of one invoice alone does not hold another.
     */
    public function test_adv593_5_a_declined_group_charge_does_not_hold_the_others_behind_the_leads_own_payment(): void
    {
        $a = $this->manual('2026-11-11');
        $b = $this->manual('2026-11-12');
        $group = (new MassPay)->open($this->client, [$a->id, $b->id], $this->user);
        $this->assertSame($a->id, $group->invoice_id, 'precondition: A leads');
        $this->attempt($a, ChargeAttempt::STATUS_FAILED, ChargeAttempt::SOURCE_CLIENT, null, $group->id);
        $this->processing($a);

        $this->assertSame(PaymentStatus::PROCESSING, PaymentStatus::for($a->fresh())->state, 'precondition: A is being paid');
        $this->assertFalse(SavedMethodCharge::onItsWay($b->fresh()), 'nothing on its way pays B');
        $this->assertTrue(PaymentStatus::for($b->fresh())->payable(), 'B is left with no way to pay');
    }

    /**
     * ADV-593-6 (pins, Medium): the card's date is the day the cron really charges, for the cases that agree today: not
     * tried yet (on its due date, autocharge_days_before earlier, or today when overdue), declined and retried every day,
     * on the retry days set, and nothing after the last retry day. A day-by-day run, not a reading of the code.
     */
    public function test_adv593_6_pins_the_next_attempt_against_a_day_by_day_run(): void
    {
        $cases = [
            ['days_before' => 0, 'retry' => '', 'due' => '2026-11-14', 'decline_first' => false],
            ['days_before' => 2, 'retry' => '', 'due' => '2026-11-14', 'decline_first' => false],
            ['days_before' => 0, 'retry' => '', 'due' => '2026-11-08', 'decline_first' => false],
            ['days_before' => 0, 'retry' => '', 'due' => '2026-11-10', 'decline_first' => true],
            ['days_before' => 0, 'retry' => '2,5', 'due' => '2026-11-10', 'decline_first' => true],
            ['days_before' => 0, 'retry' => '1', 'due' => '2026-11-09', 'decline_first' => true],
        ];
        foreach ($cases as $i => $case) {
            Adv593Pay::$charges = [];
            Carbon::setTestNow('2026-11-10 09:00:00');
            config(['settings.autocharge_days_before' => $case['days_before'], 'settings.autocharge_retry_days' => $case['retry']]);
            $invoice = $this->invoice($case['due']);
            $this->declineAll();
            if ($case['decline_first']) {
                (new AutoCharge)->run();
                Carbon::setTestNow('2026-11-11 09:00:00');
            }
            $from = Carbon::now()->toDateString();
            $promised = AutoCharge::nextAttempt($invoice->fresh())?->toDateString();
            $before = count($this->chargeDays($invoice));
            $actual = null;
            for ($day = Carbon::parse($from); $day->lte(Carbon::parse('2026-11-30')) && $actual === null; $day = $day->copy()->addDay()) {
                $this->cronOn($day->toDateString());
                if (count($this->chargeDays($invoice)) > $before) {
                    $actual = $day->toDateString();
                }
            }
            $this->assertSame($actual, $promised, "case {$i}: " . json_encode($case));
            Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_CANCELLED]);
        }
    }

    /**
     * ADV-593-7 (pins, Medium): no state crashes the page or leaks: refunded, cancelled, no due date, a held charge whose
     * method was deleted, a processing payment of a deleted gateway, paid with no payment row, paid with credit, and
     * another currency.
     */
    public function test_adv593_7_pins_edge_states_render(): void
    {
        $refunded = $this->manual();
        Invoice::query()->whereKey($refunded->id)->update(['status' => Invoice::STATUS_REFUNDED]);
        $cancelled = $this->manual();
        Invoice::query()->whereKey($cancelled->id)->update(['status' => Invoice::STATUS_CANCELLED]);
        $noDue = $this->manual(null);
        $paidBare = $this->manual();
        Invoice::query()->whereKey($paidBare->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => null]);

        $heldDeleted = $this->invoice();
        $gone = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->gateway->id,
            'name' => 'Old card', 'external_reference' => 'pm_gone', 'type' => 'card']);
        $this->attempt($heldDeleted, ChargeAttempt::STATUS_UNKNOWN, ChargeAttempt::SOURCE_CRON, $gone);
        DB::table('billing_agreements')->where('id', $gone->id)->delete();

        $processingNoGateway = $this->manual();
        DB::table('invoice_transactions')->insert(['invoice_id' => $processingNoGateway->id, 'gateway_id' => null, 'amount' => 10,
            'status' => InvoiceTransactionStatus::Processing->value, 'transaction_id' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        $eur = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-11-12']);
        $eur->items()->create(['description' => 'Setup', 'price' => '10.00', 'quantity' => 1]);

        $expected = [
            [$refunded, PaymentStatus::REFUNDED], [$cancelled, PaymentStatus::CANCELLED], [$noDue, PaymentStatus::UNPAID],
            [$paidBare, PaymentStatus::PAID], [$heldDeleted, PaymentStatus::HELD], [$processingNoGateway, PaymentStatus::PROCESSING],
            [$eur, PaymentStatus::DUE],
        ];
        foreach ($expected as [$invoice, $state]) {
            $status = PaymentStatus::for($invoice->fresh());
            $this->assertSame($state, $status->state, "invoice #{$invoice->id}");
            $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice->fresh()])->assertOk()->assertSee($status->title());
        }
    }

    /**
     * ADV-593-8 (pins, Low): the 30-second poll of a held charge tells nothing while it is held, tells «paid» once when its
     * payment is recorded, and then stops; ?pay=1 and a crafted $set of showPayModal never open the window while held.
     */
    public function test_adv593_8_pins_the_poll_of_a_held_charge(): void
    {
        $invoice = $this->manual();
        $attempt = $this->attempt($invoice, ChargeAttempt::STATUS_SUCCEEDED);

        $page = $this->livewireAs($this->user)->withQueryParams(['pay' => 1])->test(Show::class, ['invoice' => $invoice]);
        $page->assertSet('showPayModal', false)->assertSeeHtml('wire:poll.30s="checkPaymentStatus"');
        $page->set('showPayModal', true)->assertSet('showPayModal', false);
        $page->call('checkPaymentStatus')->assertNotDispatched('notify');
        Carbon::setTestNow(now()->addSeconds(30));
        $page->call('checkPaymentStatus')->assertNotDispatched('notify');

        // Its payment recorded (the webhook): the hold ends, the invoice is paid.
        ExtensionHelper::addPayment($invoice->id, 'Adv593Pay', 10, null, 'ch_593', currency: 'USD');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        Carbon::setTestNow(now()->addSeconds(30));
        $page->call('checkPaymentStatus')->assertDispatched('notify')->assertDontSeeHtml('wire:poll');
        $this->assertNotNull($attempt);
    }
}
