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
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * E3 of the Ekdosi bridge (docs/ekdosi-bridge.md): a payment Ekdosi received for an invoice it issued on credit, recorded
 * here once, never more than is owed (never account credit), only on an unpaid EUR invoice with no payment on its way;
 * and the feed narrowed to the accounts Ekdosi asks for.
 */
class EkdosiAddPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-test-secret-of-at-least-thirty-two-chars';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.ekdosi_enabled' => true, 'settings.ekdosi_secret' => self::SECRET, 'settings.ekdosi_url' => 'https://ekdosi.example.test']);
        Gateway::query()->create(['name' => 'Bank transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
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

    /** An unpaid invoice Ekdosi issued on credit: its document is active. */
    private function onCredit(string $price = '124.00', array $attributes = []): Invoice
    {
        $invoice = Invoice::factory()->create($attributes + ['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);
        EkdosiDocument::query()->create(['invoice_id' => $invoice->id, 'mark' => (string) (400001234560000 + $invoice->id), 'code' => 'ΤΙΜ' . $invoice->id]);

        return $invoice->fresh();
    }

    private function pay(Invoice $invoice, string $amount, string $transid = 'pay-1'): TestResponse
    {
        return $this->bridge(['op' => 'add_payment', 'invoice_id' => $invoice->id, 'amount' => $amount, 'transid' => $transid, 'gateway' => 'ekdosi']);
    }

    public function test_a_payment_in_full_pays_the_invoice_once(): void
    {
        $invoice = $this->onCredit();

        $this->pay($invoice, '124.00')->assertOk()->assertJsonPath('amount', '124.00')->assertJsonPath('capped', false);
        $this->pay($invoice, '124.00')->assertOk()->assertJsonPath('note', 'already_recorded');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $transaction = InvoiceTransaction::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame(['ekdosi:pay-1', '124.00', null], [$transaction->transaction_id, (string) $transaction->amount, $transaction->gateway_id]);
        $this->assertSame(EkdosiDocument::ACTIVE, $invoice->ekdosiDocuments()->sole()->state, 'its document stands');
    }

    public function test_part_payments_add_up_and_never_more_than_is_owed(): void
    {
        $invoice = $this->onCredit();
        $credit = fn () => (string) Credit::query()->sum('amount');
        $before = $credit();

        $this->pay($invoice, '24.00', 'pay-a')->assertOk()->assertJsonPath('amount', '24.00');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        // More than is left: cut to what is left (the plugin's rule), never account credit.
        $this->pay($invoice, '500.00', 'pay-b')->assertOk()->assertJsonPath('amount', '100.00')->assertJsonPath('capped', true);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('124.00', number_format((float) InvoiceTransaction::query()->where('invoice_id', $invoice->id)->sum('amount'), 2, '.', ''));
        $this->assertSame($before, $credit(), 'no account credit');
        $this->pay($invoice, '10.00', 'pay-c')->assertOk()->assertJsonPath('note', 'already_settled');
        $this->assertSame(2, InvoiceTransaction::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_an_invoice_already_paid_here_is_never_paid_again(): void
    {
        // The customer paid in MyCS (a gateway, a transfer) before Ekdosi saw it paid there.
        $invoice = $this->onCredit();
        ExtensionHelper::addPayment($invoice, 'BankTransfer', '124.00', transactionId: 'bank-1');

        $this->pay($invoice, '124.00')->assertOk()->assertJsonPath('note', 'already_settled');
        $this->assertSame(1, InvoiceTransaction::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_what_is_not_payable_is_refused(): void
    {
        foreach ([Invoice::STATUS_CANCELLED, Invoice::STATUS_DRAFT] as $i => $status) {
            $invoice = $this->onCredit();
            Invoice::query()->whereKey($invoice->id)->update(['status' => $status]);
            $this->pay($invoice, '124.00', 'pay-s' . $i)->assertStatus(409)->assertJsonPath('error', 'invoice_not_payable');
        }
        $usd = $this->onCredit('124.00', ['currency_code' => 'USD']);
        $this->pay($usd, '124.00', 'pay-usd')->assertStatus(409);

        $processing = $this->onCredit();
        ExtensionHelper::addPayment($processing, 'BankTransfer', '124.00', transactionId: 'sepa-1', status: InvoiceTransactionStatus::Processing);
        $this->pay($processing, '124.00', 'pay-p')->assertStatus(409)->assertJsonPath('error', 'payment_on_its_way');

        $this->assertSame(1, InvoiceTransaction::query()->count(), 'only the processing one');
    }

    public function test_the_same_payment_on_another_invoice_is_refused(): void
    {
        $first = $this->onCredit();
        $second = $this->onCredit();
        $this->pay($first, '24.00', 'shared')->assertOk();

        $this->pay($second, '24.00', 'shared')->assertStatus(409)->assertJsonPath('error', 'transid_on_another_invoice');
        $this->assertSame(0, InvoiceTransaction::query()->where('invoice_id', $second->id)->count());
    }

    public function test_bad_requests_are_refused(): void
    {
        $invoice = $this->onCredit();
        foreach ([
            ['amount' => '0', 'transid' => 'x'],
            ['amount' => '-5', 'transid' => 'x'],
            ['amount' => '1.234', 'transid' => 'x'],
            ['amount' => '1e3', 'transid' => 'x'],
            ['amount' => ['10'], 'transid' => 'x'],
            ['amount' => '10.00', 'transid' => ''],
            ['amount' => '10.00', 'transid' => ['x']],
            ['amount' => '10.00', 'transid' => str_repeat('x', 101)],
        ] as $body) {
            $this->bridge(['op' => 'add_payment', 'invoice_id' => $invoice->id] + $body)->assertStatus(400);
        }
        $this->bridge(['op' => 'add_payment', 'invoice_id' => 999999, 'amount' => '10.00', 'transid' => 'x'])->assertStatus(404);
        $this->assertSame(0, InvoiceTransaction::query()->count());

        config(['settings.ekdosi_enabled' => false]);
        $this->pay($invoice, '10.00')->assertStatus(404);
        $this->assertSame(0, InvoiceTransaction::query()->count());
    }

    /** Review of #464: only for an invoice with an active document; a short payment is recorded and told to staff. */
    public function test_only_with_an_active_document_and_a_short_payment_is_told(): void
    {
        Mail::fake();
        config(['settings.system_email_address' => 'billing@example.test', 'settings.mail_disable' => false]);
        $none = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $none->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '10.00']);
        $this->pay($none, '10.00', 'no-doc')->assertStatus(409)->assertJsonPath('error', 'no_active_document');
        $cancelled = $this->onCredit();
        $cancelled->ekdosiDocuments()->update(['state' => EkdosiDocument::CANCELLED]);
        $this->pay($cancelled, '124.00', 'doc-cancelled')->assertStatus(409)->assertJsonPath('error', 'no_active_document');
        $this->assertSame(0, InvoiceTransaction::query()->count());

        $short = $this->onCredit();
        $this->pay($short, '100.00', 'short')->assertOk()->assertJsonPath('amount', '100.00')->assertJsonPath('remaining', '24.00');
        $this->assertSame(1, EmailLog::query()->where('subject', 'like', '%attention%')->where('body', 'like', '%24.00%')->count());
    }

    public function test_the_feed_narrows_to_the_accounts_asked_for(): void
    {
        $mine = $this->onCredit();
        $otherClient = User::factory()->create()->client();
        $other = Invoice::factory()->create(['client_id' => $otherClient->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $other->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '10.00']);

        $ids = fn (array $body) => collect($this->bridge(['op' => 'invoices', 'status' => 'Unpaid'] + $body)->assertOk()->json('invoices'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$mine->id, $other->id], $ids([]));
        $this->assertSame([$mine->id], $ids(['userids' => [$this->client->id]]));
        $this->assertSame([], $ids(['userids' => []]));

        foreach (['1', [0], ['1'], [1.5], ['a' => 1], range(1, 1001)] as $bad) {
            $this->bridge(['op' => 'invoices', 'status' => 'Unpaid', 'userids' => $bad])->assertStatus(400);
        }
    }
}
