<?php

namespace Tests\Feature;

use App\Admin\Actions\SendToEkdosi;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\EkdosiDocument;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\SplitInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Independent and adversarial review of #778 («an invoice that is Ekdosi's is not split, merged or moved; a split no longer
 * refuses the MARK», S7-TAX-EKDOSI-1). Each test fails on the PR's head and says what main did. Invented data only.
 *
 * The PR's rule holds only while MyCS still knows the invoice is Ekdosi's. Each test is a way that knowledge is lost
 * (or never was) while Ekdosi keeps the copy it staged: the invoice is then split and the document of the OLD total is
 * taken for an invoice of another total, its split-off lines offered again for a second document.
 */
class Sweep7EkdosiReviewTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-test-secret-of-at-least-thirty-two-chars';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.ekdosi_enabled' => true, 'settings.ekdosi_secret' => self::SECRET, 'settings.ekdosi_url' => 'https://ekdosi.example.test/ekdosi',
            'settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
        Gateway::query()->create(['name' => 'Bank transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $this->client = User::factory()->create()->client();
        $this->client->primaryContact->forceFill(['type' => 'business', 'company_name' => 'Test', 'tax_id' => '099999999', 'country' => 'GR'])->save();
    }

    private function bridge(array $body): TestResponse
    {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/ekdosi', [], [], [], $this->transformHeadersToServerVars([
            'X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $raw, self::SECRET), 'Content-Type' => 'application/json']), $raw);
    }

    private function service(): Service
    {
        return Service::factory()->create(['client_id' => $this->client->id, 'currency_code' => 'EUR', 'price' => 20, 'quantity' => 1,
            'status' => Service::STATUS_ACTIVE]);
    }

    /** @return array{Invoice, int} unpaid, 100.00 + 20.00 net = 148.80 with VAT 24%; and the id of the 20.00 line */
    private function invoice(bool $services = false): array
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']
            + ($services ? ['reference_type' => Service::class, 'reference_id' => $this->service()->id] : []));
        $b = $invoice->items()->create(['description' => 'Domain', 'quantity' => 1, 'price' => '20.00']
            + ($services ? ['reference_type' => Service::class, 'reference_id' => $this->service()->id] : []));

        return [$invoice->fresh(), (int) $b->id];
    }

    /**
     * REV-778-1. A paid invoice is «Ekdosi's» only while it is paid. Paid 148.80 → in the Inbox, Ekdosi stages it → the
     * payment was a mistake (wrong invoice, a bounced transfer) and staff delete it: unpaid again, no payment, no request,
     * no document, so nothing in refusal() remembers Ekdosi has it → split 124.00 + 24.80 → Ekdosi issues what it staged.
     * The MARK of a 148.80 document is taken for an invoice of 124.00, and the 24.80 comes back for a second document.
     *
     * On main: 409 invoice_changed (the «ever split» check). Not among the gaps docs/ekdosi-bridge.md lists (it says «a
     * paid one … only unpaid ones are split, as always»).
     */
    public function test_rev_778_1_a_paid_invoice_ekdosi_read_then_set_back_to_unpaid_and_split_takes_the_old_document(): void
    {
        [$invoice, $line] = $this->invoice();
        ExtensionHelper::addPayment($invoice, 'BankTransfer', '148.80', transactionId: 'TX-MISTAKE');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        // Ekdosi reads its Inbox: this is the copy it will issue.
        $staged = collect($this->bridge(['op' => 'invoices'])->assertOk()->json('invoices'))->firstWhere('id', $invoice->id);
        $this->assertSame('148.80', $staged['total']);

        (new RecordPaymentService)->deleteTransaction($invoice->transactions()->firstOrFail());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: the payment deleted, it is unpaid again');

        try {
            $new = (new SplitInvoice)->handle($invoice->id, [$line]);
        } catch (DisplayException) {
            $this->addToAssertionCount(1); // refused: Ekdosi has it

            return;
        }
        $this->assertSame('124.00', number_format((float) $invoice->fresh()->total, 2, '.', ''), 'precondition: it is now 124.00');

        $status = $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000007781', 'code' => 'TIM-1'])->status();
        $this->assertNotSame(200, $status, 'a document of 148.80 is filed on an invoice of 124.00; the 24.80 on #' . $new->id . ' will be issued again');
    }

    /**
     * REV-778-2. What `EkdosiBridgeAdversarialRound2Test::test_r2_3b` guarded on main, as it was written before the PR
     * rewrote it (the PR's version adds a «Send to Ekdosi» first): Ekdosi pulls an unpaid invoice by id (its own «send to
     * Ekdosi of one invoice», op=invoice, which leaves no trace here), staff split it, Ekdosi issues its copy.
     *
     * On main: 409 invoice_changed. Now 200. docs/ekdosi-bridge.md lists it as not covered; the test is here so the price
     * of the rule is on the table with amounts: 148.80 filed on 124.00, 24.80 to be issued twice.
     */
    public function test_rev_778_2_an_unpaid_invoice_ekdosi_pulled_by_id_and_staff_then_split_takes_the_old_document(): void
    {
        [$invoice, $line] = $this->invoice();
        $this->bridge(['op' => 'invoice', 'invoice_id' => $invoice->id])->assertOk()->assertJsonPath('invoice.total', '148.80');

        try {
            $new = (new SplitInvoice)->handle($invoice->id, [$line]);
        } catch (DisplayException) {
            $this->addToAssertionCount(1);

            return;
        }
        $status = $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000007782'])->status();

        $this->assertNotSame(200, $status, 'the document covers 148.80, the invoice is 124.00; the 24.80 on #' . $new->id . ' has no document and comes back once paid');
    }

    /**
     * REV-778-3. The rule says «once it is Ekdosi's it stays as it is», but only a split, a merge and a move ask it. A
     * service ending takes its line off a shared invoice that staff SENT (CancelOpenInvoices, no staff at the keyboard):
     * the request is withdrawn without a word (an audit row), Ekdosi keeps the 148.80 it staged, and its MARK is taken for
     * an invoice of 124.00. With an issued document the same path leaves the invoice to staff; with a request it does not.
     *
     * On main: the same (nothing here is the PR's doing), but the PR writes the rule down and this path is not in it:
     * either the line stays for staff (as with a document), or staff are told the request was withdrawn.
     */
    public function test_rev_778_3_a_service_ending_takes_its_line_off_an_invoice_sent_to_ekdosi_and_nobody_is_told(): void
    {
        [$invoice, $line] = $this->invoice(services: true);
        SendToEkdosi::request([$invoice], true);
        $this->bridge(['op' => 'invoices', 'status' => 'requested'])->assertOk()->assertJsonPath('invoices.0.total', '148.80');
        $ended = Service::query()->findOrFail($invoice->items()->findOrFail($line)->reference_id);

        $left = CancelOpenInvoices::handle([$invoice->fresh()], 'service_ended', $ended);

        $changed = $invoice->items()->count() === 1;
        $told = $left !== [] || $invoice->fresh()->ekdosi_requested_at !== null;
        $this->assertTrue(!$changed || $told, 'the invoice staff sent at 148.80 is now 124.00, its request gone, nobody told; Ekdosi still holds 148.80');
        if ($changed) {
            $status = $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000007783'])->status();
            $this->assertNotSame(200, $status);
            $this->assertSame(0, EkdosiDocument::query()->count());
        }
    }

    /**
     * REV-778-4. What the customer of an «immediate invoicing» account gets when they change the payment method of ONE
     * service that shares an open invoice with another (ItemPaymentMethod::followOnOpenInvoices): the line's way is
     * changed first, then the split is refused by the new rule and swallowed. The invoice stays whole with lines of two
     * ways, which no saved method charges (AutoCharge::wayOf), and nobody is told: for such an account that is now every
     * shared open invoice, not the rare one with a payment on it. The same for an invoice staff sent to Ekdosi.
     *
     * On main: split, 124.00 stays and 24.80 goes to an invoice of its own with the new way, both charged.
     * Either the lines keep one way when the split is refused (ask refusal() before changing the way), or someone is told.
     */
    public function test_rev_778_4_a_payment_method_change_on_an_immediate_invoicing_account_leaves_one_invoice_of_two_ways(): void
    {
        $this->client->forceFill(['immediate_invoicing' => true])->save();
        [$invoice, $line] = $this->invoice(services: true);
        $other = Gateway::query()->create(['name' => 'Card', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $service = Service::query()->findOrFail($invoice->items()->findOrFail($line)->reference_id);

        $service->update(['payment_gateway_id' => $other->id]);

        $ways = $invoice->items()->get()->map(fn ($item) => $item->payment_gateway_id)->unique()->count();
        $this->assertTrue(Invoice::query()->count() === 2 || $ways === 1, 'one invoice, lines of two payment ways: no saved method charges it, and the customer saw nothing');
    }
}
