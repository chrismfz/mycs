<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Ekdosi\EkdosiDocuments;
use App\Services\Ekdosi\EkdosiFeed;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Services\Reports\IncomeReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep 6, tax-ekdosi: adversarial verification (failing tests for what was confirmed).
 */
class Sweep6TaxEkdosiTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        Cache::flush();
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive',
            'settings.credits_enabled' => true, 'settings.credits_auto_use' => false,
            'settings.system_email_address' => 'staff@example.test']);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
        $this->client = User::factory()->create()->client();
        $this->client->primaryContact->update(['country' => 'GR']);
        $this->client = $this->client->fresh();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $net, ?Service $service = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $net,
            'reference_type' => $service ? Service::class : null, 'reference_id' => $service?->id]);

        return $invoice->fresh();
    }

    private function service(bool $taxable): Service
    {
        $product = Product::factory()->create(['taxable' => $taxable]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 100, 'currency_code' => 'EUR']);

        return Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 100, 'quantity' => 1, 'currency_code' => 'EUR', 'status' => Service::STATUS_ACTIVE]);
    }

    /** S6-TAX-EKDOSI-1: downgrade credit spent on another invoice is counted again as income. */
    public function test_downgrade_credit_spent_is_not_income_twice(): void
    {
        Carbon::setTestNow('2026-03-10 10:00:00');
        $a = $this->invoice('100.00');
        ExtensionHelper::addPayment($a->id, 'Stripe', 124.00, transactionId: 'pi_a', currency: 'eur');
        $this->assertSame(Invoice::STATUS_PAID, $a->fresh()->status);

        // The downgrade's credit, as PlaceUpgrade gives it (gross, with its tax), with no note on A.
        Carbon::setTestNow('2026-06-10 10:00:00');
        (new CreditLedger)->add((int) $this->client->id, 'EUR', 3100, MoneyTrail::CAUSE_DOWNGRADE, MoneyTrail::SOURCE_SYSTEM);

        Carbon::setTestNow('2026-07-10 10:00:00');
        $b = $this->invoice('25.00');
        $this->assertNotNull((new RecordPaymentService)->payWithCredit($b->id));
        $this->assertSame(Invoice::STATUS_PAID, $b->fresh()->status);

        $totals = IncomeReport::year('EUR', 2026)['totals'];
        $this->assertSame('124.00', $totals['total'], 'only 124.00 came in; the downgrade credit is counted again when spent');
        $this->assertSame('24.00', $totals['tax']);
    }

    /** S6-TAX-EKDOSI-3: a chargeback in the next year changes the closed year and adds no reversal to the new one. */
    public function test_a_chargeback_next_year_leaves_the_closed_year_as_sent(): void
    {
        Carbon::setTestNow('2026-12-20 10:00:00');
        $invoice = $this->invoice('100.00');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 124.00, transactionId: 'pi_cb', currency: 'eur');
        $sent = IncomeReport::year('EUR', 2026)['totals'];
        $this->assertSame('124.00', $sent['total']);

        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_cb', 'dp_cb', 124.00, 'eur'));
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        $this->assertSame($sent, IncomeReport::year('EUR', 2026)['totals'], '2026 as it was sent to the accountant');
        $this->assertSame('-124.00', IncomeReport::year('EUR', 2027)['totals']['total'], 'the money lost is a reversal of 2027');
    }

    /** S6-TAX-EKDOSI-2: the fingerprint misses a change of the line's net/VAT split at the same total. */
    public function test_a_change_of_vat_at_the_same_total_changes_the_fingerprint(): void
    {
        $invoice = $this->invoice('100.00');
        $line = $invoice->items()->sole();
        $this->assertSame(['24.00', '124.00'], [(string) $line->tax_amount, (string) $line->line_total]);
        $read = EkdosiFeed::fingerprint($invoice->fresh(['items', 'adjustmentNotes']));

        $line->update(['price' => '124.00', 'taxed' => false]);
        $line->refresh();
        $this->assertSame(['0.00', '124.00'], [(string) $line->tax_amount, (string) $line->line_total]);

        [$status, $body] = (new EkdosiDocuments)->record(['invoice_id' => $invoice->id, 'mark' => '400001234567890', 'fingerprint' => $read]);
        $this->assertSame(409, $status, 'a document read with 24.00 VAT is taken for an invoice now of 0 VAT: ' . json_encode($body));
    }

    /** S6-TAX-EKDOSI-4: a line pointed at another service keeps the old product's tax decision. */
    public function test_a_line_moved_to_a_taxable_service_is_taxed(): void
    {
        $untaxed = $this->service(false);
        $taxed = $this->service(true);
        $invoice = $this->invoice('100.00', $untaxed);
        $line = $invoice->items()->sole();
        $this->assertSame('0.00', (string) $line->tax_amount);

        $line = InvoiceItem::query()->findOrFail($line->id);
        $line->fill(['reference_type' => Service::class, 'reference_id' => $taxed->id])->save();
        $line->refresh();

        $this->assertSame(['24.0000', '24.00', null], [(string) $line->tax_rate, (string) $line->tax_amount, $line->tax_reason],
            'the line now bills a taxable product');
    }
}
