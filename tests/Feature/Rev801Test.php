<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\ModuleAction;
use App\Models\PaymentGroup;
use App\Models\Product;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\CancelOrder;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #801 (the four open items of the sweep-8 reviews). Each test fails on the PR's head for the
 * reason its docblock gives; «Should» says what is expected instead. Nothing is fixed here.
 */
class Rev801Test extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Gateway $stripe;

    private BillingAgreement $card;

    /** @var list<string|null> the idempotency keys of the charges that reached Stripe */
    private array $keys = [];

    private string $mode = 'timeout';

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => false, 'settings.mass_pay_enabled' => true]);
        MailFacade::fake();
        Queue::fake();
        Cache::flush();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-11 09:00:00');
        $this->client = User::factory()->create()->client();
        $this->stripe = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $this->card = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->stripe->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->client->properties()->updateOrCreate(['key' => 'stripe_id'], ['value' => 'cus_1']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/customers/')) {
                return Http::response(['id' => 'cus_1']);
            }
            $this->keys[] = $request->header('Idempotency-Key')[0] ?? null;

            return $this->mode === 'timeout' ? throw new ConnectionException('cURL error 28: Operation timed out')
                : Http::response(['id' => 'pi_1', 'status' => 'succeeded']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $amount, string $due): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Hosting', 'price' => $amount, 'quantity' => 1]);

        return $invoice->fresh();
    }

    /** A part of the invoice paid through Stripe earlier the same day (a checkout, or staff's «Add payment»). */
    private function paidEarlierToday(Invoice $invoice, string $amount): void
    {
        Carbon::setTestNow('2026-11-11 08:00:00');
        InvoiceTransaction::query()->create(['invoice_id' => $invoice->id, 'gateway_id' => $this->stripe->id, 'amount' => $amount,
            'transaction_id' => 'pi_earlier', 'status' => InvoiceTransactionStatus::Succeeded]);
        Carbon::setTestNow('2026-11-11 09:00:00');
    }

    private function charge(Invoice $invoice, ?PaymentGroup $group = null): ?ChargeAttempt
    {
        try {
            return (new SavedMethodCharge)->charge($invoice->fresh(), $this->card, $group === null ? ChargeAttempt::SOURCE_CLIENT : ChargeAttempt::SOURCE_GROUP, $group?->fresh())->fresh();
        } catch (DisplayException) {
            return null;
        }
    }

    /**
     * REV-801-1 (money; introduced by #801). The PR relies on the hold: «the other one is not this charge's to resend: it
     * holds the invoice (unresolved), and charge() refuses». The hold and unknownAttempt() do not read the same rows: the
     * hold leaves out an attempt with a payment of at least its amount on its invoice, through its gateway, from the DAY it
     * was sent (notRecorded), also a payment made BEFORE the charge. unknownAttempt() has no such filter. So a group's
     * unknown charge that the hold does not see was, before #801, still found by the invoice's own charge of the same total
     * and sent again with ITS key (Stripe answers that with an idempotency error: no money). With the narrowed match it is
     * neither found nor held, and a NEW charge with a NEW key goes out while the group's charge may still land.
     *
     * Scenario: invoice A of 16.00, 10.00 of it paid through Stripe at 08:00; at 09:00 the customer pays A (6.00 left) and B
     * (4.00) together with the saved card: a timeout, unknown, 10.00. Staff then change A so that it alone owes 10.00, and
     * the customer (or the cron) charges A alone at 09:20.
     *
     * Should: nothing leaves with another key while the group's charge of unknown outcome is unsettled (resent with its
     * key as on main, or refused).
     */
    public function test_rev_801_1_a_groups_unknown_charge_the_hold_misses_is_followed_by_a_new_key_on_its_lead(): void
    {
        $a = $this->invoice('16.00', '2026-11-09');
        $b = $this->invoice('4.00', '2026-11-10');
        $this->paidEarlierToday($a, '10.00');
        $this->assertSame('6.00', number_format((float) $a->fresh()->remaining, 2, '.', ''), 'precondition: 6.00 left on A');

        $group = (new MassPay)->open($this->client, [$a->id, $b->id]);
        $ofGroup = $this->charge($a, $group);
        $this->assertNotNull($ofGroup);
        $this->assertSame([ChargeAttempt::STATUS_UNKNOWN, $group->id, '10.00'], [$ofGroup->status, $ofGroup->payment_group_id, $ofGroup->amount]);

        $a->items->each->update(['price' => '20.00']); // staff: A alone now owes the group's total
        $this->assertSame('10.00', number_format((float) $a->fresh()->remaining, 2, '.', ''));
        $this->mode = 'ok';
        Carbon::setTestNow('2026-11-11 09:20:00');
        $this->charge($a);

        $this->assertSame([$ofGroup->key], array_values(array_unique($this->keys)),
            'a charge of A left with a new idempotency key while the group\'s charge of unknown outcome (which pays A too) is not settled');
        $this->assertSame(1, ChargeAttempt::query()->count(), 'a second attempt was written next to the unknown one');
    }

    /**
     * REV-801-2 (money; already on main, what REV-801-1 stands on). «None of them is released by time: only by the payment
     * recorded or by staff» (SavedMethodCharge::unresolved). A charge of unknown outcome does not hold its invoice at all
     * when a payment of at least its amount was recorded on the invoice through the same gateway EARLIER the same day
     * (notRecorded compares created_at with DATE(sent_at)): that payment is read as the charge's own. Within the key's
     * window the invoice's own charge is still resent with its key; past it (23 h Stripe, 3 h PayPal), or as a Mass Pay
     * group, a new charge with a new key goes out though the first may have been taken.
     *
     * Scenario: invoice of 30.00, 20.00 paid through Stripe at 08:00; the saved card is charged the 10.00 left at 09:00: a
     * timeout. The next day (the key no longer reused) the customer or the cron charges it again.
     *
     * Should: the invoice is held by its unknown charge (a payment made before the charge was sent is not its payment), and
     * nothing is sent with a new key until it is settled.
     */
    public function test_rev_801_2_an_unknown_charge_does_not_hold_after_an_earlier_payment_of_the_same_day(): void
    {
        $this->markTestIncomplete('open, awaiting the owner (2026-11-11): the hold counts a same-day payment made before the charge; the fix reverses ADV-UNRES-4 for staff-dated payments');
        $invoice = $this->invoice('30.00', '2026-11-09');
        $this->paidEarlierToday($invoice, '20.00');
        $first = $this->charge($invoice);
        $this->assertNotNull($first);
        $this->assertSame([ChargeAttempt::STATUS_UNKNOWN, '10.00'], [$first->status, $first->amount]);

        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()), 'an unknown charge of 10.00 does not hold the invoice: the 20.00 paid an hour before it is read as its payment');

        $this->mode = 'ok';
        Carbon::setTestNow('2026-11-12 10:00:00'); // past the key's window
        $this->charge($invoice);
        $this->assertSame([$first->key], $this->keys, 'a new charge with a new key was sent while the first one\'s outcome is unknown');
    }

    /**
     * REV-801-3 (words told to staff; the lookup is main's, the false sentence is new with #801). The task and the notice
     * of a registered domain say «nothing was refunded» unless money of the order's invoice went back «by this click or
     * before it». «Before it» is read from `refunded_amount` only: a refund to the customer's credit (the invoice's page,
     * «Refund to credit») is kept in `credited_amount`, so after it «Cancel Order» tells staff that nothing was refunded.
     * The same refund made by the click itself («Cancel & Refund», to credit) does name the refund.
     *
     * Scenario: an order of 10.00 with a registered domain, paid; staff refund the payment to the customer's credit from
     * the invoice; later «Cancel Order».
     *
     * Should: the text does not claim that nothing was refunded (the customer has the money back, as credit).
     */
    public function test_rev_801_3_cancel_order_after_a_refund_to_credit_says_nothing_was_refunded(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct()->product;
        $product->update(['autosetup' => Product::AUTOSETUP_PAYMENT, 'server_id' => null, 'stock' => 10]);
        $product = $product->fresh();
        $order = (new PlaceOrderService)->place($user->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')])->order->fresh();
        $invoice = Invoice::query()->findOrFail($order->invoice_id);
        $domain = Domain::factory()->create(['client_id' => $user->client()->id, 'order_id' => $order->id, 'sld' => 'rev801', 'tld' => 'test',
            'fqdn' => 'rev801.test', 'status' => DomainStatus::Active]);
        (new RecordPaymentService)->handle($invoice->fresh(), null, 10.00, transactionId: 'bank-1');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        ExtensionHelper::refundToCredit($invoice->transactions()->sole(), 10.00, notify: false);
        $this->assertSame('10.00', (string) $invoice->transactions()->sole()->credited_amount, 'precondition: the whole payment went back, as credit');

        $left = CancelOrder::handle($order->fresh());

        $task = ModuleAction::query()->where('domain_id', $domain->id)->where('action', ModuleAction::DOMAIN_REFUNDED)->sole();
        foreach ([(string) $task->last_error, $left["domain #{$domain->id}"]->getMessage()] as $text) {
            $this->assertStringNotContainsString('nothing was refunded', $text, 'staff are told nothing was refunded though the whole payment went back to the customer\'s credit');
        }
    }
}
