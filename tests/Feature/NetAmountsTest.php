<?php

namespace Tests\Feature;

use App\Events\Service\RenewalTaxChanged;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Δ1 (docs/decisions.md, Σ7b): amounts are net and each invoice line carries its tax, rounded per line, with the rate
 * of when it was made; a product may be sold without tax and an account may be tax exempt (WHMCS `tblproducts.tax`,
 * `tblclients.taxexempt`).
 */
class NetAmountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.default_currency' => 'USD']);
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
    }

    private function client(array $attributes = []): Client
    {
        $client = User::factory()->create()->client();
        $client->primaryContact->update(['country' => 'GR']);
        if ($attributes !== []) {
            $client->forceFill($attributes)->save();
        }

        return $client->fresh();
    }

    private function service(Client $client, float $price = 20.00, bool $taxable = true): Service
    {
        $product = Product::factory()->create(['taxable' => $taxable]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'currency_code' => 'USD']);

        return Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => $price, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE]);
    }

    private function invoice(Client $client): Invoice
    {
        return Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
    }

    private function line(Invoice $invoice, string $price, int $quantity = 1, ?Service $service = null, array $extra = []): InvoiceItem
    {
        return $invoice->items()->create($extra + ['description' => 'Line', 'price' => $price, 'quantity' => $quantity,
            'reference_type' => $service ? Service::class : null, 'reference_id' => $service?->id]);
    }

    public function test_a_line_carries_the_accounts_tax_on_its_net_amount(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client);
        $line = $this->line($invoice, '20.00', 1, $this->service($client));

        $this->assertSame(['24.0000', '4.80', '24.80'], [(string) $line->tax_rate, (string) $line->tax_amount, (string) $line->line_total]);
        $invoice = $invoice->fresh();
        $this->assertSame([24.80, 20.00, 4.80], [(float) $invoice->total, (float) $invoice->subtotal, (float) $invoice->taxTotal]);
        $this->assertEqualsWithDelta(24.80, (float) $invoice->remaining, 0.001);
    }

    public function test_a_product_sold_without_tax_and_a_tax_exempt_account_get_none(): void
    {
        $client = $this->client();
        $untaxed = $this->line($this->invoice($client), '20.00', 1, $this->service($client, taxable: false));
        $this->assertSame(['0.00', '20.00'], [(string) $untaxed->tax_amount, (string) $untaxed->line_total]);

        $exempt = $this->client(['tax_exempt' => true]);
        $line = $this->line($this->invoice($exempt), '20.00', 1, $this->service($exempt));
        $this->assertSame(['0.00', '20.00'], [(string) $line->tax_amount, (string) $line->line_total]);
    }

    /** WHMCS: 22 (myip) and 12 (nixpal) invoices a year mix lines with and without tax. */
    public function test_an_invoice_mixes_taxed_and_untaxed_lines(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client);
        $this->line($invoice, '20.00', 1, $this->service($client));
        $this->line($invoice, '10.00', 1, $this->service($client, 10.00, taxable: false));
        $this->line($invoice, '5.00', 1, extra: ['taxed' => false]);

        $invoice = $invoice->fresh();
        $this->assertSame([39.80, 35.00, 4.80], [(float) $invoice->total, (float) $invoice->subtotal, (float) $invoice->taxTotal]);
        $this->assertSame([['rate' => '24.0000', 'amount' => 4.80]], $invoice->taxLines());
    }

    /** CART-14: the tax of a line is worked out on its total, once. */
    public function test_tax_is_rounded_per_line_not_per_unit(): void
    {
        $client = $this->client();
        $line = $this->line($this->invoice($client), '0.99', 100, $this->service($client, 0.99));

        $this->assertSame(['23.76', '122.76'], [(string) $line->tax_amount, (string) $line->line_total]);
    }

    /** An issued line keeps the rate it was made with; its amounts follow its price. */
    public function test_a_line_keeps_its_rate_when_the_account_changes(): void
    {
        $client = $this->client();
        $line = $this->line($this->invoice($client), '20.00', 1, $this->service($client));
        $client->forceFill(['tax_exempt' => true])->save();

        $line->update(['price' => '30.00']);

        $this->assertSame(['24.0000', '7.20', '37.20'], [(string) $line->tax_rate, (string) $line->tax_amount, (string) $line->line_total]);
    }

    public function test_untaxing_a_line_and_taxing_it_again_decides_the_rate_again(): void
    {
        $client = $this->client();
        $line = $this->line($this->invoice($client), '20.00', 1, $this->service($client));

        $line->update(['taxed' => false]);
        $this->assertSame(['0.0000', '0.00', '20.00'], [(string) $line->tax_rate, (string) $line->tax_amount, (string) $line->line_total]);

        $line->update(['taxed' => true]);
        $this->assertSame(['24.0000', '4.80', '24.80'], [(string) $line->tax_rate, (string) $line->tax_amount, (string) $line->line_total]);
    }

    /** Credit bought is not a sale (WHMCS «Add Funds»): never taxed. */
    public function test_a_credit_purchase_is_not_taxed(): void
    {
        $client = $this->client();
        $line = $this->line($this->invoice($client), '50.00', 1, extra: ['reference_type' => Credit::class]);

        $this->assertSame(['0.00', '50.00'], [(string) $line->tax_amount, (string) $line->line_total]);
    }

    /** An imported line keeps the rate it was issued with. */
    public function test_a_given_rate_is_kept(): void
    {
        $client = $this->client();
        $line = $this->line($this->invoice($client), '100.00', 1, extra: ['tax_rate' => '22']);

        $this->assertSame(['22.0000', '22.00', '122.00'], [(string) $line->tax_rate, (string) $line->tax_amount, (string) $line->line_total]);
    }

    public function test_the_invoice_page_shows_the_tax_by_rate(): void
    {
        $user = User::factory()->create();
        $client = CurrentClient::defaultFor($user);
        $client->primaryContact->update(['country' => 'GR']);
        $invoice = $this->invoice($client->fresh());
        $this->line($invoice, '20.00', 1, $this->service($client->fresh()));

        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $client->id]))
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('ΦΠΑ (24%)')
            ->assertSee('24.80');
    }

    public function test_an_upgrade_line_follows_its_product_and_a_loose_line_the_account(): void
    {
        $client = $this->client();
        $service = $this->service($client);
        $untaxed = Product::factory()->create(['taxable' => false]);
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'product_id' => $untaxed->id, 'plan_id' => $service->plan_id, 'status' => ServiceUpgrade::STATUS_PENDING]);
        $invoice = $this->invoice($client);

        $upgradeLine = $this->line($invoice, '10.00', extra: ['reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id]);
        $staffLine = $this->line($invoice, '10.00');

        $this->assertSame('0.00', (string) $upgradeLine->tax_amount, 'the upgrade is to a product sold without tax');
        $this->assertSame('2.40', (string) $staffLine->tax_amount, 'a line of nothing takes the account\'s rate');
    }

    /** What the customer sees for a service and an order is what renewals charge, tax included. */
    public function test_service_and_order_amounts_are_shown_with_tax(): void
    {
        $client = $this->client();
        $service = $this->service($client, 20.00);
        $order = Order::query()->create(['client_id' => $client->id, 'currency_code' => 'USD']);
        $service->update(['order_id' => $order->id]);

        $this->assertSame('24.80', number_format((float) $service->fresh()->formattedPrice->price, 2, '.', ''));
        $this->assertSame(24.80, (float) $order->fresh()->total);
    }

    /** A subscription billing a renewal by itself follows a change of its tax (code review S4). */
    public function test_a_tax_change_tells_the_subscriptions(): void
    {
        Event::fake([RenewalTaxChanged::class]);
        $client = $this->client();
        $subscribed = $this->service($client);
        $subscribed->update(['subscription_id' => 'sub_test']);
        $this->service($client);

        $client->update(['tax_exempt' => true]);
        Event::assertDispatched(RenewalTaxChanged::class, 1);
        Event::assertDispatched(RenewalTaxChanged::class, fn (RenewalTaxChanged $event) => $event->service->is($subscribed));

        $subscribed->product->update(['taxable' => false]);
        $client->primaryContact->update(['country' => 'DE']);
        Event::assertDispatched(RenewalTaxChanged::class, 3);
    }

    public function test_a_renewal_charges_the_net_price_with_tax(): void
    {
        $client = $this->client();
        $service = $this->service($client, 20.00);

        $this->assertSame(2480, $service->recurringGrossCents());
        $this->assertSame('20.00', $service->calculatePrice());
    }
}
