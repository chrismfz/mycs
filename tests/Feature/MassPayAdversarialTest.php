<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Livewire\Invoices\PayTogether;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGroup;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\Mollie\Mollie;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use Tests\TestCase;

/**
 * Adversarial review of PR F2 (customer Mass Pay). Tests named test_held_* show an attack that is blocked; the others
 * fail on the branch and show what goes wrong.
 */
class MassPayAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(float $price, string $due, ?int $serviceId = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->modify($due)]);
        $invoice->items()->create(array_filter(['description' => 'Line', 'quantity' => 1, 'price' => $price,
            'reference_type' => $serviceId === null ? null : Service::class, 'reference_id' => $serviceId], fn ($value) => $value !== null));

        return $invoice->fresh();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10,
            'quantity' => 1, 'expires_at' => now()->addDays(5)]);
    }

    private function mollie(): Gateway
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Mollie'], ['name' => 'Mollie', 'type' => 'gateway']);
        $gateway->settings()->updateOrCreate(['key' => 'api_key'], ['value' => 'test_key']);
        (new Mollie(['api_key' => 'test_key']))->boot();

        return $gateway->fresh();
    }

    private function credit(): int
    {
        return (new CreditLedger)->balanceCents($this->user->client()->id, 'USD');
    }

    private function attention(string $subject = 'Payment needs attention'): int
    {
        return EmailLog::query()->where('subject', $subject)->count();
    }

    /** @return array{0: Invoice, 1: Invoice, 2: Invoice} 20.00 due first (the lead), 10.00, 15.00 */
    private function three(): array
    {
        return [$this->invoice(20, '+1 day'), $this->invoice(10, '+2 days'), $this->invoice(15, '+3 days')];
    }

    // ------------------------------------------------------------------ findings (fail on the branch)

    /**
     * F2-ADV-1. A PayPal subscription sale recorded on an invoice that leads a Mass Pay group the customer opened (and
     * never paid) is taken for the group's checkout: its money pays the group's other invoice before the subscription's
     * other service, which stays unpaid (suspended) although PayPal billed it. Any gateway excess on a lead is spread.
     */
    public function test_a_paypal_subscription_sale_on_a_group_lead_pays_the_subscription_not_the_group(): void
    {
        $a = $this->service(['subscription_id' => 'I-MASS']);
        $b = $this->service(['subscription_id' => 'I-MASS']);
        $saleOnA = $this->invoice(10, '+1 day', $a->id);
        $saleOnB = $this->invoice(10, '+1 day', $b->id);
        $unrelated = $this->invoice(10, '+2 days');
        $group = (new MassPay)->open($this->user->client(), [$saleOnA->id, $unrelated->id], $this->user); // chosen, never paid

        (new PayPal([]))->recordSubscriptionSale(['id' => 'SALE-MASS', 'billing_agreement_id' => 'I-MASS', 'amount' => ['total' => '20.00', 'currency' => 'USD']]);

        $this->assertSame(Invoice::STATUS_PAID, $saleOnA->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $saleOnB->fresh()->status, 'the subscription sale paid for service B, but its money went to the Mass Pay group');
        $this->assertSame(Invoice::STATUS_UNPAID, $unrelated->fresh()->status, 'an invoice outside the subscription was paid from the sale');
        $this->assertSame(PaymentGroup::STATUS_OPEN, $group->fresh()->status, 'a subscription sale is not the group\'s checkout');
    }

    /**
     * F2-ADV-2. The group's checkout lands after the customer paid the others apart (or a second checkout, or a payment
     * above the group total): the excess the spread cannot place stays account credit and staff are NOT told, because
     * addCredit() is silenced whenever a group matched. A plain overpayment tells staff (held control below).
     */
    public function test_an_excess_the_spread_could_not_place_still_tells_staff(): void
    {
        [$lead, $x, $y] = $this->three();
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $x->id, $y->id], $this->user);
        $this->mollie();
        ExtensionHelper::addPayment($x->id, 'Mollie', '10.00', transactionId: 'tr_x', currency: 'USD');
        ExtensionHelper::addPayment($y->id, 'Mollie', '15.00', transactionId: 'tr_y', currency: 'USD');

        ExtensionHelper::addPayment($lead->id, 'Mollie', '45.00', transactionId: 'tr_group', currency: 'USD', paymentGroup: $group->ulid);

        $this->assertSame(2500, $this->credit(), 'the customer paid X and Y twice');
        $this->assertSame(1, $this->attention(), 'a double payment that became credit is news for staff (a refund may be due)');
    }

    /** F2-ADV-2 (b): more than the group's total (a tampered PayPal Standard amount, a retried checkout): silent too. */
    public function test_a_payment_above_the_group_total_tells_staff_of_the_part_over(): void
    {
        [$lead, $x, $y] = $this->three();
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $x->id, $y->id], $this->user);
        $this->mollie();

        ExtensionHelper::addPayment($lead->id, 'Mollie', '60.00', transactionId: 'tr_over', currency: 'USD', paymentGroup: $group->ulid);

        $this->assertSame(1500, $this->credit());
        $this->assertSame(1, $this->attention(), '15.00 over the group\'s total became credit without a word to staff');
    }

    /**
     * F2-ADV-3. Staff delete an unpaid invoice of the group after the customer chose it: its pivot row goes by cascade,
     * staleness() checks only the rows left, so the checkout still opens for the old total (45.00). The customer is
     * charged for an invoice that no longer exists; the part becomes credit (silently, F2-ADV-2).
     */
    public function test_an_invoice_deleted_after_choosing_makes_the_group_stale(): void
    {
        [$lead, $x, $y] = $this->three();
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $x->id, $y->id], $this->user);
        $y->delete();

        $this->assertNotNull(MassPay::staleness($group->fresh()), 'the group still asks 45.00 for 30.00 of invoices');
    }

    /** F2-ADV-4. Staff delete the lead: invoice_id is nulled, staleness() passes, and payGroup() gets a null invoice (500). */
    public function test_a_deleted_lead_makes_the_group_stale_instead_of_crashing(): void
    {
        [$lead, $x, $y] = $this->three();
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $x->id, $y->id], $this->user);
        $gateway = $this->mollie();
        $lead->delete();
        Http::fake();

        $this->assertNotNull(MassPay::staleness($group->fresh()), 'the group lost the invoice it is paid on');
        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()])
            ->set('selectedMethod', 'gateway-' . $gateway->id)
            ->call('processPayment')
            ->assertNoRedirect();
        Http::assertNothingSent();
    }

    /**
     * F2-ADV-5. The checkout carries no reference to its group: two groups led by the same invoice with the same total
     * are told apart by «the newest». The customer pays the checkout opened for [lead, X]; after choosing [lead, Y] in
     * another tab (not paid), the money pays Y, X stays unpaid (its service is suspended) although it was on the page
     * the customer paid.
     */
    public function test_a_checkout_pays_the_invoices_of_its_own_group(): void
    {
        $lead = $this->invoice(20, '+1 day');
        $x = $this->invoice(10, '+2 days');
        $y = $this->invoice(10, '+3 days');
        $gateway = $this->mollie();
        $first = (new MassPay)->open($this->user->client(), [$lead->id, $x->id], $this->user);
        Http::fake([
            'https://api.mollie.com/v2/payments' => Http::response(['id' => 'tr_first', '_links' => ['checkout' => ['href' => 'https://mollie.test/checkout/tr_first']]]),
            'https://api.mollie.com/v2/payments/tr_first' => Http::response([
                // Mollie returns the metadata the checkout was opened with (the group, PR F2).
                'id' => 'tr_first', 'status' => 'paid', 'amount' => ['value' => '30.00', 'currency' => 'USD'], 'metadata' => ['invoice_id' => $lead->id, 'payment_group' => $first->ulid],
            ]),
        ]);
        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $first])
            ->set('selectedMethod', 'gateway-' . $gateway->id)
            ->call('processPayment')
            ->assertRedirect('https://mollie.test/checkout/tr_first');

        (new MassPay)->open($this->user->client(), [$lead->id, $y->id], $this->user); // another tab, never paid
        $this->post('/extensions/gateways/mollie/webhook', ['id' => 'tr_first'])->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $x->fresh()->status, 'the checkout the customer paid listed X');
        $this->assertSame(Invoice::STATUS_UNPAID, $y->fresh()->status);
    }

    // ------------------------------------------------------------------ held

    /** Held: a plain overpayment (no group) tells staff: the control for F2-ADV-2. */
    public function test_held_a_plain_overpayment_tells_staff(): void
    {
        [$lead] = $this->three();
        $this->mollie();

        ExtensionHelper::addPayment($lead->id, 'Mollie', '45.00', transactionId: 'tr_plain', currency: 'USD');

        $this->assertSame(2500, $this->credit());
        $this->assertSame(1, $this->attention());
    }

    /** Held: two checkouts of one group both paid: the second becomes credit, nothing is paid twice, the journal holds. */
    public function test_held_two_paid_checkouts_of_one_group_do_not_spread_twice(): void
    {
        [$lead, $x, $y] = $this->three();
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $x->id, $y->id], $this->user);
        $this->mollie();

        ExtensionHelper::addPayment($lead->id, 'Mollie', '45.00', transactionId: 'tr_one', currency: 'USD', paymentGroup: $group->ulid);
        ExtensionHelper::addPayment($lead->id, 'Mollie', '45.00', transactionId: 'tr_two', currency: 'USD', paymentGroup: $group->ulid);

        $this->assertSame(4500, $this->credit());
        $this->assertSame(2, InvoiceTransaction::query()->whereNotNull('source_transaction_id')->count());
        $this->assertSame([], (new MoneyCheck)->run());
    }

    /** Held: an older debt (negative credit) stays a debt; the payment pays the chosen invoices and makes no credit. */
    public function test_held_a_debt_is_not_eaten_and_no_credit_is_made(): void
    {
        (new CreditLedger)->remove($this->user->client()->id, 'USD', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Debt', mayGoNegative: true);
        [$lead, $x, $y] = $this->three();
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $x->id, $y->id], $this->user);
        $this->mollie();

        ExtensionHelper::addPayment($lead->id, 'Mollie', '45.00', transactionId: 'tr_debt', currency: 'USD', paymentGroup: $group->ulid);

        $this->assertSame(['paid', 'paid', 'paid'], [$lead->fresh()->status, $x->fresh()->status, $y->fresh()->status]);
        $this->assertSame(-5000, $this->credit());
        $this->assertSame(['credit_negative'], array_column((new MoneyCheck)->run(), 'rule'), 'only the debt that was there');
    }

    /** Held: a payment smaller than the group's total pays in order and never pushes credit below zero. */
    public function test_held_a_short_payment_pays_in_order_and_makes_no_debt(): void
    {
        [$lead, $x, $y] = $this->three();
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $x->id, $y->id], $this->user);
        $this->mollie();

        ExtensionHelper::addPayment($lead->id, 'Mollie', '35.00', transactionId: 'tr_short', currency: 'USD', paymentGroup: $group->ulid);

        $this->assertSame(['paid', 'paid', 'unpaid'], [$lead->fresh()->status, $x->fresh()->status, $y->fresh()->status]);
        $this->assertSame(1000, (int) round($y->fresh()->remaining * 100));
        $this->assertSame(0, $this->credit());
        $this->assertSame([], (new MoneyCheck)->run());
    }

    /** Held: the group page cannot be pointed at another group by tampering the locked property. */
    public function test_held_the_group_property_is_locked(): void
    {
        [$lead, $x] = $this->three();
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $x->id], $this->user);

        $this->expectException(\Throwable::class);
        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group])->set('paymentGroup', 999);
    }

    /** Held: a chargeback of the whole group payment takes back the lead's part and tells staff of the rest. */
    public function test_held_a_chargeback_after_the_spread_tells_staff(): void
    {
        [$lead, $x, $y] = $this->three();
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $x->id, $y->id], $this->user);
        $this->mollie();
        ExtensionHelper::addPayment($lead->id, 'Mollie', '45.00', transactionId: 'tr_cb', currency: 'USD', paymentGroup: $group->ulid);

        ExtensionHelper::chargebackAtGateway('Mollie', 'tr_cb', 'chb_1', 45.00, 'USD');

        $this->assertSame(Invoice::STATUS_UNPAID, $lead->fresh()->status);
        $this->assertSame(1, $this->attention('Chargeback needs staff attention'));
        $this->assertSame([], (new MoneyCheck)->run());
    }

    /** Held: a staff entry on the lead is never spread (only a gateway checkout is). */
    public function test_held_a_staff_entry_with_a_gateway_is_not_spread(): void
    {
        [$lead, $x, $y] = $this->three();
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $x->id, $y->id], $this->user);
        $gateway = $this->mollie();

        (new RecordPaymentService)->recordByStaff($lead, $gateway, '45.00', 'typed-1');

        $this->assertSame(['paid', 'unpaid', 'unpaid'], [$lead->fresh()->status, $x->fresh()->status, $y->fresh()->status]);
        $this->assertSame(PaymentGroup::STATUS_OPEN, $group->fresh()->status);
    }
}
