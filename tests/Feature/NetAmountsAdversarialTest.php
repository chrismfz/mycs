<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Upgrade;
use App\Models\ApiKey;
use App\Models\Cart;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use Tests\TestCase;

/**
 * Adversarial review of Σ7b / Δ1 (net amounts, tax per line). Each test asserts the correct behaviour.
 */
class NetAmountsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const PAYPAL = 'https://api-m.sandbox.paypal.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tax();
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    /** The settings, again right before an action: the settings provider can reload them from the database. */
    private function tax(string $type = 'exclusive'): void
    {
        $values = ['tax_enabled' => [true, 'boolean'], 'tax_type' => [$type, 'string'], 'default_currency' => ['USD', 'string'],
            'credits_enabled' => [true, 'boolean'], 'credits_on_downgrade' => [true, 'boolean']];
        foreach ($values as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->client()->primaryContact->update(['country' => 'GR']);

        return $user;
    }

    private function client(): Client
    {
        return $this->user()->client()->fresh();
    }

    private function product(float $price, bool $taxable = true): Product
    {
        $product = Product::factory()->create(['taxable' => $taxable, 'name' => 'P' . $price]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function service(Client $client, Product $product, float $price, array $extra = []): Service
    {
        return Service::factory()->create($extra + ['client_id' => $client->id, 'product_id' => $product->id,
            'plan_id' => $product->plans()->first()->id, 'price' => $price, 'quantity' => 1, 'currency_code' => 'USD',
            'status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addDays(30)]);
    }

    private function invoice(Client $client): Invoice
    {
        return Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
    }

    private function creditBalance(Client $client): string
    {
        return number_format((float) (Credit::query()->where('client_id', $client->id)->where('currency_code', 'USD')->value('amount') ?? 0), 2, '.', '');
    }

    // ------------------------------------------------------------------ upgrades / downgrades

    /**
     * F1. A downgrade's unused part comes back as credit. The customer paid it WITH tax (10.00 net → 12.40 paid);
     * the credit is only the net difference (8.00), so 1.92 of what they paid is kept.
     */
    public function test_a_downgrade_credits_the_unused_amount_with_the_tax_that_was_paid(): void
    {
        $user = $this->user();
        $client = $user->client()->fresh();
        $from = $this->product(10.00);
        $to = $this->product(2.00);
        DB::table('product_upgrades')->insert(['product_id' => $from->id, 'upgrade_id' => $to->id]);
        $service = $this->service($client, $from, 10.00);

        $this->tax();
        $this->livewireAs($user)->test(Upgrade::class, ['service' => $service])->set('upgrade', $to->id)->call('doUpgrade');

        // Full period left (30 of 30 days): (10.00 − 2.00) × 1.24.
        $this->assertSame('9.92', $this->creditBalance($client));
    }

    /** F1b (inclusive catalog, regression): the catalog prices contain the tax; the credit used to be the gross difference. */
    public function test_an_inclusive_downgrade_credits_the_gross_difference(): void
    {
        $user = $this->user();
        $client = $user->client()->fresh();
        $from = $this->product(12.40);
        $to = $this->product(2.48);
        DB::table('product_upgrades')->insert(['product_id' => $from->id, 'upgrade_id' => $to->id]);
        $service = $this->service($client, $from, 10.00);

        $this->tax('inclusive');
        $this->livewireAs($user)->test(Upgrade::class, ['service' => $service])->set('upgrade', $to->id)->call('doUpgrade');

        $this->assertSame('9.92', $this->creditBalance($client));
    }

    /**
     * F2 (decided, not changed): an upgrade from a product sold without tax (paid 10.00) to a taxed one (20.00 net).
     * The reviewer expected the difference of what is paid with tax (24.80 − 10.00 = 14.80). MyCS does as WHMCS: the
     * upgrade is the net difference (20.00 − 10.00), taxed at the new product's rate: 12.40 (docs/orders.md, Δ1).
     */
    public function test_an_upgrade_between_untaxed_and_taxed_products_is_the_net_difference_taxed_as_the_new_product(): void
    {
        $user = $this->user();
        $client = $user->client()->fresh();
        $from = $this->product(10.00, taxable: false);
        $to = $this->product(20.00);
        DB::table('product_upgrades')->insert(['product_id' => $from->id, 'upgrade_id' => $to->id]);
        $service = $this->service($client, $from, 10.00);

        $this->tax();
        $this->livewireAs($user)->test(Upgrade::class, ['service' => $service])->set('upgrade', $to->id)->call('doUpgrade');

        $invoice = ServiceUpgrade::query()->sole()->invoice;
        $this->assertSame(12.40, (float) $invoice->fresh()->total);
    }

    /**
     * F8. A downgrade to a product sold without tax reprices the open renewal line (ServiceUpgradeService →
     * ChangeOpenLine) but the line keeps the 24% decided for the old product: the untaxed product is billed with tax.
     */
    public function test_an_open_renewal_repriced_to_an_untaxed_product_is_not_taxed(): void
    {
        $user = $this->user();
        $client = $user->client()->fresh();
        $from = $this->product(10.00);
        $to = $this->product(2.00, taxable: false);
        DB::table('product_upgrades')->insert(['product_id' => $from->id, 'upgrade_id' => $to->id]);
        $service = $this->service($client, $from, 10.00);
        $renewal = $this->invoice($client);
        $line = $renewal->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1,
            'reference_type' => Service::class, 'reference_id' => $service->id]);
        $this->assertSame('12.40', (string) $line->line_total);

        $this->tax();
        $this->livewireAs($user)->test(Upgrade::class, ['service' => $service])->set('upgrade', $to->id)->call('doUpgrade');

        $this->assertSame($to->id, (int) $service->fresh()->product_id);
        $line = $line->fresh();
        $this->assertSame('2.00', number_format((float) $line->price, 2, '.', ''));
        $this->assertSame('2.00', (string) $line->line_total);
    }

    // ------------------------------------------------------------------ what the customer is shown

    /** F3. The order e-mail («new_order_created») shows Order::formattedTotal: net, with a tax backed out of it. */
    public function test_the_order_total_the_customer_is_sent_includes_the_tax(): void
    {
        $placed = (new PlaceOrderService)->place($this->client(), 'USD', [new OrderLine($product = $this->product(20.00), $product->plans()->with('prices')->firstOrFail())]);

        $this->assertSame(24.80, (float) $placed->invoice->fresh()->total);
        $this->assertSame('24.80', $placed->order->fresh()->formattedTotal->total);
        $this->assertSame('4.80', $placed->order->fresh()->formattedTotal->total_tax);
    }

    /** F3b. The service page shows Service::formattedPrice: the net price as if it contained the tax. */
    public function test_the_service_page_shows_what_a_renewal_charges(): void
    {
        $client = $this->client();
        $service = $this->service($client, $this->product(20.00), 20.00);

        $this->assertSame(2480, $service->recurringGrossCents());
        $this->assertSame('24.80', $service->formattedPrice->total);
    }

    /**
     * F4. The cart says what checkout charges: its item adds the invoice line it will make (net × quantity, the tax once
     * on that: 99.00 + 23.76 = 122.76), not the gross unit price × quantity (1.23 × 100 = 123.00).
     */
    public function test_the_cart_total_is_what_the_invoice_charges_for_a_quantity(): void
    {
        $user = $this->user();
        $client = $user->client()->fresh();
        $product = $this->product(0.99);
        $cart = Cart::create(['currency_code' => 'USD']);
        $item = $cart->items()->create(['product_id' => $product->id, 'plan_id' => $product->plans()->first()->id,
            'config_options' => [], 'checkout_config' => [], 'quantity' => 100]);
        $this->actingAs($user);

        $placed = (new PlaceOrderService)->place($client, 'USD', [new OrderLine($product, $product->plans()->with('prices')->firstOrFail(), 100)]);

        $this->assertSame(122.76, (float) $placed->invoice->fresh()->total);
        $this->assertSame(122.76, $item->fresh()->lineTotal());
    }

    // ------------------------------------------------------------------ gateways

    /** F5. PayPal's subscription update sends Σ services.price: net, without tax (and without quantity). */
    public function test_a_paypal_subscription_update_bills_the_renewal_with_tax(): void
    {
        $client = $this->client();
        $service = $this->service($client, $this->product(20.00), 20.00, ['subscription_id' => 'I-SUB', 'quantity' => 2]);
        $service->properties()->create(['key' => 'has_paypal_subscription', 'value' => '1']);
        Http::fake([
            self::PAYPAL . '/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::PAYPAL . '/v1/billing/subscriptions/*' => Http::response([], 200),
        ]);

        (new PayPal(['test_mode' => true, 'client_id' => 'id', 'client_secret' => 'secret']))->updateSubscription($service);

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && abs((float) ($request->data()[0]['value']['value'] ?? 0) - 49.60) < 0.001);
    }

    // ------------------------------------------------------------------ credit purchases

    /** F6. A credit line of quantity 2 (staff/API) charges 20.00 (its line_total) but grants only its price, 10.00. */
    public function test_bought_credit_is_the_lines_total(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client);
        $invoice->items()->create(['description' => 'Credit', 'price' => '10.00', 'quantity' => 2, 'reference_type' => Credit::class]);
        $this->assertSame(20.00, (float) $invoice->fresh()->total);

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 20.00, null, 'pi_credit_qty', currency: 'USD');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('20.00', $this->creditBalance($client));
    }

    // ------------------------------------------------------------------ money check

    /** F7. line_totals checks total = net + tax_amount, never tax_amount against taxed/tax_rate: a wrong tax passes. */
    public function test_the_money_check_flags_a_tax_that_does_not_match_the_lines_rate(): void
    {
        $client = $this->client();
        $line = $this->invoice($client)->items()->create(['description' => 'L', 'price' => '20.00', 'quantity' => 1,
            'reference_type' => Service::class, 'reference_id' => $this->service($client, $this->product(20.00), 20.00)->id]);
        $this->assertSame('24.0000', (string) $line->tax_rate);

        DB::table('invoice_items')->where('id', $line->id)->update(['tax_amount' => 0, 'line_total' => 20.00]); // tax dropped
        $untaxed = $this->invoice($client)->items()->create(['description' => 'U', 'price' => '10.00', 'quantity' => 1, 'taxed' => false]);
        DB::table('invoice_items')->where('id', $untaxed->id)->update(['tax_amount' => 2.40, 'line_total' => 12.40]); // tax on an untaxed line

        $flagged = array_column(array_filter((new MoneyCheck)->run(['line_totals']), fn ($r) => $r['rule'] === 'line_totals'), 'subject');
        $this->assertCount(2, $flagged, json_encode($flagged));
    }

    // ------------------------------------------------------------------ regression guards (pass today)

    public function test_guard_the_api_cannot_set_a_lines_tax_rate_or_totals(): void
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 't', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $token), 'permissions' => ['admin.invoice_items.create', 'admin.invoice_items.update']]);
        $client = $this->client();
        $invoice = $this->invoice($client);

        $id = $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), [
            'invoice_id' => $invoice->id, 'description' => 'X', 'price' => 20, 'quantity' => 1,
            'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 1,
        ])->assertSuccessful()->json('data.id');

        $line = InvoiceItem::query()->findOrFail($id);
        $this->assertSame(['24.0000', '4.80', '24.80'], [(string) $line->tax_rate, (string) $line->tax_amount, (string) $line->line_total]);
    }

    public function test_guard_a_paid_invoices_line_cannot_be_untaxed(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client);
        $line = $invoice->items()->create(['description' => 'L', 'price' => '20.00', 'quantity' => 1]);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 24.80, null, 'pi_full', currency: 'USD');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        try {
            $line->fresh()->update(['taxed' => false]);
            $this->fail('A paid invoice\'s line lost its tax.');
        } catch (DisplayException) {
        }
        $this->assertSame('24.80', (string) $line->fresh()->line_total);
    }

    public function test_guard_a_partial_payment_of_the_net_leaves_the_tax_owing(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client);
        $invoice->items()->create(['description' => 'L', 'price' => '20.00', 'quantity' => 3]);

        (new RecordPaymentService)->handle($invoice->fresh(), null, 60.00, transactionId: 'net-only');

        $invoice = $invoice->fresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertEqualsWithDelta(14.40, (float) $invoice->remaining, 0.001);
    }

    public function test_guard_tax_off_globally_means_no_tax_on_a_new_line(): void
    {
        config(['settings.tax_enabled' => false]);
        $line = $this->invoice($this->client())->items()->create(['description' => 'L', 'price' => '20.00', 'quantity' => 1]);

        $this->assertSame(['0.00', '20.00'], [(string) $line->tax_amount, (string) $line->line_total]);
    }

    public function test_guard_a_negative_line_takes_its_tax_back(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client);
        $invoice->items()->create(['description' => 'L', 'price' => '20.00', 'quantity' => 1]);
        $invoice->items()->create(['description' => 'Promo', 'price' => '-5.00', 'quantity' => 1]);

        $invoice = $invoice->fresh();
        $this->assertSame([18.60, 15.00, 3.60], [(float) $invoice->total, (float) $invoice->subtotal, (float) $invoice->taxTotal]);
    }
}
