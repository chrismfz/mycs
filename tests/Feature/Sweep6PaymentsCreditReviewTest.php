<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\AdjustmentNoteType;
use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\AdjustmentNote;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Clients\MoveToClient;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\StaffLineEdit;
use App\Services\Money\GatewayReference;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Independent and adversarial review of #766 (sweep 6: failed payments stay failed, no discount on credit purchases,
 * chargebacks always recorded). Each test fails while the finding it names (REV-766-n) holds.
 */
class Sweep6PaymentsCreditReviewTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_SECRET = 'whsec_rev766';

    private const PAYPAL_API = 'https://api-m.sandbox.paypal.com';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.credits_enabled' => true,
            'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function stripe(): Gateway
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        foreach (['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::STRIPE_SECRET] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return $gateway->fresh();
    }

    private function stripeWebhook(string $id, int $created, string $type, array $object)
    {
        $payload = json_encode(['id' => $id, 'created' => $created, 'type' => $type, 'data' => ['object' => $object]]);
        $time = time();

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1=" . hash_hmac('sha256', $time . '.' . $payload, self::STRIPE_SECRET),
        ], $payload);
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
    }

    private function invoice(float $price, ?string $referenceType = null, ?int $referenceId = null, string $currency = 'USD'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => $currency,
            'due_at' => now()->toDateString()]);
        $invoice->items()->create(['description' => $referenceType === Credit::class ? 'Add funds' : 'Hosting', 'quantity' => 1, 'price' => $price,
            'reference_type' => $referenceType, 'reference_id' => $referenceId]);

        return $invoice->fresh();
    }

    private function staffMail(string $subject): string
    {
        return (string) EmailLog::query()->where('subject', $subject)->orderByDesc('id')->value('body');
    }

    /**
     * REV-766-1 (claim 1). The rule «a failed payment never goes back to processing» cannot tell a late copy of an old
     * event from a new attempt on the same payment intent. Stripe's checkout (pay(): one intent, automatic payment
     * methods) keeps its intent through the customer's attempts: a card declined (payment_intent.payment_failed), then a
     * bank debit on the same form (payment_intent.processing, a later event with another charge). That debit is on its
     * way for days. On main the row went back to processing and the invoice was held; now the event is dropped, the row
     * says «failed» and the invoice is open for the cron's saved-card charge, another checkout, credit and dunning while
     * the bank is taking the money: paid twice (the second lands as credit), or suspended for an invoice being paid.
     */
    public function test_rev_766_1_a_new_attempt_on_the_same_intent_after_a_decline_is_on_its_way(): void
    {
        $this->stripe();
        $invoice = $this->invoice(10);
        $intent = ['id' => 'pi_rev766_1', 'object' => 'payment_intent', 'amount' => 1000, 'currency' => 'usd',
            'metadata' => ['mycs' => GatewayReference::mark(), 'invoice_id' => (string) $invoice->id]];

        // 10:00 the card is declined: the intent is back to «requires_payment_method», its first charge failed.
        $this->stripeWebhook('evt_rev766_1a', 1794304800, 'payment_intent.payment_failed',
            $intent + ['status' => 'requires_payment_method', 'latest_charge' => 'ch_rev766_card'])->assertOk();
        $this->assertSame(InvoiceTransactionStatus::Failed, $invoice->transactions()->sole()->status, 'precondition: the decline is recorded');

        // 10:03 the customer pays by bank debit on the same form: a new charge of the same intent, on its way.
        // (Added with the fix: the adapter asks Stripe once what the intent is now, and Stripe says «processing».)
        Http::fake(['api.stripe.com/v1/payment_intents/pi_rev766_1' => Http::response(['id' => 'pi_rev766_1', 'object' => 'payment_intent', 'status' => 'processing'])]);
        $this->stripeWebhook('evt_rev766_1b', 1794304980, 'payment_intent.processing',
            $intent + ['status' => 'processing', 'latest_charge' => 'py_rev766_sepa'])->assertOk();

        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()),
            'a bank debit of the invoice is on its way at Stripe, yet nothing holds the invoice: the cron charges the saved card, a second checkout opens, dunning runs');
        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status,
            'the customer and staff read «payment failed» while the bank is taking the money');
    }

    /**
     * REV-766-2 (claim 1, the PayPal order the author left out). PAYMENT.CAPTURE.DENIED is acted on only when a
     * processing row of that capture exists; one that arrives before the row (pay()/capture() record the PENDING capture
     * after PayPal's answer, in another request) is dropped with no trace. The PENDING capture recorded a moment later is
     * then a processing payment nothing ever settles: PayPal does not send the denial again, and the invoice is held from
     * the cron charge, every checkout, credit, Mass Pay and bank matching, for ever. The new rule would cover it if the
     * denial left a failed row (failed stays failed).
     */
    public function test_rev_766_2_a_paypal_denial_before_its_pending_capture_is_recorded_does_not_hold_the_invoice_for_ever(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        foreach (['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1', 'test_mode' => '1'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Http::fake([
            self::PAYPAL_API . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::PAYPAL_API . '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);
        $invoice = $this->invoice(10);

        $this->postJson('/extensions/paypal/webhook', ['id' => 'WH-EV-REV766-2', 'event_type' => 'PAYMENT.CAPTURE.DENIED',
            // (Added with the fix: PayPal's capture carries the order's invoice_id, as pay() sets it; it names the invoice.)
            'resource' => ['id' => 'CAP-REV766-2', 'status' => 'DENIED', 'invoice_id' => GatewayReference::paypalInvoiceId($invoice),
                'amount' => ['value' => '10.00', 'currency_code' => 'USD']]])->assertOk();
        // What pay() and capture() do with PayPal's answer «PENDING», a moment later.
        ExtensionHelper::addPayment($invoice->id, 'PayPal', '10.00', null, 'CAP-REV766-2', InvoiceTransactionStatus::Processing, currency: 'USD');

        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()),
            'PayPal denied this capture (its signed event was accepted with 200), yet its payment is «processing» and holds the invoice with nothing left to settle it');
    }

    /**
     * REV-766-3 (claim 3). «A chargeback is always recorded» now leaves the first customer's invoice UNPAID with a renewal
     * line on a service that is another customer's. Service::invoices() reads lines by the service alone, so that open
     * invoice is «the service's renewal on its way» for the cron (RenewalBilling::billRenewal: an open invoice of it, not
     * billed): the new owner's service is not billed again while the first customer's disputed invoice stays open, which
     * is as long as staff leave it. It then runs unpaid to suspension with nothing the new owner can pay. That is the
     * case the guard skipped here was written for (AUTH-8), and the staff mail does not say it.
     */
    public function test_rev_766_3_a_chargeback_of_the_first_customers_invoice_does_not_stop_the_new_owners_renewal(): void
    {
        $this->stripe();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-10-13', 'currency_code' => 'USD', 'price' => 10.00]);
        $invoice = $this->invoice(10, Service::class, $service->id);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, transactionId: 'pi_rev766_3', currency: 'usd');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'precondition: paid');
        $this->assertSame('2026-11-13', Carbon::parse($service->fresh()->expires_at)->toDateString(), 'precondition: renewed until the 13th');

        $newOwner = User::factory()->create()->client();
        (new MoveToClient)->service($service->fresh(), $newOwner, $this->staff());

        // Control: as things stand, the cron bills the new owner's next period.
        DB::beginTransaction();
        $this->assertSame(1, (new RenewalBilling)->billRenewal($service->id, now()->addDays(7)), 'precondition: the service is due to be billed');
        DB::rollBack();

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_rev766_3', 'dp_rev766_3', 10.00, 'usd');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: the chargeback is recorded, the invoice owed again');

        $billed = DB::transaction(fn () => (new RenewalBilling)->billRenewal($service->id, now()->addDays(7)));
        $renewal = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->whereHas('invoice', fn ($open) => $open->where('client_id', $newOwner->id))->exists();
        $this->assertTrue($billed === 1 && $renewal,
            'the new owner\'s service is not billed while the first customer\'s charged-back invoice is open: it runs to suspension with nothing its owner can pay');
    }

    /**
     * REV-766-4 (claim 2, the refusal caught). The notice that tells staff of a credit purchase its payment could not turn
     * paid is written only when the payment left no excess. A customer who pays the purchase's face amount (a transfer of
     * 100 for «Add funds 100», the invoice showing 80 after an older -20 note) is in the same state: 80 stays on an
     * invoice that stays unpaid and gives no credit, and 20 became credit. Staff are told only of the 20: nothing says
     * the invoice is stuck with the customer's 80 on it, so nobody voids the discount or refunds it.
     */
    public function test_rev_766_4_staff_are_told_of_a_stuck_credit_purchase_when_it_was_paid_over_its_discounted_total(): void
    {
        $this->stripe();
        $invoice = $this->invoice(100, Credit::class);
        AdjustmentNote::withoutEvents(fn () => $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -20, 'description' => 'Discount']));
        $this->assertEqualsWithDelta(80.0, (float) $invoice->fresh()->remaining, 0.001, 'precondition: 80 to pay, as an import leaves it');

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, transactionId: 'pi_rev766_4', currency: 'usd');

        $invoice = $invoice->fresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status, 'precondition: its payment could not turn it paid');
        $this->assertEqualsWithDelta(0.0, (float) $invoice->remaining, 0.001, 'precondition: nothing is left to pay on it');
        $this->assertEqualsWithDelta(20.0, (float) Credit::query()->where('client_id', $invoice->client_id)->value('amount'), 0.001,
            'precondition: 100 paid for 100 of credit, 20 given');
        $told = EmailLog::query()->where('subject', 'Payment needs attention')->pluck('body')->implode("\n");
        $this->assertStringContainsString('stays unpaid', $told,
            'staff are told that 20 became credit, not that the invoice stays unpaid with 80 of the customer\'s money on it and gave no credit');
    }

    /**
     * REV-766-5 (claim 2, the staff message). For a purchase discounted by a negative line (what an import or an older
     * edit leaves), the mail tells staff «Void the discount or refund the payment». The discount cannot be taken off:
     * its line keeps its amount while the invoice has a payment (StaffLineEdit, whose own message offers «a discount with
     * a note», refused here too), and the payment is not refunded to credit either. Only a refund at the gateway works.
     * Either the mail names what staff can do, or the line can be removed.
     */
    public function test_rev_766_5_what_the_mail_tells_staff_to_do_with_a_line_discount_can_be_done(): void
    {
        $this->stripe();
        $invoice = $this->invoice(100, Credit::class);
        $discount = (new InvoiceItem)->forceFill(['invoice_id' => $invoice->id, 'description' => 'Discount', 'quantity' => 1, 'price' => -20,
            'line_total' => -20, 'tax_amount' => 0, 'taxed' => false]);
        $discount->saveQuietly(); // as an import leaves it
        $this->assertEqualsWithDelta(80.0, (float) $invoice->fresh()->remaining, 0.001, 'precondition: 80 to pay');

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 80.00, transactionId: 'pi_rev766_5', currency: 'usd');
        $mail = $this->staffMail('Payment needs attention');
        $this->assertStringContainsString("Invoice #{$invoice->id} buys account credit and has a discount", $mail, 'precondition: staff are told');

        $removed = true;
        try {
            StaffLineEdit::run($invoice->id, true, fn () => $discount->fresh()->delete());
        } catch (DisplayException $e) {
            $removed = false;
        }
        $this->assertTrue($removed || !str_contains($mail, 'Void the discount'),
            'the mail tells staff to void the discount, and the discount line cannot be taken off while the payment is on the invoice');
    }

    // ---------------------------------------------------------------- added with the fixes of the review

    /**
     * REV-766-1, the other half (S6-PAYMENTS-1 through the adapter): a late copy of the old «processing» event after the
     * decline. Stripe is asked once and says the intent is not processing: the payment stays failed, nothing is held.
     */
    public function test_rev_766_1_a_late_copy_of_an_old_processing_event_stays_failed_at_the_adapter(): void
    {
        $this->stripe();
        $invoice = $this->invoice(10);
        $intent = ['id' => 'pi_rev766_1b', 'object' => 'payment_intent', 'amount' => 1000, 'currency' => 'usd',
            'metadata' => ['mycs' => GatewayReference::mark(), 'invoice_id' => (string) $invoice->id]];
        $this->stripeWebhook('evt_rev766_1c', 1794304800, 'payment_intent.payment_failed', $intent + ['status' => 'requires_payment_method'])->assertOk();

        Http::fake(['api.stripe.com/v1/payment_intents/pi_rev766_1b' => Http::response(['id' => 'pi_rev766_1b', 'object' => 'payment_intent', 'status' => 'requires_payment_method'])]);
        $this->stripeWebhook('evt_rev766_1d', 1794304700, 'payment_intent.processing', $intent + ['status' => 'processing'])->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(InvoiceTransactionStatus::Failed, $invoice->transactions()->sole()->status);
        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()));
    }

    /** A first «processing» event (no failed payment of the intent here) asks Stripe nothing. */
    public function test_rev_766_1_a_first_processing_event_asks_stripe_nothing(): void
    {
        $this->stripe();
        $invoice = $this->invoice(10);
        $this->stripeWebhook('evt_rev766_1e', 1794304800, 'payment_intent.processing', ['id' => 'pi_rev766_1c', 'object' => 'payment_intent', 'amount' => 1000,
            'currency' => 'usd', 'status' => 'processing', 'metadata' => ['mycs' => GatewayReference::mark(), 'invoice_id' => (string) $invoice->id]])->assertOk();

        Http::assertNothingSent();
        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status);
    }

    /**
     * REV-766-3, the sibling: the end of the new owner's service gives up on its own open invoices, never on the first
     * customer's charged-back one, which stays that customer's debt for staff.
     */
    public function test_rev_766_3_the_end_of_the_new_owners_service_leaves_the_first_customers_charged_back_invoice(): void
    {
        $this->stripe();
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10.00]);
        $first = $this->invoice(10, Service::class, $service->id);
        ExtensionHelper::addPayment($first->id, 'Stripe', 10.00, transactionId: 'pi_rev766_3b', currency: 'usd');
        $newOwner = User::factory()->create()->client();
        (new MoveToClient)->service($service->fresh(), $newOwner, $this->staff());
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_rev766_3b', 'dp_rev766_3b', 10.00, 'usd');
        $own = Invoice::factory()->create(['client_id' => $newOwner->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $own->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10, 'reference_type' => Service::class, 'reference_id' => $service->id]);

        $service = $service->fresh();
        $open = $service->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get();
        $this->assertEqualsCanonicalizing([$first->id, $own->id], $open->pluck('id')->all(), 'precondition: both bill the service');

        $this->assertSame([$own->id], CancelOpenInvoices::onServiceEnd($open, $service)->pluck('id')->all());
        $this->assertStringContainsString('moved to another customer or was deleted', $this->staffMail('Chargeback needs staff attention'));
    }

    /**
     * REV-766-5, rewritten 2026-11-10 (TIGHT-INVOICES-PAYMENTS-2): the line of a credit purchase with a part payment is
     * repriced like any other (the refusal it pinned is gone). The kernel stays: it gives the credit its money covers,
     * never more.
     */
    public function test_rev_766_5_a_part_paid_credit_purchase_is_repriced_and_gives_only_what_was_paid(): void
    {
        $this->stripe();
        $invoice = $this->invoice(100, Credit::class);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 50.00, transactionId: 'pi_rev766_5b', currency: 'usd');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $credit = fn (): string => number_format((float) (Credit::query()->where('client_id', $invoice->client_id)->value('amount') ?? 0), 2, '.', '');
        $this->assertSame('0.00', $credit(), 'precondition: unpaid, no credit given yet');

        StaffLineEdit::run($invoice->id, true, fn () => $invoice->items()->first()->update(['price' => 50]));

        $this->assertEqualsWithDelta(0.0, (float) $invoice->fresh()->remaining, 0.001);
        $this->assertSame('0.00', $credit(), 'an edit closes nothing and gives nothing on its own');
        $this->assertTrue((new RecordPaymentService)->settle($invoice->id));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('50.00', $credit(), 'the 50 that arrived, not the 100 it was made for');
    }

    /**
     * The pre-dump refusal: an unpaid WHMCS Add Funds invoice with a negative line stops the import (named by id); a paid
     * or cancelled one, an Add Funds with no discount and a discounted ordinary invoice do not.
     */
    public function test_the_import_refuses_an_unpaid_whmcs_add_funds_invoice_with_a_negative_line(): void
    {
        $whmcs = new \PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, status TEXT)');
        $whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, type TEXT, amount NUMERIC)');
        $whmcs->exec("INSERT INTO tblinvoices VALUES (9001, 'Unpaid'), (9002, 'Paid'), (9003, 'Unpaid'), (9004, 'Unpaid'), (9005, 'Cancelled'), (9006, 'Draft')");
        $whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, type, amount) VALUES (9001, 'AddFunds', 100), (9001, '', -20), (9002, 'AddFunds', 100), (9002, '', -20),"
            . " (9003, 'AddFunds', 100), (9004, 'Hosting', 100), (9004, 'PromoHosting', -20), (9005, 'AddFunds', 100), (9005, '', -20), (9006, 'AddFunds', 50), (9006, '', -5)");
        $command = app(ImportFromWhmcs::class);
        (new \ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);

        $this->assertSame([9001, 9006], (new \ReflectionMethod($command, 'discountedAddFunds'))->invoke($command));
    }

    /**
     * Owner 2026-11-10: staff must still be able to simply change the money. The cut refuses a discount (note, negative
     * line) on an unpaid credit purchase; the way out is to change the line's amount, through the normal staff edit:
     * 100.00 becomes 80.00, the gateway's 80.00 pays it and gives exactly 80.00 of credit, once.
     */
    public function test_staff_change_the_amount_of_an_unpaid_credit_purchase_and_its_payment_gives_that_credit_once(): void
    {
        $this->stripe();
        $invoice = $this->invoice(100, Credit::class);

        StaffLineEdit::run($invoice->id, true, fn () => $invoice->items()->sole()->update(['price' => 80]));
        $this->assertEqualsWithDelta(80.0, (float) $invoice->fresh()->remaining, 0.001, 'staff lowered what it buys');

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 80.00, transactionId: 'pi_rev766_edit', currency: 'usd');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 80.00, transactionId: 'pi_rev766_edit', currency: 'usd'); // the webhook again

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertEqualsWithDelta(80.0, (float) Credit::query()->where('client_id', $invoice->client_id)->value('amount'), 0.001, 'exactly the credit it bought');
        $this->assertSame('', $this->staffMail('Payment needs attention'));

        // And the refusal of the cut says so.
        $other = $this->invoice(100, Credit::class);
        try {
            $other->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -20, 'description' => 'Discount']);
            $this->fail('a discount note landed on an unpaid credit purchase');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('change the amount of its line instead', $e->getMessage());
            $this->assertNotSame($e->getMessage(), __($e->getMessage(), [], 'el'));
        }
    }
}
