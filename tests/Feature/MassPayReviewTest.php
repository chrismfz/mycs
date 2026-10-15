<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Invoices\Index;
use App\Livewire\Invoices\PayTogether;
use App\Models\ClientUser;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\Mollie\Mollie;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use Tests\TestCase;

/**
 * Independent code review of PR F2 (client Mass Pay). Tests without the `_guard` suffix fail on the PR as written and
 * prove a finding (CR-n); `_guard` tests pin behaviour that was checked and holds.
 */
class MassPayReviewTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(float $price, string $due, ?Service $service = null, ?User $user = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => ($user ?? $this->user)->client()->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD', 'due_at' => now()->modify($due)]);
        $invoice->items()->create(array_filter(['description' => 'Line', 'quantity' => 1, 'price' => $price,
            'reference_type' => $service ? Service::class : null, 'reference_id' => $service?->id], fn ($value) => $value !== null));

        return $invoice->fresh();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(array_merge(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10,
            'expires_at' => now()->addDays(5)], $attributes));
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

    private function attention(): int
    {
        return EmailLog::query()->where('subject', 'Payment needs attention')->count();
    }

    // ------------------------------------------------------------------ findings

    /**
     * CR-1: groupPaidBy() falls back to «the newest group» led by the invoice whatever the amount. A second payment of
     * the lead on its own (two tabs, a stale checkout) is spread over the group and marks it paid; the group's real
     * checkout then finds no group and becomes credit: the customer paid 50 for 30 of invoices and one stays unpaid.
     */
    public function test_an_unrelated_overpayment_of_the_lead_does_not_take_the_group(): void
    {
        $lead = $this->invoice(10, '+1 day');
        $other = $this->invoice(20, '+2 days');
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $other->id], $this->user);
        $this->mollie();

        ExtensionHelper::addPayment($lead->id, 'Mollie', '10.00', transactionId: 'tr_single_1', currency: 'USD');
        ExtensionHelper::addPayment($lead->id, 'Mollie', '10.00', transactionId: 'tr_single_2', currency: 'USD'); // paid twice on its own page
        ExtensionHelper::addPayment($lead->id, 'Mollie', '30.00', transactionId: 'tr_group', currency: 'USD', paymentGroup: $group->ulid); // the group's checkout

        $this->assertSame(Invoice::STATUS_PAID, $other->fresh()->status, 'the group\'s own 30.00 must pay the invoice it was chosen for');
        $this->assertSame(2000, $this->credit());
        $this->assertSame((int) InvoiceTransaction::query()->where('transaction_id', 'tr_group')->value('id'), (int) $group->fresh()->invoice_transaction_id);
    }

    /**
     * CR-1b: same root cause, a PayPal subscription sale. The sale lands on the subscription's oldest open invoice; when
     * that invoice leads an open (unpaid) group, what the sale leaves for the subscription's other service is spread over
     * the group's unrelated invoice, and the subscription's other invoice is left unpaid.
     */
    public function test_a_subscription_sale_on_a_group_lead_pays_the_subscription_not_the_group(): void
    {
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        $a = $this->service(['subscription_id' => 'I-REVIEW']);
        $b = $this->service(['subscription_id' => 'I-REVIEW']);
        $c = $this->service();
        $invoiceA = $this->invoice(10, '+1 day', $a);
        $invoiceB = $this->invoice(10, '+5 days', $b);
        $invoiceC = $this->invoice(10, '+2 days', $c);
        $group = (new MassPay)->open($this->user->client(), [$invoiceA->id, $invoiceC->id], $this->user);
        $this->assertSame((int) $invoiceA->id, (int) $group->invoice_id);

        (new PayPal([]))->recordSubscriptionSale(['id' => 'SALE-REVIEW', 'billing_agreement_id' => 'I-REVIEW', 'amount' => ['total' => '20.00', 'currency' => 'USD']]);

        $this->assertSame(Invoice::STATUS_PAID, $invoiceA->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoiceB->fresh()->status, 'the subscription sale pays the subscription\'s services');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoiceC->fresh()->status, 'the customer never paid the group');
        $this->assertSame(PaymentGroup::STATUS_OPEN, $group->fresh()->status);
    }

    /**
     * CR-2: staleness() reads the pivot, which loses a row when staff delete an invoice (cascade). The rest still
     * match, so the gateway is opened for the old total, which includes the deleted invoice.
     */
    public function test_a_deleted_invoice_makes_the_group_stale(): void
    {
        $lead = $this->invoice(10, '+1 day');
        $other = $this->invoice(20, '+2 days');
        $third = $this->invoice(30, '+3 days');
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $other->id, $third->id], $this->user);
        $gateway = $this->mollie();
        $third->delete();
        Http::fake();

        $this->assertNotNull(MassPay::staleness($group->fresh()), 'the 60.00 total still holds the deleted invoice\'s 30.00');

        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group])
            ->set('selectedMethod', 'gateway-' . $gateway->id)
            ->call('processPayment');
        Http::assertNothingSent();
    }

    /** CR-2b: the lead deleted: invoice_id is nulled, staleness() says nothing, and payGroup() hands the gateway null. */
    public function test_a_deleted_lead_makes_the_group_stale(): void
    {
        $lead = $this->invoice(10, '+1 day');
        $other = $this->invoice(20, '+2 days');
        $third = $this->invoice(30, '+3 days');
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $other->id, $third->id], $this->user);
        $lead->delete();

        $this->assertNotNull(MassPay::staleness($group->fresh()));
    }

    /**
     * CR-3: refusal() only looks at the invoice's own transactions. A group's processing payment (SEPA) sits on the lead
     * only, so its other invoices can be chosen again (or paid alone) while that money is on its way: the customer pays
     * them twice and the second payment ends as credit.
     */
    public function test_invoices_of_a_group_whose_payment_is_processing_cannot_be_chosen_again(): void
    {
        $lead = $this->invoice(10, '+1 day');
        $other = $this->invoice(20, '+2 days');
        $third = $this->invoice(30, '+3 days');
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $other->id, $third->id], $this->user);
        $this->mollie();
        ExtensionHelper::addProcessingPayment($group->invoice_id, 'Mollie', '60.00', transactionId: 'tr_sepa', currency: 'USD', paymentGroup: $group->ulid);

        $this->expectException(DisplayException::class);
        (new MassPay)->open($this->user->client(), [$other->id, $third->id], $this->user);
    }

    /**
     * CR-4: addCredit(spread: true) silences the staff notice for the whole excess, also for the part the spread could
     * not use (an invoice paid meanwhile). With auto-use off that money sits as credit and nobody is told.
     */
    public function test_what_the_spread_leaves_as_credit_is_still_news_for_staff(): void
    {
        $lead = $this->invoice(10, '+1 day');
        $other = $this->invoice(20, '+2 days');
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $other->id], $this->user);
        (new RecordPaymentService)->handle($other, null, '20.00', transactionId: 'paid-apart');
        $this->mollie();

        ExtensionHelper::addPayment($group->invoice_id, 'Mollie', '30.00', transactionId: 'tr_late', currency: 'USD', paymentGroup: $group->ulid);

        $this->assertSame(2000, $this->credit());
        $this->assertSame(1, $this->attention(), '20.00 of a customer checkout became credit');
    }

    // ------------------------------------------------------------------ guards

    public function test_a_login_without_the_invoices_grant_neither_opens_nor_makes_a_group_guard(): void
    {
        $this->withoutVite();
        $lead = $this->invoice(10, '+1 day');
        $other = $this->invoice(20, '+2 days');
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $other->id], $this->user);
        $helper = User::factory()->create();
        ClientUser::query()->create(['client_id' => $this->user->client()->id, 'user_id' => $helper->id, 'owner' => false, 'permissions' => ['tickets'], 'granted_at' => now()]);

        $status = $this->actingAs($helper)->withSession($this->loginUser($helper, ['client_id' => $this->user->client()->id]))
            ->get(route('invoices.pay-together', $group))->status();
        $this->assertContains($status, [403, 404]);
        $this->assertSame(1, PaymentGroup::query()->count());
    }

    public function test_another_accounts_invoice_ids_in_selected_make_nothing_guard(): void
    {
        $mine = $this->invoice(10, '+1 day');
        $stranger = User::factory()->create();
        $theirs = $this->invoice(20, '+2 days', user: $stranger);

        try {
            (new MassPay)->open($this->user->client(), [$mine->id, $theirs->id], $this->user);
            $this->fail('grouped another account\'s invoice');
        } catch (DisplayException) {
        }
        $this->assertSame(0, PaymentGroup::query()->count());
    }

    public function test_a_tampered_method_opens_no_gateway_guard(): void
    {
        $lead = $this->invoice(10, '+1 day');
        $other = $this->invoice(20, '+2 days');
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $other->id], $this->user);
        $this->mollie();
        Http::fake();

        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group])
            ->set('selectedMethod', 'gateway-99999')->call('processPayment')
            ->set('selectedMethod', '01JUNKNOWNAGREEMENT000000')->call('processPayment')
            ->assertNoRedirect();
        Http::assertNothingSent();
    }

    public function test_the_locked_group_cannot_be_swapped_guard(): void
    {
        $lead = $this->invoice(10, '+1 day');
        $other = $this->invoice(20, '+2 days');
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $other->id], $this->user);

        $this->expectException(\Throwable::class);
        $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group])->set('paymentGroup', null);
    }

    public function test_spread_works_with_credits_disabled_and_the_spread_payment_cannot_be_deleted_guard(): void
    {
        config(['settings.credits_enabled' => false]);
        $lead = $this->invoice(10, '+1 day');
        $other = $this->invoice(20, '+2 days');
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $other->id], $this->user);
        $this->mollie();

        $payment = ExtensionHelper::addPayment($group->invoice_id, 'Mollie', '30.00', transactionId: 'tr_off', currency: 'USD', paymentGroup: $group->ulid);

        $this->assertSame([Invoice::STATUS_PAID, Invoice::STATUS_PAID], [$lead->fresh()->status, $other->fresh()->status]);
        $this->assertSame(0, $this->credit());
        $this->assertSame([], (new MoneyCheck)->run());
        $this->expectException(DisplayException::class);
        (new RecordPaymentService)->deleteTransaction(InvoiceTransaction::query()->where('transaction_id', 'tr_off')->sole());
    }

    public function test_a_payment_in_another_currency_is_not_spread_guard(): void
    {
        $lead = $this->invoice(10, '+1 day');
        $other = $this->invoice(20, '+2 days');
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $other->id], $this->user);
        $this->mollie();

        ExtensionHelper::addPayment($group->invoice_id, 'Mollie', '30.00', transactionId: 'tr_eur', currency: 'EUR', paymentGroup: $group->ulid);

        $this->assertSame([Invoice::STATUS_UNPAID, Invoice::STATUS_UNPAID], [$lead->fresh()->status, $other->fresh()->status]);
        $this->assertSame(PaymentGroup::STATUS_OPEN, $group->fresh()->status);
    }

    public function test_a_second_checkout_of_a_paid_group_becomes_credit_not_a_second_spread_guard(): void
    {
        $lead = $this->invoice(10, '+1 day');
        $other = $this->invoice(20, '+2 days');
        $third = $this->invoice(5, '+3 days');
        $group = (new MassPay)->open($this->user->client(), [$lead->id, $other->id], $this->user);
        $this->mollie();

        ExtensionHelper::addPayment($lead->id, 'Mollie', '30.00', transactionId: 'tr_one', currency: 'USD', paymentGroup: $group->ulid);
        ExtensionHelper::addPayment($lead->id, 'Mollie', '30.00', transactionId: 'tr_two', currency: 'USD', paymentGroup: $group->ulid);

        $this->assertSame(Invoice::STATUS_UNPAID, $third->fresh()->status, 'not chosen, auto-use off');
        $this->assertSame(3000, $this->credit());
    }
}
