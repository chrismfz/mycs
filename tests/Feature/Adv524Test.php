<?php

namespace Tests\Feature;

use App\Livewire\Cart as CartPage;
use App\Models\Cart;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of #524 (AUD5-1: an account with no country invoiced at 0% with no reason). The owner's decision:
 * such an invoice pays the «all» rate, else the company's home rate, never 0%.
 */
class Adv524Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.default_currency' => 'USD',
            'settings.tos' => false, 'settings.mail_must_verify' => false, 'settings.captcha' => 'disabled']);
    }

    private function assertNoUntaxedSale(InvoiceItem $line, string $message): void
    {
        $this->assertFalse((float) $line->tax_rate === 0.0 && $line->tax_reason === null,
            "{$message}: invoiced at {$line->tax_rate}% with no reason");
    }

    /** A line staff (or the cron) adds to an invoice of the account: its tax is decided by LineTax. */
    private function staffLine(int $clientId): InvoiceItem
    {
        $invoice = Invoice::factory()->create(['client_id' => $clientId, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD', 'due_at' => now()->addWeek()]);

        return $invoice->items()->create(['description' => 'Hosting renewal', 'quantity' => 1, 'price' => 10])->fresh();
    }

    /**
     * ADV-524-1: myip as documented (docs/eu-vat.md: eu_vat_home_country «–» for myip; AuditFlow5Test: «myip's is
     * Greece's» rate, no «all»). An account imported from WHMCS without a country: the fallback has neither an «all»
     * row nor a home country, so the cron's or staff's invoice is still 0% with no reason, the very AUD5-1 case.
     */
    public function test_adv_524_1_myip_an_imported_account_without_a_country_is_still_invoiced_at_zero(): void
    {
        config(['settings.eu_vat_enabled' => false, 'settings.eu_vat_home_country' => null]);
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        $client = User::factory()->create()->client();
        $client->primaryContact->update(['country' => null]); // imported: WHMCS's country empty

        $this->assertNoUntaxedSale($this->staffLine($client->id), 'myip, no country');
    }

    /**
     * ADV-524-2: a country that is not empty but not a known one (the importer writes strtoupper(substr(country, 0, 2))
     * unchecked, ImportFromWhmcs.php:618). EuVat::iso calls it «no country» (ADV-4), but Settings::tax's fallback tests
     * blank() on the raw value, and PlaceOrderService's refusal too: the order goes through at 0% with no reason.
     */
    public function test_adv_524_2_nixpal_an_unknown_country_code_orders_at_zero_with_no_reason(): void
    {
        config(['settings.eu_vat_enabled' => true, 'settings.eu_vat_home_country' => 'EE']);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'EE']);
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        $user = User::factory()->create();
        $user->client()->primaryContact->update(['country' => 'XX']);

        $product = Product::factory()->create(['name' => 'Hosting', 'autosetup' => Product::AUTOSETUP_NEVER, 'server_id' => null, 'taxable' => true]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10.00, 'setup_fee' => 0, 'currency_code' => 'USD']);
        $cart = Cart::create(['currency_code' => 'USD']);
        $cart->items()->create(['product_id' => $product->id, 'plan_id' => $plan->id, 'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);
        Once::flush();
        app(CurrentClient::class)->forget();

        Livewire::withCookie('cart', $cart->ulid)->actingAs($user->fresh())->test(CartPage::class)->call('checkout');

        $invoice = Invoice::query()->latest('id')->first();
        if ($invoice === null) {
            $this->assertTrue(true); // refused: fine

            return;
        }
        $this->assertNoUntaxedSale($invoice->items()->sole(), 'nixpal, country XX');
    }

    /** ADV-524-2b: the same account's invoice from the cron or staff. */
    public function test_adv_524_2b_nixpal_an_unknown_country_code_is_invoiced_at_zero_by_the_cron(): void
    {
        config(['settings.eu_vat_enabled' => true, 'settings.eu_vat_home_country' => 'EE']);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'EE']);
        $client = User::factory()->create()->client();
        $client->primaryContact->update(['country' => 'XX']);

        $this->assertNoUntaxedSale($this->staffLine($client->id), 'nixpal, country XX');
    }
}
