<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Clients\MoveToClient;
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

    /** @return array{Invoice, Invoice} an unpaid invoice of two lines, and what the split made of the second */
    private function split(?\Closure $before = null): array
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);
        $b = $invoice->items()->create(['description' => 'Domain', 'quantity' => 1, 'price' => '20.00']);
        if ($before !== null) {
            $before($invoice);
            $this->travel(1)->hours();
        }
        $new = (new SplitInvoice)->handle($invoice->id, [$b->id]);
        $this->travel(1)->hours();

        return [$invoice->fresh(), $new];
    }

    /** The other way: asked for («Send to Ekdosi», on credit) and then split, its document is of the invoice before the split. */
    public function test_an_invoice_split_after_staff_sent_it_to_ekdosi_still_refuses_its_document(): void
    {
        [$invoice] = $this->split(fn (Invoice $invoice) => Invoice::query()->whereKey($invoice->id)->update(['ekdosi_requested_at' => now()]));

        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000000003', 'code' => 'TPY-3'])
            ->assertStatus(409)->assertJsonPath('error', 'invoice_changed');
        $this->assertSame(0, $invoice->ekdosiDocuments()->count());
    }

    /** Refused too when the request is still on it (whatever withdraws it): the split came after it. */
    public function test_a_split_later_than_a_request_still_on_the_invoice_refuses_its_document(): void
    {
        [$invoice, $new] = $this->split();
        Invoice::query()->whereKey($invoice->id)->update(['ekdosi_requested_at' => now()->subHours(3)]);
        $this->assertTrue($new->split_at->gt($invoice->fresh()->ekdosi_requested_at));

        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000000004', 'code' => 'TPY-4'])
            ->assertStatus(409)->assertJsonPath('error', 'invoice_changed');
    }

    /** Split first, sent to Ekdosi after (unpaid, on credit): Ekdosi read the invoice as the split left it. */
    public function test_an_invoice_split_and_then_sent_to_ekdosi_takes_its_document(): void
    {
        [$invoice, $new] = $this->split();
        Invoice::query()->whereKey([$invoice->id, $new->id])->update(['ekdosi_requested_at' => now()]);

        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000000005', 'code' => 'TPY-5'])->assertOk();
        $this->bridge(['op' => 'mark', 'invoice_id' => $new->id, 'mark' => '400000000000006', 'code' => 'TPY-6'])->assertOk();
    }

    /** A split made before the time was kept (no split_at) is taken as before the read; never asked for nor paid, refused. */
    public function test_a_split_with_no_time_is_taken_as_before_the_read_and_an_invoice_never_ekdosis_to_read_is_refused(): void
    {
        [$invoice, $new] = $this->split();
        Invoice::query()->whereKey($new->id)->update(['split_at' => null]);
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000000007', 'code' => 'TPY-7'])
            ->assertStatus(409)->assertJsonPath('error', 'invoice_changed');

        ExtensionHelper::addPayment($invoice->fresh(), 'BankTransfer', (string) $invoice->fresh()->total, transactionId: 'TX-7');
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000000007', 'code' => 'TPY-7'])->assertOk();
    }

    /** A service moved off a shared unpaid invoice to another account splits it the same way (MoveToClient). */
    public function test_an_invoice_a_service_was_moved_off_before_it_was_paid_takes_its_document(): void
    {
        $to = User::factory()->create()->client();
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $this->client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 20, 'expires_at' => now()->addMonth()]);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $stays = Service::factory()->create(['client_id' => $this->client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 100, 'expires_at' => now()->addMonth()]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $stays->id, 'description' => 'Other', 'quantity' => 1, 'price' => '100.00']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Hosting', 'quantity' => 1, 'price' => '20.00']);

        $result = app(MoveToClient::class)->service($service, $to, Staff::factory()->create());
        $part = Invoice::query()->findOrFail($result['split'][$invoice->id]);
        $this->assertSame($invoice->id, $part->split_from_id);
        $this->assertNotNull($part->split_at);
        $this->travel(1)->hours();
        ExtensionHelper::addPayment($invoice->fresh(), 'BankTransfer', (string) $invoice->fresh()->total, transactionId: 'TX-8');

        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000000008', 'code' => 'TPY-8'])->assertOk();
    }
}
