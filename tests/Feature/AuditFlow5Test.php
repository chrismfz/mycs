<?php

namespace Tests\Feature;

use App\Livewire\Auth\Register;
use App\Livewire\Cart as CartPage;
use App\Livewire\Traits\EditsContact;
use App\Models\Cart;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Order\OrderPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pre-launch audit, flow 5 (orders, cart, VAT, promotions; docs/prelaunch-audit.md).
 *
 * AUD5-1 (High): an account's country decides its VAT (`Settings::tax`, `EuVat::zeroReason`), and an account may have
 * none: the country is optional on registration and on the account page (`EditsContact`: nullable, the form's first
 * option is «Select a country»), and a login that checks out without an account gets one with no country at all
 * (`Client::openFor` from the cart). With no country only a TaxRate of «all» applies. nixpal's rates are one per member
 * state (OSS, docs/decisions.md: 27 rates) and myip's is Greece's: with no «all» row such an order is invoiced at 0%,
 * with no reason on the line (not reverse charge, not outside the EU, not exempt) — a consumer's sale without VAT that
 * the company owes. WHMCS always asks a country. Refused, or charged (the home rate), are both fine; 0% without a
 * reason is not.
 */
class AuditFlow5Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.default_currency' => 'USD',
            'settings.tos' => false, 'settings.mail_must_verify' => false, 'settings.captcha' => 'disabled']);
    }

    /** nixpal (decision 9): EU VAT, home EE, a rate per member state, no «all» row. */
    private function nixpal(): void
    {
        config(['settings.eu_vat_enabled' => true, 'settings.eu_vat_home_country' => 'EE']);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'EE']);
        TaxRate::create(['name' => 'MwSt', 'rate' => 19, 'country' => 'DE']);
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
    }

    /** myip: no EU VAT, Greece's 24%. */
    private function myip(): void
    {
        config(['settings.eu_vat_enabled' => false]);
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
    }

    private function product(): Product
    {
        $product = Product::factory()->create(['name' => 'Hosting', 'autosetup' => Product::AUTOSETUP_NEVER, 'server_id' => null, 'taxable' => true]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10.00, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    /** A guest's cart with the product, checked out by $user through the customer's cart page. */
    private function checkout(User $user): Invoice
    {
        $product = $this->product();
        $cart = Cart::create(['currency_code' => 'USD']);
        $cart->items()->create(['product_id' => $product->id, 'plan_id' => $product->plans()->firstOrFail()->id,
            'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);
        Once::flush();

        Livewire::withCookie('cart', $cart->ulid)->actingAs($user)->test(CartPage::class)->call('checkout')->assertHasNoErrors();

        return Invoice::query()->latest('id')->firstOrFail();
    }

    private function assertNoUntaxedSale(InvoiceItem $line, string $message): void
    {
        $this->assertFalse((float) $line->tax_rate === 0.0 && $line->tax_reason === null,
            "{$message}: invoiced at {$line->tax_rate}% with no reason (no reverse charge, not outside the EU, not exempt)");
    }

    /** Control (passes today): the same checkout with a country is taxed, so the failures below are the missing country. */
    public function test_control_a_checkout_with_a_country_is_taxed(): void
    {
        $this->myip();
        $user = User::factory()->create();
        $user->client()->primaryContact->update(['country' => 'GR']);

        $line = $this->checkout($user->fresh())->items()->sole();

        $this->assertSame('24.0000', number_format((float) $line->tax_rate, 4, '.', ''));
    }

    /** nixpal: a consumer who signs up without choosing a country, then orders. */
    public function test_aud5_1_a_signup_without_a_country_is_invoiced_without_vat(): void
    {
        $this->nixpal();

        Livewire::test(Register::class)
            ->set('first_name', 'Maria')->set('last_name', 'Example')->set('email', 'maria@example.test')
            ->set('password', 'Corr3ct-Horse-Battery')->set('password_confirmation', 'Corr3ct-Horse-Battery')
            ->call('submit')->assertHasNoErrors();
        $user = User::query()->where('email', 'maria@example.test')->sole();
        $this->assertNull($user->client()->country, 'precondition: registered without a country (the form allows it)');
        Auth::logout();

        $invoice = $this->checkout($user->fresh());

        $this->assertNoUntaxedSale($invoice->items()->sole(), 'a nixpal consumer who left the country empty');
    }

    /** nixpal: a login with no account of its own checks out; the cart opens one for it, with no country. */
    public function test_aud5_1_the_account_opened_at_checkout_has_no_country_and_pays_no_vat(): void
    {
        $this->nixpal();
        $user = User::factory()->create();
        // As a login without an account (an invite never accepted, or its account removed): the cart opens one.
        $user->clients()->detach();
        $this->assertSame(0, Client::query()->whereHas('users', fn ($q) => $q->whereKey($user->id))->count(), 'precondition');

        $invoice = $this->checkout($user);

        $this->assertNull($invoice->client->country, 'precondition: the account checkout opened has no country');
        $this->assertNoUntaxedSale($invoice->items()->sole(), 'the account opened at checkout');
    }

    /** myip: a Greek consumer empties the country on the account page (allowed) and the next order has no VAT. */
    public function test_aud5_1_a_greek_customer_who_clears_the_country_pays_no_vat(): void
    {
        $this->myip();
        $user = User::factory()->create();
        $client = $user->client();
        $client->primaryContact->update(['country' => 'GR']);
        $this->assertSame('24.0000', number_format((float) OrderPricing::taxRate($client->fresh()), 4, '.', ''), 'precondition: 24% in Greece');

        // The account page's rule: country nullable (EditsContact::contactRules).
        $rules = (new class
        {
            use EditsContact;

            public function rulesFor(): array
            {
                return $this->contactRules();
            }
        })->rulesFor();
        $this->assertTrue(validator(['contact' => ['type' => 'individual', 'country' => null]], ['contact.type' => $rules['contact.type'], 'contact.country' => $rules['contact.country']])->passes(),
            'precondition: the account page accepts an empty country');
        $client->primaryContact->update(['country' => null]);

        $invoice = $this->checkout($user->fresh());

        $this->assertNoUntaxedSale($invoice->items()->sole(), 'a myip customer who cleared the country');
    }
}
