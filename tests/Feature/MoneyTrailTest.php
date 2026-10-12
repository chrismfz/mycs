<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyTrail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * MT-1a (docs/money-trail.md §1): every money movement writes its events, in its own transaction; the journal is
 * append-only, holds no personal data, and the credit balance can be rebuilt from it.
 */
class MoneyTrailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_enabled' => true]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    private function invoice(float $price = 100.00, string $status = 'pending'): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => $status, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function creditInvoice(float $price): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'pending', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function giveCredit(float $amount): void
    {
        Credit::query()->create(['user_id' => $this->user->id, 'currency_code' => 'USD', 'amount' => $amount]);
    }

    private function balance(): string
    {
        return number_format((float) (Credit::query()->where('user_id', $this->user->id)->where('currency_code', 'USD')->value('amount') ?? 0), 2, '.', '');
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: ?string}> kind, amount, source, cause */
    private function events(): array
    {
        return MoneyEvent::query()->orderBy('id')->get()
            ->map(fn (MoneyEvent $event) => [$event->kind->value, $event->amount, $event->source, $event->cause])->all();
    }

    /** The balance the journal gives, from an opening balance that predates it. */
    private function journalBalance(float $opening = 0): string
    {
        $cents = (int) round($opening * 100) + MoneyEvent::query()->where('user_id', $this->user->id)->get()
            ->sum(fn (MoneyEvent $event) => $event->creditEffectCents());

        return number_format($cents / 100, 2, '.', '');
    }

    public function test_a_gateway_payment_writes_one_payment_event(): void
    {
        $invoice = $this->invoice();

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_1', currency: 'USD');

        $this->assertSame([['payment', '100.00', 'stripe', null]], $this->events());
        $event = MoneyEvent::query()->sole();
        $this->assertSame($invoice->id, $event->invoice_id);
        $this->assertSame('pi_1', $event->external_reference);
        $this->assertSame($this->user->id, $event->user_id);
        $this->assertSame('USD', $event->currency_code);
    }

    public function test_an_overpayment_writes_the_payment_and_the_credit_it_left(): void
    {
        $invoice = $this->invoice();

        $transaction = ExtensionHelper::addPayment($invoice->id, 'Stripe', 120.00, null, 'pi_2', currency: 'USD');

        $this->assertSame([['payment', '120.00', 'stripe', null], ['credit_in', '20.00', 'stripe', 'overpayment']], $this->events());
        $creditIn = MoneyEvent::query()->where('kind', MoneyEventKind::CreditIn->value)->sole();
        $this->assertSame('20.00', $creditIn->credit_balance_after);
        $this->assertSame($transaction->id, $creditIn->invoice_transaction_id);
        $this->assertSame($this->balance(), $this->journalBalance());
    }

    public function test_a_replayed_webhook_writes_nothing_more(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_3', currency: 'USD');

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_3', currency: 'USD');

        $this->assertCount(1, $this->events());
    }

    public function test_a_processing_payment_is_journaled_only_once_it_succeeds(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 100.00, null, 'pi_4', currency: 'USD');
        $this->assertSame([], $this->events());

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_4', currency: 'USD');

        $this->assertSame([['payment', '100.00', 'stripe', null]], $this->events());
    }

    public function test_a_refused_payment_writes_nothing(): void
    {
        $invoice = $this->invoice();

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_5', currency: 'EUR');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', -5, null, 'pi_6', currency: 'USD');

        $this->assertSame([], $this->events());
    }

    public function test_a_staff_payment_without_a_gateway_is_manual_and_names_the_actor(): void
    {
        $invoice = $this->invoice();
        $staff = User::factory()->create();
        $this->actingAs($staff);

        ExtensionHelper::addPayment($invoice->id, null, 100.00, null, 'BANK-1');

        $event = MoneyEvent::query()->sole();
        $this->assertSame('manual', $event->source);
        $this->assertSame([$staff->getMorphClass(), $staff->id], [$event->actor_type, $event->actor_id]);
    }

    public function test_paying_with_credit_writes_credit_out_and_payment(): void
    {
        $this->giveCredit(150.00);
        $invoice = $this->invoice();

        (new RecordPaymentService)->payWithCredit($invoice);

        $this->assertSame([['credit_out', '100.00', 'credit', 'credit_payment'], ['payment', '100.00', 'credit', null]], $this->events());
        $this->assertSame('50.00', MoneyEvent::query()->where('kind', 'credit_out')->value('credit_balance_after'));
        $this->assertSame($this->balance(), $this->journalBalance(150.00));
    }

    public function test_credit_from_one_sale_paying_another_invoice_is_journaled(): void
    {
        $first = $this->invoice(10.00);
        $second = $this->invoice(10.00);
        $sale = ExtensionHelper::addPayment($first->id, 'Stripe', 20.00, null, 'sale_1', currency: 'USD');

        (new RecordPaymentService)->allocateFromTransaction($sale, $second);

        $this->assertSame([
            ['payment', '20.00', 'stripe', null],
            ['credit_in', '10.00', 'stripe', 'overpayment'],
            ['credit_out', '10.00', 'credit', 'credit_payment'],
            ['payment', '10.00', 'credit', null],
        ], $this->events());
        $this->assertSame('0.00', $this->balance());
        $this->assertSame($this->balance(), $this->journalBalance());
    }

    public function test_deleting_a_payment_reverses_its_events_and_keeps_them(): void
    {
        $invoice = $this->invoice();
        $transaction = ExtensionHelper::addPayment($invoice->id, 'Stripe', 120.00, null, 'pi_7', currency: 'USD');

        (new RecordPaymentService)->deleteTransaction($transaction);

        $this->assertNull($transaction->fresh());
        $this->assertSame([
            ['payment', '120.00', 'stripe', null],
            ['credit_in', '20.00', 'stripe', 'overpayment'],
            ['reversal', '120.00', 'stripe', 'transaction_deleted'],
            ['reversal', '20.00', 'stripe', 'transaction_deleted'],
        ], $this->events());
        $reversals = MoneyEvent::query()->where('kind', 'reversal')->orderBy('id')->get();
        $this->assertSame(MoneyEvent::query()->where('kind', 'payment')->value('id'), $reversals[0]->reverses_id);
        $this->assertSame('0.00', $reversals[1]->credit_balance_after);
        $this->assertSame('0.00', $this->balance());
        $this->assertSame($this->balance(), $this->journalBalance());
    }

    public function test_deleting_a_credit_payment_gives_the_credit_back_in_the_journal(): void
    {
        $this->giveCredit(150.00);
        $invoice = $this->invoice();
        $transaction = (new RecordPaymentService)->payWithCredit($invoice);

        (new RecordPaymentService)->deleteTransaction($transaction);

        $this->assertSame('150.00', $this->balance());
        $this->assertSame($this->balance(), $this->journalBalance(150.00));
        $this->assertSame(2, MoneyEvent::query()->where('kind', 'reversal')->count());
    }

    public function test_deleting_a_payment_from_before_the_journal_still_journals_the_credit_it_takes_back(): void
    {
        $this->giveCredit(20.00);
        $invoice = $this->invoice();
        $transaction = $invoice->transactions()->create([
            'gateway_id' => null, 'amount' => 120.00, 'credited_amount' => 20.00, 'transaction_id' => 'legacy-1',
            'status' => InvoiceTransactionStatus::Succeeded,
        ]);

        (new RecordPaymentService)->deleteTransaction($transaction);

        $this->assertSame([['credit_out', '20.00', 'manual', 'transaction_deleted']], $this->events());
        $this->assertSame('0.00', $this->balance());
    }

    public function test_a_local_refund_is_journaled(): void
    {
        $invoice = $this->invoice();
        $transaction = ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_8', currency: 'USD');

        ExtensionHelper::refundLocally($transaction, 30.00);

        $this->assertSame([['payment', '100.00', 'stripe', null], ['refund', '30.00', 'local', null]], $this->events());
        $this->assertSame($transaction->id, MoneyEvent::query()->where('kind', 'refund')->value('invoice_transaction_id'));
    }

    public function test_a_credit_purchase_and_its_refund_are_journaled(): void
    {
        $invoice = $this->creditInvoice(50.00);
        $transaction = ExtensionHelper::addPayment($invoice->id, 'Stripe', 50.00, null, 'pi_9', currency: 'USD');
        $this->assertSame('paid', $invoice->fresh()->status);

        ExtensionHelper::refundLocally($transaction, 50.00);

        // The invoice turns paid (and gives its credit) while the transaction is created, so before its payment event.
        $this->assertSame([
            ['credit_in', '50.00', 'system', 'purchase'],
            ['payment', '50.00', 'stripe', null],
            ['credit_out', '50.00', 'local', 'purchase_refunded'],
            ['refund', '50.00', 'local', null],
        ], $this->events());
        $this->assertSame('0.00', $this->balance());
        $this->assertSame($this->balance(), $this->journalBalance());
    }

    public function test_deleting_a_credit_purchase_journals_the_bought_credit_taken_back(): void
    {
        $invoice = $this->creditInvoice(50.00);
        $transaction = ExtensionHelper::addPayment($invoice->id, 'Stripe', 70.00, null, 'pi_10', currency: 'USD');
        $this->assertSame('70.00', $this->balance());

        (new RecordPaymentService)->deleteTransaction($transaction);

        $this->assertSame('0.00', $this->balance());
        $this->assertSame($this->balance(), $this->journalBalance());
        $this->assertSame(['credit_out', '50.00', 'manual', 'transaction_deleted'], array_slice($this->events(), -1)[0]);
    }

    public function test_a_rolled_back_payment_leaves_no_event(): void
    {
        $invoice = $this->invoice();

        try {
            DB::transaction(function () use ($invoice) {
                ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_11', currency: 'USD');
                throw new RuntimeException('later step failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->events());
        $this->assertSame(0, InvoiceTransaction::query()->count());
    }

    public function test_events_cannot_be_updated_or_deleted(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_12', currency: 'USD');
        $event = MoneyEvent::query()->sole();

        try {
            $event->update(['amount' => 1]);
            $this->fail('An event was updated.');
        } catch (LogicException) {
        }
        try {
            $event->delete();
            $this->fail('An event was deleted.');
        } catch (LogicException) {
        }

        $this->assertSame('100.00', $event->fresh()->amount);
    }

    public function test_an_event_is_reversed_at_most_once(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_13', currency: 'USD');
        $event = MoneyEvent::query()->sole();
        DB::transaction(fn () => (new MoneyTrail)->reverse($event));

        $this->expectException(QueryException::class);
        DB::transaction(fn () => (new MoneyTrail)->reverse($event));
    }

    public function test_a_customer_with_money_events_cannot_be_deleted(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_14', currency: 'USD');

        try {
            $this->user->delete();
            $this->fail('A customer with money events was deleted.');
        } catch (DisplayException) {
        }

        $this->assertNotNull($this->user->fresh());
        $this->assertSame(1, MoneyEvent::query()->count());
    }

    public function test_the_journal_refuses_a_customer_delete_that_skips_the_model(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_15', currency: 'USD');

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $this->user->id)->delete();
    }

    public function test_a_customer_without_money_events_can_still_be_deleted(): void
    {
        $other = User::factory()->create();

        $other->delete();

        $this->assertNull($other->fresh());
    }

    public function test_events_hold_no_personal_data(): void
    {
        $this->user->forceFill(['first_name' => 'Unique-First', 'last_name' => 'Unique-Last', 'email' => 'unique-mail@example.test'])->save();
        $this->giveCredit(10.00);
        $invoice = $this->invoice();
        $transaction = ExtensionHelper::addPayment($invoice->id, 'Stripe', 95.00, null, 'pi_16', currency: 'USD');
        (new RecordPaymentService)->payWithCredit($invoice);
        ExtensionHelper::refundLocally($transaction, 5.00);

        $this->assertSame(
            ['id', 'kind', 'amount', 'currency_code', 'user_id', 'invoice_id', 'invoice_transaction_id', 'credit_balance_after',
                'source', 'cause', 'external_reference', 'group_reference', 'actor_type', 'actor_id', 'reason', 'reverses_id', 'occurred_at'],
            array_keys((array) DB::table('money_events')->first()),
        );
        $dump = json_encode(DB::table('money_events')->get());
        foreach (['Unique-First', 'Unique-Last', 'unique-mail@example.test'] as $personal) {
            $this->assertStringNotContainsString($personal, $dump);
        }
    }
}
