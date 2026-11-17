<?php

namespace Tests\Feature;

use App\Livewire\Client\Credits;
use App\Livewire\Invoices\Show;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Component;
use MyCS\Extensions\Gateways\Mollie\Mollie;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Adversarial review of #896: Add Funds calls no gateway (it only goes to the invoice, ?pay=1, its gateway picked) and the
 * Stripe windows write the customer's name and email as JavaScript (@js).
 */
class Adv896Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        $this->storeSettings(['credits_enabled' => true, 'credits_minimum_deposit' => 5, 'credits_maximum_deposit' => 500,
            'credits_maximum_credit' => 1000, 'default_currency' => 'EUR']);
        $this->user = User::factory()->create();
    }

    private function gateway(string $extension, array $settings = []): Gateway
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => $extension], ['name' => $extension, 'type' => 'gateway', 'enabled' => true]);
        foreach ($settings as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return $gateway->fresh();
    }

    private function deposit(Gateway $gateway, float $amount = 20): Invoice
    {
        $this->livewireAs($this->user)->test(Credits::class)
            ->set('currency', 'EUR')->set('amount', $amount)->set('gateway', $gateway->id)->call('addCredit')
            ->assertHasNoErrors()->assertRedirectContains('?pay=1');

        return Invoice::query()->where('client_id', $this->user->client()->id)->latest('id')->firstOrFail();
    }

    /** A redirect gateway (Mollie): nothing is sent from Add Funds; on the invoice it is picked and one click opens it. */
    public function test_a_redirect_gateway_is_not_called_by_add_funds_and_one_click_on_the_invoice_opens_it(): void
    {
        $mollie = $this->gateway('Mollie', ['api_key' => 'test_key']);
        (new Mollie(['api_key' => 'test_key']))->boot();
        app('router')->getRoutes()->refreshNameLookups();
        Http::fake(['https://api.mollie.com/v2/payments' => Http::response(['id' => 'tr_dep', '_links' => ['checkout' => ['href' => 'https://mollie.test/checkout/tr_dep']]])]);

        $invoice = $this->deposit($mollie);
        Http::assertNothingSent();
        $this->assertNull(DB::table('invoices')->where('id', $invoice->id)->value('checkout_opened_at'), 'no checkout opened before «Pay»');

        $this->livewireAs($this->user)->withQueryParams(['pay' => '1'])->test(Show::class, ['invoice' => $invoice])
            ->assertSet('showPayModal', true)
            ->assertSet('selectedMethod', 'gateway-' . $mollie->id)
            ->call('processPayment')
            ->assertRedirect('https://mollie.test/checkout/tr_dep');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['amount']['value'] === '20.00' && $r['amount']['currency'] === 'EUR');
        $this->assertNotNull(DB::table('invoices')->where('id', $invoice->id)->value('checkout_opened_at'), 'the checkout is stamped, as any other');
    }

    /** Stripe: Add Funds makes no PaymentIntent (the old path made one and threw its window away). */
    public function test_stripe_add_funds_makes_no_payment_intent(): void
    {
        Http::fake();
        $stripe = $this->gateway('Stripe', ['stripe_secret_key' => 'sk_test_x', 'stripe_publishable_key' => 'pk_test_x']);

        $invoice = $this->deposit($stripe);

        Http::assertNothingSent();
        $this->assertSame($stripe->id, (int) $invoice->preferred_gateway_id);
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])->assertSet('selectedMethod', 'gateway-' . $stripe->id);
    }

    /** The transfer chosen with a saved card on the account (ADV-539-1 again): «Pay» shows the transfer, charges nothing. */
    public function test_bank_transfer_deposit_with_a_saved_card_is_never_charged_to_the_card(): void
    {
        $stripe = $this->gateway('Stripe');
        $bank = $this->gateway('BankTransfer');
        BillingAgreement::query()->create(['ulid' => (string) Str::ulid(), 'client_id' => $this->user->client()->id, 'gateway_id' => $stripe->id,
            'name' => 'Visa **** 4242', 'type' => 'visa', 'external_reference' => 'pm_' . Str::random(8)]);

        $invoice = $this->deposit($bank);

        $this->livewireAs($this->user)->withQueryParams(['pay' => '1'])->test(Show::class, ['invoice' => $invoice])
            ->assertSet('selectedMethod', 'gateway-' . $bank->id)
            ->call('processPayment');
        $this->assertSame(0, ChargeAttempt::query()->count(), 'no charge of the saved card');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    /** A second press of «Add Funds» (or another tab) makes no second deposit invoice. */
    public function test_a_double_submit_makes_one_deposit_invoice(): void
    {
        $bank = $this->gateway('BankTransfer');
        $page = $this->livewireAs($this->user)->test(Credits::class)->set('currency', 'EUR')->set('amount', 20)->set('gateway', $bank->id);
        $page->call('addCredit');
        try {
            $this->livewireAs($this->user)->test(Credits::class)->set('currency', 'EUR')->set('amount', 20)->set('gateway', $bank->id)->call('addCredit');
        } catch (\Throwable) {
            // refused: an unpaid deposit invoice is open
        }

        $this->assertSame(1, Invoice::query()->where('client_id', $this->user->client()->id)->count());
    }

    /** Past the maximum credit: no invoice and no redirect to one. */
    public function test_over_the_maximum_credit_makes_no_invoice_and_goes_nowhere(): void
    {
        $bank = $this->gateway('BankTransfer');
        $this->user->client()->credits()->create(['currency_code' => 'EUR', 'amount' => 990]);

        $this->livewireAs($this->user)->test(Credits::class)->set('currency', 'EUR')->set('amount', 20)->set('gateway', $bank->id)
            ->call('addCredit')->assertNoRedirect();

        $this->assertSame(0, Invoice::query()->where('client_id', $this->user->client()->id)->count());
    }

    /** The gateway chosen is disabled before the customer presses «Pay»: nothing is picked and nothing is opened. */
    public function test_a_gateway_disabled_after_the_deposit_is_not_picked_nor_opened(): void
    {
        Http::fake();
        $stripe = $this->gateway('Stripe');
        $invoice = $this->deposit($stripe);
        $stripe->forceFill(['enabled' => false])->save();

        $this->livewireAs($this->user)->withQueryParams(['pay' => '1'])->test(Show::class, ['invoice' => $invoice])
            ->assertSet('selectedMethod', null)
            ->set('selectedMethod', 'gateway-' . $stripe->id)->call('processPayment');

        Http::assertNothingSent();
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function views(): iterable
    {
        yield 'pay' => ['pay', []];
        yield 'billing agreement' => ['billing-agreement', ['type' => 'setup']];
    }

    /**
     * A name that closes the script or carries line terminators, an email with quotes: still one JavaScript string each,
     * nothing of them read as HTML or code.
     *
     * @param  array<string, mixed>  $extra
     */
    #[DataProvider('views')]
    public function test_a_hostile_name_and_email_stay_javascript_strings(string $view, array $extra): void
    {
        $user = User::factory()->create(['first_name' => "</script><script>alert(1)</script>\u{2028}x", 'last_name' => "a\nb'c\"d\\",
            'email' => "o'neil\"x@example.test"]);
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => Invoice::STATUS_UNPAID]);
        $page = $this->livewireAs($user)->test(Adv896StripeHost::class, ['view' => $view, 'extra' => $extra, 'invoiceId' => $invoice->id]);
        $js = implode("\n", array_map('strval', (array) ($page->effects['scripts'] ?? [])));

        $this->assertStringContainsString('js.stripe.com', $js);
        $this->assertStringNotContainsString('</script><script>', $js);
        $this->assertStringNotContainsString("\u{2028}", $js);
        $this->assertSame(1, preg_match("/name: '((?:[^'\\\\\\n]|\\\\.)*)',/", $js, $name), 'the name is one single-quoted string on one line');
        $this->assertSame($user->name, json_decode('"' . str_replace("\\'", "'", $name[1]) . '"'));
        $this->assertSame(1, preg_match("/email: '((?:[^'\\\\\\n]|\\\\.)*)',/", $js, $email));
        $this->assertSame($user->email, json_decode('"' . str_replace("\\'", "'", $email[1]) . '"'));
    }
}

/** A Stripe window rendered as its gateway's pay() view is: inside a Livewire component (its @script). */
class Adv896StripeHost extends Component
{
    public string $view = 'pay';

    /** @var array<string, mixed> */
    public array $extra = [];

    public int $invoiceId = 0;

    public function render(): View
    {
        return view()->file(base_path('extensions/Gateways/Stripe/resources/views/' . $this->view . '.blade.php'), [
            'intent' => (object) ['client_secret' => 'pi_test_secret'], 'stripePublishableKey' => 'pk_test',
            'invoice' => Invoice::query()->findOrFail($this->invoiceId),
        ] + $this->extra);
    }
}
