<?php

namespace Tests\Feature;

use App\Admin\Actions\SendToEkdosi;
use App\Enums\AdjustmentNoteType;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Adversarial review of #478 (E5, «Send to Ekdosi»), round 3. Each test is one finding and fails on the PR.
 */
class Adv478Round3Test extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-test-secret-of-at-least-thirty-two-chars';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.ekdosi_enabled' => true, 'settings.ekdosi_secret' => self::SECRET, 'settings.ekdosi_url' => 'https://ekdosi.example.test/ekdosi',
            'settings.default_currency' => 'USD']);
        Gateway::query()->create(['name' => 'Bank transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $this->client = User::factory()->create()->client();
        $this->client->forceFill(['ignore_invoicing' => true])->save();
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'A478c' . uniqid(), 'permissions' => ['*']])->id]), 'staff');
    }

    private function invoice(string $status = Invoice::STATUS_UNPAID): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);
        if ($status !== Invoice::STATUS_UNPAID) {
            Invoice::query()->whereKey($invoice->id)->update(['status' => $status]);
        }

        return $invoice->fresh();
    }

    /** @param array<string, mixed> $body */
    private function bridge(array $body): TestResponse
    {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/ekdosi', [], [], [], $this->transformHeadersToServerVars([
            'X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $raw, self::SECRET), 'Content-Type' => 'application/json']), $raw);
    }

    /** @return list<int> */
    private function requested(): array
    {
        return array_map(fn (array $invoice) => (int) $invoice['invoiceid'],
            $this->bridge(['op' => 'invoices', 'status' => 'requested'])->assertOk()->json('invoices'));
    }

    /**
     * R3-1 (P1): an adjustment note changes what the invoice bills (its `total`, `mycs.adjustments` and fingerprint) but
     * keeps the request, while a line change clears it (R2-3: «a request was for the lines as they stood»). Staff send
     * an unpaid invoice of 100 (the customer wants the document before paying); another staff writes a 40 credit note
     * on it (allowed while unpaid); `requested` lists it with a total of 60 that nobody asked a document for. A partial
     * refund of a paid requested invoice (its refund note) does the same. AdjustmentNoteObserver never calls forget().
     */
    public function test_a_credit_note_after_the_request_takes_it_off_as_a_line_change_does(): void
    {
        $invoice = $this->invoice();
        $this->assertSame(1, SendToEkdosi::request([$invoice], true));
        $this->assertSame([$invoice->id], $this->requested());

        $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -40, 'description' => 'Goodwill']);

        $this->assertNull($invoice->fresh()->ekdosi_requested_at, 'a credit note changed its total: the request should be gone');
        $this->assertSame([], $this->requested());
    }

    /**
     * R3-2 (P2): the request is never consumed. Ekdosi issues the document (`mark` active) and the request stays on the
     * invoice; once that document is cancelled at AADE (`mark` state=cancelled, e.g. the customer never paid the one
     * issued on credit), `requested` lists the invoice again on the old request, as if staff had just asked. Docs: the
     * feed lists it «until a document is active»; staff never asked for a second document.
     */
    public function test_a_document_issued_for_the_request_consumes_it(): void
    {
        $invoice = $this->invoice();
        $this->assertSame(1, SendToEkdosi::request([$invoice], true));

        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000001', 'state' => 'active'])->assertOk();
        $this->assertSame([], $this->requested());
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000001', 'state' => 'cancelled'])->assertOk();

        $this->assertSame([], $this->requested(), 'cancelled at AADE: listed again on a request already fulfilled');
    }

    /**
     * R3-3 (P2): a paid invoice set back to unpaid keeps its request. Staff sent it paid (the modal said nothing of
     * credit); it is set back to unpaid (staff reopen it, a payment deleted, a chargeback: ExtensionHelper makes a paid
     * invoice unpaid). `requested` now lists it unpaid, so Ekdosi issues it on credit, which staff never confirmed
     * («It is not paid: Ekdosi issues it on credit» is shown only when sending an unpaid one).
     */
    public function test_a_paid_request_does_not_become_a_request_on_credit(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_PAID);
        $this->assertSame(1, SendToEkdosi::request([$invoice], true));

        $invoice->update(['status' => Invoice::STATUS_UNPAID]);

        $this->assertNotContains($invoice->id, $this->requested(), 'sent paid, now listed unpaid for a document on credit');
    }
}
