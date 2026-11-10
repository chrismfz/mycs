<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Invoice\SplitInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Sweep 7, adversarial verifier, «tax-ekdosi». Failing tests for what was confirmed.
 *
 * S7-TAX-EKDOSI-1: EkdosiDocuments::changed() reads «split since Ekdosi read it» as «was ever split»: an invoice that
 * staff split while it was unpaid refuses its document's MARK for good once it is paid and issued (K1 sends no fingerprint).
 */
class Sweep7TaxEkdosiTest extends TestCase
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

    public function test_s7_tax_ekdosi_1_an_invoice_split_before_it_was_paid_takes_its_document(): void
    {
        $this->markTestIncomplete('owner decision pending: needs a read time or the fingerprint');

        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $a = $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);
        $b = $invoice->items()->create(['description' => 'Domain', 'quantity' => 1, 'price' => '20.00']);
        // Staff split it while open: «cut and sew».
        $new = (new SplitInvoice)->handle($invoice->id, [$b->id]);
        // Both are paid, days later.
        ExtensionHelper::addPayment($invoice->fresh(), 'BankTransfer', (string) $invoice->fresh()->total, transactionId: 'TX-1');
        ExtensionHelper::addPayment($new->fresh(), 'BankTransfer', (string) $new->fresh()->total, transactionId: 'TX-2');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        // Ekdosi reads the Inbox now (after the split), issues, and writes back as K1 does: without a fingerprint.
        $ids = array_column($this->bridge(['op' => 'invoices'])->assertOk()->json('invoices'), 'id');
        $this->assertContains($invoice->id, $ids);
        $this->assertContains($new->id, $ids);

        $second = $this->bridge(['op' => 'mark', 'invoice_id' => $new->id, 'mark' => '400000000000002', 'code' => 'TPY-2']);
        $first = $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000000001', 'code' => 'TPY-1']);
        $second->assertOk();
        // Fails here: 409 invoice_changed, «Split since», though the split was before Ekdosi ever read it.
        $first->assertOk();
        // And it leaves the Inbox.
        $this->assertNotContains($invoice->id, array_column($this->bridge(['op' => 'invoices'])->json('invoices'), 'id'));
    }
}
