<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Ekdosi feed's `taxed`, as WHMCS's: «tax applies at the invoice's rate». A line staff left ticked but billed at 0%
 * (a product that is not taxable, an exempt account) was sent taxed: Ekdosi put 24% on it, and a split, which reconciles
 * no totals, made documents for more than was paid (live test of 2026-10-27).
 */
class EkdosiFeedTaxedTest extends TestCase
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

    public function test_a_line_billed_at_zero_is_sent_untaxed_and_a_taxed_one_taxed(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $taxed = $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '5.00']);
        $zero = $invoice->items()->create(['description' => 'Licence (not taxable)', 'quantity' => 1, 'price' => '10.00']);
        // Ticked, but billed at 0% (as a product that is not taxable decides it, LineTax).
        $zero->forceFill(['taxed' => true, 'tax_rate' => '0', 'tax_reason' => 'not_taxable', 'tax_amount' => '0.00', 'line_total' => '10.00'])->saveQuietly();
        $untaxed = $invoice->items()->create(['description' => 'Fee', 'quantity' => 1, 'price' => '2.00', 'taxed' => false]);
        ExtensionHelper::addPayment($invoice->fresh(), 'BankTransfer', (string) $invoice->fresh()->total, transactionId: 'TX-1');

        $lines = collect($this->bridge(['op' => 'invoice', 'invoice_id' => $invoice->id])->assertOk()->json('invoice.items.item'))->keyBy('id');

        $this->assertSame(1, $lines[$taxed->id]['taxed']);
        $this->assertSame(0, $lines[$zero->id]['taxed'], 'billed at 0%: Ekdosi must not add 24%');
        $this->assertSame(0, $lines[$untaxed->id]['taxed']);
        $this->assertSame('24', rtrim(rtrim((string) $this->bridge(['op' => 'invoice', 'invoice_id' => $invoice->id])->json('invoice.taxrate'), '0'), '.'));
    }

    /** Review of #487: a tax-exempt account bills every line at 0%: all untaxed, and no rate in the header. */
    public function test_an_exempt_accounts_lines_all_go_untaxed(): void
    {
        $this->client->forceFill(['tax_exempt' => true])->save();
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '10.00']);
        $invoice->items()->create(['description' => 'Domain', 'quantity' => 1, 'price' => '5.00']);
        ExtensionHelper::addPayment($invoice->fresh(), 'BankTransfer', (string) $invoice->fresh()->total, transactionId: 'TX-2');

        $payload = $this->bridge(['op' => 'invoice', 'invoice_id' => $invoice->id])->assertOk()->json('invoice');

        $this->assertSame([0, 0], array_column($payload['items']['item'], 'taxed'));
        $this->assertSame('0', $payload['taxrate']);
        $this->assertSame('0.00', $payload['tax']);
    }
}
