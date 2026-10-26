<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\Credit;
use App\Models\EkdosiDocument;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Adversarial review of #464, round 2: the round-1 fixes (175eee83) of op=add_payment. Written without the author's
 * context.
 *
 * test_r2_* fail on 175eee83: each is a break. test_held_* pass: they pin what held.
 */
class EkdosiAddPaymentAdversarialRound2Test extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-test-secret-of-at-least-thirty-two-chars';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.ekdosi_enabled' => true, 'settings.ekdosi_secret' => self::SECRET, 'settings.ekdosi_url' => 'https://ekdosi.example.test']);
        config(['settings.system_email_address' => 'billing@example.test', 'settings.mail_disable' => false]);
        Mail::fake();
        Gateway::query()->firstOrCreate(['extension' => 'BankTransfer'], ['name' => 'Bank transfer', 'type' => 'gateway', 'enabled' => true]);
        $this->client = User::factory()->create()->client();
    }

    /** @param array<string, mixed> $body */
    private function bridge(array $body): TestResponse
    {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/ekdosi', [], [], [], $this->transformHeadersToServerVars([
            'X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $raw, self::SECRET), 'Content-Type' => 'application/json',
        ]), $raw);
    }

    private function onCredit(string $price = '124.00'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);
        EkdosiDocument::query()->create(['invoice_id' => $invoice->id, 'mark' => (string) (400001234560000 + $invoice->id), 'code' => 'ΤΙΜ' . $invoice->id]);

        return $invoice->fresh();
    }

    private function pay(Invoice $invoice, string $amount, string $transid): TestResponse
    {
        return $this->bridge(['op' => 'add_payment', 'invoice_id' => $invoice->id, 'amount' => $amount, 'transid' => $transid]);
    }

    private function paid(Invoice $invoice): string
    {
        return number_format((float) InvoiceTransaction::query()->where('invoice_id', $invoice->id)
            ->where('status', InvoiceTransactionStatus::Succeeded)->sum('amount'), 2, '.', '');
    }

    /** @return Collection<int, string> */
    private function told(): Collection
    {
        return EmailLog::query()->where('subject', 'Payment needs attention')->pluck('body');
    }

    // ----------------------------------------------------------------------------------------------------------------
    // Breaks
    // ----------------------------------------------------------------------------------------------------------------

    /**
     * R2-1. The ADV-2 fix completes a failed transaction with the id `ekdosi:<transid>` whatever its gateway. Round 1's
     * own fixture is a BankTransfer one: RecordPaymentService completes it keeping gateway_id (it unsets gateway_id on
     * update), so Ekdosi's money is now the bank transfer's (journal source, reference). And the replay check only
     * accepts a succeeded one with gateway_id null: Ekdosi's retry of the payment it just recorded answers 409
     * `transid_on_another_invoice` naming this very invoice, a «permanent, needs a human» code per the docs.
     */
    public function test_r2_1_completing_a_gateways_failed_transaction_makes_the_retry_a_409(): void
    {
        $invoice = $this->onCredit();
        ExtensionHelper::addPayment($invoice, 'BankTransfer', '124.00', transactionId: 'ekdosi:pay-9', status: InvoiceTransactionStatus::Failed);

        // Fix of #464 (author), the reviewer's suggested fix: a gateway's transaction with this id is never taken over —
        // a 409 transid_conflict, every time (shown in Ekdosi, for a human), never recorded as the bank transfer's.
        $this->pay($invoice, '124.00', 'pay-9')->assertStatus(409)->assertJsonPath('error', 'transid_conflict');
        $this->pay($invoice, '124.00', 'pay-9')->assertStatus(409)->assertJsonPath('error', 'transid_conflict');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(InvoiceTransactionStatus::Failed, InvoiceTransaction::query()->where('transaction_id', 'ekdosi:pay-9')->sole()->status,
            'the bank transfer\'s record is untouched');
    }

    /**
     * R2-2. The docs (and the code comment on line 57) say a processing transaction with this id «is completed». It is
     * never reached: the `payment_on_its_way` check sees that same transaction in $invoice->transactions and answers 409,
     * which the docs call transient («ξαναδοκιμάζεται»): Ekdosi retries forever, nothing is recorded, nobody is told.
     */
    public function test_r2_2_a_processing_transaction_with_the_same_id_is_never_completed(): void
    {
        $invoice = $this->onCredit();
        ExtensionHelper::addProcessingPayment($invoice, null, '124.00', transactionId: 'ekdosi:pay-p');

        // Fix of #464 (author), the reviewer's other option: the docs now say a processing one is never completed; it is
        // a 409 transid_conflict (permanent, for a human), not the transient payment_on_its_way.
        $this->pay($invoice, '124.00', 'pay-p')->assertStatus(409)->assertJsonPath('error', 'transid_conflict');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    /**
     * R2-3. `already_settled` records nothing, so the replay check never finds it: every retry of the same transid
     * (a lost 200, Ekdosi's sync run again) tells staff again — one «may have paid twice» mail per retry for one payment.
     * The same for the unpaid-but-owes-nothing branch.
     */
    public function test_r2_3_an_already_settled_payment_retried_tells_staff_again(): void
    {
        $invoice = $this->onCredit();
        ExtensionHelper::addPayment($invoice, 'BankTransfer', '124.00', transactionId: 'bank-full');

        $this->pay($invoice, '124.00', 'twice-1')->assertOk()->assertJsonPath('note', 'already_settled');
        $this->pay($invoice, '124.00', 'twice-1')->assertOk()->assertJsonPath('note', 'already_settled');
        $this->pay($invoice, '124.00', 'twice-1')->assertOk()->assertJsonPath('note', 'already_settled');

        $this->assertCount(1, $this->told(), 'one payment, one notice');
    }

    /**
     * R2-4. `preg_match('/^[1-9]\d{0,18}$/')` without the D modifier: `$` also matches before a final newline, so the
     * string "<id>\n" passes as «a string of digits» and pays that invoice. The fix meant only digits.
     */
    public function test_r2_4_an_invoice_id_with_a_trailing_newline_is_taken_as_digits(): void
    {
        $invoice = $this->onCredit();

        $this->bridge(['op' => 'add_payment', 'invoice_id' => $invoice->id . "\n", 'amount' => '10.00', 'transid' => 'nl'])->assertStatus(400);
        $this->assertSame('0.00', $this->paid($invoice));
    }

    // ----------------------------------------------------------------------------------------------------------------
    // Held
    // ----------------------------------------------------------------------------------------------------------------

    public function test_held_a_capped_payment_is_told_once_with_the_right_amounts_and_never_again_on_a_retry(): void
    {
        $invoice = $this->onCredit();
        ExtensionHelper::addPayment($invoice, 'BankTransfer', '24.00', transactionId: 'bank-part');

        $this->pay($invoice, '500.00', 'over-1')->assertOk()->assertJsonPath('capped', true)->assertJsonPath('amount', '100.00')->assertJsonPath('remaining', '0.00');
        $this->pay($invoice, '500.00', 'over-1')->assertOk()->assertJsonPath('note', 'already_recorded')->assertJsonPath('amount', '100.00')->assertJsonPath('remaining', '0.00');

        $told = $this->told();
        $this->assertCount(1, $told);
        $number = $invoice->fresh()->number ?: $invoice->id;
        $this->assertStringContainsString("500.00 € (ekdosi:over-1) for invoice #{$number}, which owed 100.00 €: 100.00 € was recorded, 400.00 € was not.", $told->first());
        $this->assertSame(0.0, (float) Credit::query()->sum('amount'));
    }

    public function test_held_a_short_payment_is_told_once_and_remaining_adds_up(): void
    {
        $invoice = $this->onCredit();

        $this->pay($invoice, '24.00', 's-1')->assertOk()->assertJsonPath('remaining', '100.00')->assertJsonPath('capped', false);
        $this->pay($invoice, '24.00', 's-1')->assertOk()->assertJsonPath('note', 'already_recorded')->assertJsonPath('remaining', '100.00');
        $this->assertCount(1, $this->told());
        $this->assertStringContainsString('100.00 € is still owed here', $this->told()->first());

        $this->pay($invoice, '100.00', 's-2')->assertOk()->assertJsonPath('remaining', '0.00')->assertJsonPath('capped', false);
        $this->assertCount(1, $this->told(), 'an exact payment is not news');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_held_the_transid_is_escaped_in_the_staff_mail(): void
    {
        $invoice = $this->onCredit();

        $this->pay($invoice, '500.00', '<script>alert(1)</script><a href="https://evil.test">x</a>')->assertOk();

        $body = $this->told()->sole();
        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringNotContainsString('<a href="https://evil.test">', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    public function test_held_nothing_is_told_when_the_payment_is_rolled_back(): void
    {
        $invoice = $this->onCredit();
        ExtensionHelper::addPayment($invoice, 'BankTransfer', '124.00', transactionId: 'bank-full');

        try {
            DB::transaction(function () use ($invoice) {
                $this->pay($invoice, '124.00', 'rb')->assertOk()->assertJsonPath('note', 'already_settled');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertCount(0, $this->told());
    }

    public function test_held_a_retry_after_the_document_was_cancelled_is_still_already_recorded(): void
    {
        $invoice = $this->onCredit();
        $this->pay($invoice, '24.00', 'c-1')->assertOk();
        EkdosiDocument::query()->where('invoice_id', $invoice->id)->update(['state' => EkdosiDocument::CANCELLED]);

        $this->pay($invoice, '24.00', 'c-1')->assertOk()->assertJsonPath('note', 'already_recorded');
        $this->pay($invoice, '10.00', 'c-2')->assertStatus(409)->assertJsonPath('error', 'no_active_document');
        $this->assertSame('24.00', $this->paid($invoice));
    }

    public function test_held_a_failed_transaction_on_another_customers_invoice_is_neither_completed_nor_doubled(): void
    {
        $other = User::factory()->create()->client();
        $theirs = Invoice::factory()->create(['client_id' => $other->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $theirs->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '124.00']);
        ExtensionHelper::addPayment($theirs, null, '124.00', transactionId: 'ekdosi:x-1', status: InvoiceTransactionStatus::Failed);
        $invoice = $this->onCredit();

        $this->pay($invoice, '124.00', 'x-1')->assertStatus(409)->assertJsonPath('error', 'transid_on_another_invoice');
        $this->assertSame(['0.00', '0.00'], [$this->paid($invoice), $this->paid($theirs)]);
        $this->assertSame(InvoiceTransactionStatus::Failed, InvoiceTransaction::query()->where('transaction_id', 'ekdosi:x-1')->sole()->status);
    }

    public function test_held_a_failed_ekdosi_transaction_is_completed_once_with_the_capped_amount(): void
    {
        $invoice = $this->onCredit();
        ExtensionHelper::addPayment($invoice, null, '900.00', transactionId: 'ekdosi:f-1', status: InvoiceTransactionStatus::Failed);

        $this->pay($invoice, '900.00', 'f-1')->assertOk()->assertJsonPath('amount', '124.00')->assertJsonPath('capped', true);
        $this->pay($invoice, '900.00', 'f-1')->assertOk()->assertJsonPath('note', 'already_recorded');

        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'ekdosi:f-1')->count());
        $this->assertSame('124.00', $this->paid($invoice));
        $this->assertSame(0.0, (float) Credit::query()->sum('amount'));
        $this->assertSame(0.0, (float) InvoiceTransaction::query()->sum('credited_amount'));
    }

    public function test_held_invoice_id_forms(): void
    {
        $invoice = $this->onCredit();
        foreach (['0' . $invoice->id, '-' . $invoice->id, -$invoice->id, 0, '0', ' ' . $invoice->id, $invoice->id . '.0', '1e1', ''] as $i => $id) {
            $this->bridge(['op' => 'add_payment', 'invoice_id' => $id, 'amount' => '1.00', 'transid' => 'id' . $i])->assertStatus(400);
        }
        foreach (['9223372036854775807', '9999999999999999999', PHP_INT_MAX] as $i => $id) {
            $this->bridge(['op' => 'add_payment', 'invoice_id' => $id, 'amount' => '1.00', 'transid' => 'big' . $i])->assertStatus(404);
        }
        $this->bridge(['op' => 'add_payment', 'invoice_id' => (string) $invoice->id, 'amount' => '1.00', 'transid' => 'str'])->assertOk();
        $this->assertSame('1.00', $this->paid($invoice));
    }

    public function test_held_a_transid_over_100_characters_is_refused(): void
    {
        $invoice = $this->onCredit();

        $this->pay($invoice, '1.00', str_repeat('a', 101))->assertStatus(400);
        $this->pay($invoice, '1.00', str_repeat('a', 100))->assertOk();
    }

    /** MariaDB's PAD SPACE collations compare «x» and «x » equal: a stored id with a trailing space is a conflict, never «the same». */
    public function test_held_a_stored_transid_with_a_trailing_space_is_a_conflict_on_mariadb(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs MariaDB/MySQL (collation).');
        }
        $invoice = $this->onCredit();
        ExtensionHelper::addPayment($invoice, null, '24.00', transactionId: 'ekdosi:t-1 ');

        $this->pay($invoice, '24.00', 't-1')->assertStatus(409)->assertJsonPath('error', 'transid_conflict');
        $this->assertSame('24.00', $this->paid($invoice));
    }
}
