<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Tax\EuVat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Adversarial review of #487 (the feed's `taxed` only when billed with tax).
 *
 * ADV-1: a 0% line now reaches Ekdosi `taxed: 0`. Before, Ekdosi put its default rate on it and its guard («WHMCS
 * charged no VAT but ekdosi would») held the invoice, even on a manual «file». Now a manual file goes through, and
 * Ekdosi files every 0% line under the tenant's single 0% exemption category, whatever MyCS's reason was (an exempt
 * account, a product that is not taxable, reverse charge, outside the EU: different §8.3 categories at myDATA). MyCS
 * keeps the reason per line exactly for this (LineTax, docs/eu-vat.md «Η γέφυρα προς το Ekdosi θα στέλνει τον λόγο
 * ανά γραμμή»), but the feed does not send it.
 */
class Adv487Test extends TestCase
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
        $this->client->primaryContact->forceFill(['type' => 'business', 'company_name' => 'Δοκιμή ΑΕ', 'tax_id' => '099999999', 'country' => 'GR'])->save();
    }

    /** @param array<string, mixed> $body */
    private function bridge(array $body): TestResponse
    {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/ekdosi', [], [], [], $this->transformHeadersToServerVars([
            'X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $raw, self::SECRET), 'Content-Type' => 'application/json']), $raw);
    }

    /** ADV-1: an exempt account's invoice, all 0%: the line says why, for Ekdosi's exemption category. */
    public function test_a_zero_rated_line_of_an_exempt_account_tells_ekdosi_why(): void
    {
        $this->client->forceFill(['tax_exempt' => true])->save();
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $line = $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '10.00']);
        $this->assertSame(EuVat::REASON_EXEMPT, $line->fresh()->tax_reason, 'precondition: billed at 0% as exempt');
        ExtensionHelper::addPayment($invoice->fresh(), 'BankTransfer', (string) $invoice->fresh()->total, transactionId: 'TX-1');

        $sent = collect($this->bridge(['op' => 'invoice', 'invoice_id' => $invoice->id])->assertOk()->json('invoice.items.item'))->keyBy('id')[$line->id];

        $this->assertSame(0, $sent['taxed']);
        $this->assertSame(EuVat::REASON_EXEMPT, $sent['tax_reason'] ?? null,
            'a 0% line reaches Ekdosi untaxed and is filed under its single 0% exemption: it needs MyCS\'s reason');
    }

    /** ADV-1: mixed 24% and not taxable: each line its own reason (none on the taxed one). */
    public function test_a_mixed_invoice_sends_the_reason_of_its_zero_rated_line_only(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $taxed = $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '5.00']);
        $zero = $invoice->items()->create(['description' => 'Licence (not taxable)', 'quantity' => 1, 'price' => '10.00']);
        $zero->forceFill(['taxed' => true, 'tax_rate' => '0', 'tax_reason' => EuVat::REASON_NOT_TAXABLE, 'tax_amount' => '0.00', 'line_total' => '10.00'])->saveQuietly();
        ExtensionHelper::addPayment($invoice->fresh(), 'BankTransfer', (string) $invoice->fresh()->total, transactionId: 'TX-2');

        $lines = collect($this->bridge(['op' => 'invoice', 'invoice_id' => $invoice->id])->assertOk()->json('invoice.items.item'))->keyBy('id');

        $this->assertSame(0, $lines[$zero->id]['taxed']);
        $this->assertSame(EuVat::REASON_NOT_TAXABLE, $lines[$zero->id]['tax_reason'] ?? null);
        // R2-1 of #487: no key on a taxed line, so a staged row with none at 0% reads unchanged to Ekdosi's re-read.
        $this->assertArrayNotHasKey('tax_reason', $lines[$taxed->id]);
    }
}
