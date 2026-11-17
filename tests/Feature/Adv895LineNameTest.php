<?php

namespace Tests\Feature;

use App\Livewire\Invoices\Show as InvoiceShow;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\License;
use App\Models\Service;
use App\Models\User;
use App\Services\Ekdosi\EkdosiFeed;
use App\Support\ServiceIdentifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of #895 (Service::lineName writes ServiceIdentifier into every new invoice line's text).
 */
class Adv895LineNameTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => 'active', 'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'domain' => null])->fresh();
    }

    /** @return array{0: Invoice, 1: InvoiceItem} */
    private function invoiced(Service $service): array
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $item = $invoice->items()->create(['description' => $service->describeLine(Carbon::parse('2026-11-24'), Carbon::parse('2027-11-24')),
            'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);

        return [$invoice, $item];
    }

    /**
     * ADV-895-1. The portal's invoice page, when forLine has nothing to add, falls back to `$item->reference->label`, whose
     * accessor is never empty (baseLabel «<product> #<id>»). Before #895 a new line's forLine named the domain; now the
     * text says it, forLine is '', and every new line of a service gets «LITESPEED 20 #42» printed under it.
     */
    public function test_ADV_895_1_the_invoice_page_prints_no_product_and_id_under_a_new_line(): void
    {
        $service = $this->service(['domain' => 'shop.example.gr']);
        [$invoice] = $this->invoiced($service);
        $baseLabel = $service->product->name . ' #' . $service->id;

        $this->livewireAs($this->user)->test(InvoiceShow::class, ['invoice' => $invoice])
            ->assertSee('shop.example.gr')
            ->assertDontSee($baseLabel);
    }

    /** ADV-895-1 (same branch): a service's own label, now in the line's text, is printed a second time under it. */
    public function test_ADV_895_1_the_invoice_page_does_not_repeat_the_label_under_a_new_line(): void
    {
        $service = $this->service(['label' => 'adv-office-box']);
        License::query()->create(['service_id' => $service->id, 'license_key' => 'NIXPAL-ABCDEFGH9999', 'status' => 'active', 'valid_ip' => '198.51.100.9']);
        [$invoice] = $this->invoiced($service->fresh());

        $html = $this->livewireAs($this->user)->test(InvoiceShow::class, ['invoice' => $invoice])->html();
        $this->assertSame(1, substr_count($html, 'adv-office-box'), 'once, in the line\'s text');
    }

    /**
     * ADV-895-2. Str::limit(60) cuts what tells it apart mid-part («… · 203.0.113…»); forLine then no longer finds the
     * part in the text and the portal page adds it again under the (already truncated) line.
     */
    public function test_ADV_895_2_a_cut_identifier_is_not_added_again_by_the_invoice_page(): void
    {
        $service = $this->service(['domain' => 'customer-online-store-' . str_repeat('x', 22) . '.example.gr', 'dedicated_ip' => '203.0.113.10']);
        [, $item] = $this->invoiced($service);

        $this->assertSame('', ServiceIdentifier::forLine($item->fresh()), 'the line\'s text: ' . $item->description);
    }

    /**
     * ADV-895-2 (the text itself). A real domain longer than 60 characters is cut on the PDF, the mail and the fiscal
     * document (Ekdosi): the customer's invoice names a domain that does not exist («….exampl...»).
     */
    public function test_ADV_895_2_a_long_domain_is_written_whole_on_the_line(): void
    {
        $domain = 'my-very-long-online-shop-name-for-greek-customers.example.com.gr';
        $this->assertGreaterThan(60, strlen($domain));
        $service = $this->service(['domain' => $domain]);

        $this->assertStringContainsString($domain, $service->describeLine(Carbon::parse('2026-11-24'), Carbon::parse('2027-11-24')));
    }

    /**
     * ADV-895-3 (owner's call). A service's label is the customer's own free text (Services\Show, max 255; imported from
     * WHMCS's mod_clientproductdescriptions, which WHMCS never printed on an invoice). For a license it comes first, and
     * it now goes into the line's text: the PDF and the fiscal document Ekdosi issues from the bridge's `description`.
     */
    public function test_ADV_895_3_a_customers_own_label_is_not_on_the_fiscal_line(): void
    {
        $service = $this->service(['label' => 'Γιώργος - χρωστάει ακόμα']);
        License::query()->create(['service_id' => $service->id, 'license_key' => 'NIXPAL-ABCDEFGH9999', 'status' => 'active', 'valid_ip' => '198.51.100.9']);
        [$invoice] = $this->invoiced($service->fresh());

        $lines = app(EkdosiFeed::class)->one((int) $invoice->id)['items']['item'] ?? [];
        $this->assertNotEmpty($lines);
        $this->assertStringNotContainsString('χρωστάει', (string) $lines[0]['description']);
    }
}
