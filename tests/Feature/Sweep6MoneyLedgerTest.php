<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\AdjustmentNote;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use App\Services\Service\DeleteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Sweep 6, money-ledger: adversarial verification (tests fail while the finding holds). */
class Sweep6MoneyLedgerTest extends TestCase
{
    use RefreshDatabase;

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
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
    }

    private function creditPurchase(): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Add funds', 'quantity' => 1, 'price' => 100, 'reference_type' => Credit::class]);

        return $invoice;
    }

    /**
     * S6-MONEY-LEDGER-1, the rule (owner: cut the case). An Add Funds invoice of 100 with a -20 credit note showed 80 to
     * pay; the 80 paid at Stripe was rolled back by InvoiceObserver (credit 100 > received 80). Now no staff note that
     * lowers it lands on a credit purchase that still has its credit to give: staff cancel it and make a new one with the right amount.
     *
     * Rewritten from the adversarial test (which expected the payment of the discounted invoice to be recorded): the
     * setup it used is what is refused now. The payment of an invoice that already has such a note is the next test.
     */
    public function test_s6_money_ledger_1_a_credit_purchase_takes_no_adjustment_note(): void
    {
        $invoice = $this->creditPurchase();

        try {
            $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -20, 'description' => 'Discount']);
            $this->fail('a discount note landed on a credit purchase');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('buys account credit', $e->getMessage());
        }
        $this->assertSame(0, $invoice->adjustmentNotes()->count());
        $this->assertEqualsWithDelta(100.0, (float) $invoice->fresh()->remaining, 0.001);

        // Its payment in full is recorded, pays it and gives the credit, as before.
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, transactionId: 'pi_full', currency: 'usd');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(100.0, (float) Credit::query()->where('client_id', $invoice->client_id)->value('amount'), 0.001);
    }

    /** The message is the customer's staff's language too. */
    public function test_s6_money_ledger_1_the_refusal_is_translated(): void
    {
        $key = 'An invoice that buys account credit takes no adjustment note or discount: change the amount of its line instead (or cancel it and make a new one).';
        $this->assertNotSame($key, __($key, [], 'el'));
        $this->assertSame($key, __($key, [], 'en'));
    }

    /** The same rule for the other way a discount lands: a negative line, or credit bought on an invoice that has one. */
    public function test_s6_money_ledger_1_a_credit_purchase_takes_no_negative_line(): void
    {
        $invoice = $this->creditPurchase();
        try {
            $invoice->items()->create(['description' => 'Discount', 'quantity' => 1, 'price' => -20]);
            $this->fail('a negative line landed on a credit purchase');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('buys account credit', $e->getMessage());
        }
        $line = $invoice->items()->create(['description' => 'Setup', 'quantity' => 1, 'price' => 5]);
        try {
            $line->update(['price' => -5]);
            $this->fail('a line of a credit purchase was repriced below zero');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('buys account credit', $e->getMessage());
        }

        $other = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $other->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 50]);
        $other->items()->create(['description' => 'Discount', 'quantity' => 1, 'price' => -20]);
        try {
            $other->items()->create(['description' => 'Add funds', 'quantity' => 1, 'price' => 100, 'reference_type' => Credit::class]);
            $this->fail('credit was sold on an invoice with a discount line');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('buys account credit', $e->getMessage());
        }
        // An ordinary invoice keeps its discounts.
        $this->assertSame(2, $other->items()->count());
    }

    /**
     * S6-MONEY-LEDGER-1, what is there already (an import, a note from before the rule): the gateway's payment of the
     * discounted amount is recorded. The invoice stays unpaid, gives no credit, and staff are told. Voiding the note
     * puts the amount right; bringing it back is refused.
     */
    public function test_s6_money_ledger_1_a_payment_of_an_already_discounted_credit_purchase_is_recorded(): void
    {
        $invoice = $this->creditPurchase();
        $note = AdjustmentNote::withoutEvents(fn () => $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -20, 'description' => 'Discount']));
        $this->assertEqualsWithDelta(80.0, (float) $invoice->fresh()->remaining, 0.001, 'precondition: 80 to pay');

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 80.00, transactionId: 'pi_disc', currency: 'usd');

        $payment = InvoiceTransaction::query()->where('transaction_id', 'pi_disc')->sole();
        $this->assertSame(InvoiceTransactionStatus::Succeeded, $payment->status);
        $this->assertEqualsWithDelta(80.0, (float) $payment->amount, 0.001);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'it gives no credit its payment does not cover');
        $this->assertEqualsWithDelta(0.0, (float) Credit::query()->where('client_id', $invoice->client_id)->value('amount'), 0.001);
        $this->assertStringContainsString("Invoice #{$invoice->id} buys account credit and has a discount",
            (string) EmailLog::query()->where('subject', 'Payment needs attention')->value('body'));

        // A replay of the webhook is the same payment.
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 80.00, transactionId: 'pi_disc', currency: 'usd');
        $this->assertSame(1, InvoiceTransaction::query()->where('invoice_id', $invoice->id)->count());

        $note->fresh()->update(['status' => AdjustmentNoteStatus::Voided]);
        $this->assertEqualsWithDelta(20.0, (float) $invoice->fresh()->remaining, 0.001);
        $this->expectException(DisplayException::class);
        $note->fresh()->update(['status' => AdjustmentNoteStatus::Active]);
    }

    /** A paid invoice of a service, by Stripe 'pi_1'. */
    private function paidServiceInvoice(): array
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 100.00]);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100, 'reference_type' => Service::class, 'reference_id' => $service->id]);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, transactionId: 'pi_1', currency: 'usd');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'precondition: paid');

        return [$invoice, $service];
    }

    private function assertChargebackRecorded(Invoice $invoice): void
    {
        $thrown = null;
        try {
            ExtensionHelper::chargebackAtGateway('Stripe', 'pi_1', 'dp_1', 100.00, 'usd');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $payment = InvoiceTransaction::query()->where('transaction_id', 'pi_1')->sole();
        $this->assertSame(1, GatewayRefund::query()->where('reference', 'dp_1')->count(),
            'the chargeback is not recorded: ' . ($thrown === null ? 'no exception' : get_class($thrown) . ': ' . $thrown->getMessage()));
        $this->assertEqualsWithDelta(100.0, (float) $payment->refunded_amount, 0.001, 'the money the bank took is not on the payment');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'owed again');
        $this->assertStringContainsString('moved to another customer or was deleted',
            (string) EmailLog::query()->where('subject', 'Chargeback needs staff attention')->value('body'));
    }

    /** The exception is the chargeback's alone: any other reopening of such an invoice is refused as before. */
    public function test_s6_money_ledger_2_another_reopening_of_a_moved_services_invoice_is_still_refused(): void
    {
        [$invoice, $service] = $this->paidServiceInvoice();
        (new MoveToClient)->service($service->fresh(), User::factory()->create()->client(), $this->staff());

        $invoice = $invoice->fresh();
        $this->assertFalse($invoice->becomingOwedAfterChargeback());
        try {
            $invoice->update(['status' => Invoice::STATUS_UNPAID]);
            $this->fail('reopened by hand');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('moved to another customer', $e->getMessage());
        }
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        // After a chargeback's save the model does not keep the pass.
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_1', 'dp_1', 100.00, 'usd');
        $again = $invoice->fresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $again->status);
        $this->assertFalse($again->becomingOwedAfterChargeback());
    }

    /** Only a paid invoice is owed again this way. */
    public function test_s6_money_ledger_2_only_a_paid_invoice_is_owed_again_after_a_chargeback(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_CANCELLED, 'currency_code' => 'USD']);
        $this->expectException(\LogicException::class);
        $invoice->owedAgainAfterChargeback();
    }

    /** S6-MONEY-LEDGER-2a. Its service moved to another customer: the chargeback rolls back. */
    public function test_s6_money_ledger_2_a_chargeback_is_recorded_after_its_service_moved(): void
    {
        [$invoice, $service] = $this->paidServiceInvoice();
        (new MoveToClient)->service($service->fresh(), User::factory()->create()->client(), $this->staff());
        $this->assertSame($this->user->client()->id, (int) $invoice->fresh()->client_id, 'precondition: the paid invoice stays');

        $this->assertChargebackRecorded($invoice);
    }

    /** S6-MONEY-LEDGER-2b. Its service deleted (paid lines kept): the chargeback rolls back. */
    public function test_s6_money_ledger_2_a_chargeback_is_recorded_after_its_service_was_deleted(): void
    {
        [$invoice, $service] = $this->paidServiceInvoice();
        (new DeleteService)->handle($service->fresh());
        $this->assertNull(Service::query()->find($service->id), 'precondition: deleted');

        $this->assertChargebackRecorded($invoice);
    }
}
