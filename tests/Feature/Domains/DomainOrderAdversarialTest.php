<?php

namespace Tests\Feature\Domains;

use App\Classes\Cart as CartClass;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Jobs\Domains\RenewDomainJob;
use App\Livewire\Cart;
use App\Livewire\DomainShop\Search;
use App\Models\BillingAgreement;
use App\Models\CartItem;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Domains\DomainSearch;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Domains\Ordering\DomainOffer;
use App\Services\Domains\Ordering\DomainOrderLine;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\Payment\AutoCharge;
use App\Support\CurrentClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Adversarial review of PR #179 (D8b-1, ordering domains). Failing tests prove a problem; passing ones document an
 * attack that is defended. Never a real network call (Http::fake, LaravelTransport::$resolve).
 */
class DomainOrderAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $com;

    private DomainRegistrarConnection $op;

    /** Names Openprovider says are taken, and those of them in OUR reseller account (a record by name). */
    private array $taken = ['nixpal.com', 'parked.com'];

    private array $inOurAccount = [];

    private array $premium = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        Cache::flush();
        Http::preventStrayRequests();
        LaravelTransport::$resolve = fn (string $host) => ['93.184.216.34'];
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        config(['settings.default_currency' => 'EUR', 'settings.cronjob_order_cancel' => 7, 'settings.domain_billing_enabled' => true,
            'settings.credits_auto_use' => false]);
        session(['currency' => 'EUR']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->com = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $this->op->id, 'epp_code_required' => true]);
        foreach ([['register', 1, '23.33'], ['register', 2, '46.66'], ['renew', 1, '23.33'], ['transfer', 1, '20.00'],
            ['register', 1, '30.00', 'USD'], ['transfer', 1, '25.00', 'USD']] as $row) {
            DomainTldPrice::query()->create(['domain_tld_id' => $this->com->id, 'operation' => $row[0], 'years' => $row[1], 'currency_code' => $row[3] ?? 'EUR', 'price' => $row[2]]);
        }
        $this->user = User::factory()->create();
        $this->complete($this->user->client()->ensurePrimaryContact());
        $test = $this;
        Http::fake(function (Request $request) use ($test) {
            $url = $request->url();
            if (str_contains($url, '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if (str_contains($url, '/domains/check')) {
                return Http::response(['data' => ['results' => array_map(function ($d) use ($test) {
                    $fqdn = $d['name'] . '.' . $d['extension'];

                    return ['domain' => $fqdn, 'status' => in_array($fqdn, $test->taken, true) ? 'active' : 'free']
                        + (in_array($fqdn, $test->premium, true) ? ['premium' => ['price' => ['create' => 5000]]] : []);
                }, $request->data()['domains'])]]);
            }
            if (str_contains($url, '/domains?full_name=')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $fqdn = (string) ($q['full_name'] ?? '');
                [$name, $ext] = explode('.', $fqdn, 2);

                return Http::response(['data' => ['results' => in_array($fqdn, $test->inOurAccount, true) ? [[
                    'domain' => ['name' => $name, 'extension' => $ext], 'id' => 777001, 'status' => 'ACT',
                    'expiration_date' => '2027-06-01 00:00:00', 'name_servers' => [['name' => 'ns1.example.net']], 'is_locked' => true,
                ]] : []]]);
            }

            return Http::response([], 404);
        });
    }

    protected function tearDown(): void
    {
        LaravelTransport::$resolve = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function complete(Contact $contact): Contact
    {
        $contact->update(['first_name' => 'Maria', 'last_name' => 'P', 'email' => 'maria' . $contact->id . '@example.test',
            'phone' => '+30.2100000000', 'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10000', 'country' => 'GR']);

        return $contact;
    }

    private function cartModel(): \App\Models\Cart
    {
        return \App\Models\Cart::query()->latest('id')->firstOrFail();
    }

    private function cart(?User $as = null): Testable
    {
        $as ??= $this->user;
        session([CurrentClient::SESSION_KEY => $as->client()->id]);

        return Livewire::withCookie('cart', (string) $this->cartModel()->ulid)->actingAs($as)->test(Cart::class);
    }

    private function checkout(array $set = [], ?User $as = null): Testable
    {
        $cart = $this->cart($as);
        foreach ($set as $key => $value) {
            $cart->set($key, $value);
        }

        return $cart->call('checkout');
    }

    /** A product priced 10.00 EUR / month, its plan. */
    private function product(): array
    {
        $product = Product::factory()->create(['name' => 'Hosting', 'stock' => null, 'allow_quantity' => 'disabled']);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'setup_fee' => 0, 'currency_code' => 'EUR']);

        return [$product->fresh(), $plan->fresh()];
    }

    private function addProductToCart(): void
    {
        [$product, $plan] = $this->product();
        $this->cartModel()->items()->create(['product_id' => $product->id, 'plan_id' => $plan->id, 'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);
    }

    // ---------------------------------------------------------------- findings (failing)

    /**
     * ADV-1: a product and a domain in one order, never paid. The cron cancels the product's service first, and with it
     * the WHOLE invoice (cancelOrderInvoices: no other running service). The domain step then finds no open invoice and
     * leaves the domain pending_register for ever: the name stays taken here (live_fqdn), the order never ends.
     */
    public function test_adv1_a_mixed_unpaid_order_cancels_its_domain_too(): void
    {
        Livewire::test(Search::class)->call('add', 'mybusiness.com')->assertRedirect(route('cart'));
        $this->addProductToCart();
        $this->checkout()->assertRedirect();
        $this->assertSame(2, Invoice::query()->sole()->items()->count(), 'precondition: one invoice, product and domain');

        Carbon::setTestNow('2026-10-28 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_CANCELLED, Service::query()->sole()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, Invoice::query()->sole()->status);
        $this->assertSame(DomainStatus::Cancelled, Domain::query()->sole()->status, 'the domain of the cancelled order is still pending');
        $this->assertSame(Order::STATUS_CANCELLED, Order::query()->sole()->status);
    }

    /**
     * ADV-2: an UNPAID pending transfer becomes Active through the sync. A name taken and held in our reseller account
     * with no live record here (not adopted yet, a record staff cancelled): the customer orders its transfer with any
     * EPP code, never pays, and the 4-hourly domains:sync reads the registrar's ACT record by name and moves the pending
     * order to Active, with its expiry, owned by that customer (who can then manage it, read its EPP code, change NS).
     */
    public function test_adv2_an_unpaid_pending_transfer_is_never_activated_by_the_sync(): void
    {
        $this->inOurAccount = ['parked.com'];
        Livewire::test(Search::class)->call('transfer', 'parked.com')->assertRedirect(route('cart'));
        $item = $this->cartModel()->items()->sole();
        $this->checkout(['epp.' . $item->id => 'anything'])->assertRedirect();
        $domain = Domain::query()->where('fqdn', 'parked.com')->sole();
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->sole()->status);

        $this->artisan('domains:sync');

        $domain->refresh();
        $this->assertSame(DomainStatus::PendingTransfer, $domain->status, 'an order nobody paid is now an active domain of the customer');
        $this->assertNull($domain->expires_at);
    }

    /**
     * ADV-3: the order's invoice of a domain is charged on the saved card by the cron (AutoCharge «a renewal: of a
     * service, or of a domain»): a pending domain is auto_renew and not terminal, so the first invoice of an order the
     * customer left unpaid is charged on its due date. A product order is not (its pending service excludes it).
     */
    public function test_adv3_an_unpaid_domain_order_is_not_charged_on_the_saved_card(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AdvPay'], ['name' => 'AdvPay', 'type' => 'gateway']);
        BillingAgreement::query()->create(['client_id' => $this->user->client()->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_x', 'type' => 'card']);
        Client::query()->whereKey($this->user->client()->id)->update(['auto_charge' => true]);
        $this->assertNotNull($this->user->client()->fresh()->default_billing_agreement_id, 'precondition: a saved method');

        // A product order: not charged.
        [$product, $plan] = $this->product();
        $productInvoice = (new PlaceOrderService)->place($this->user->client()->fresh(), 'EUR', [new OrderLine($product, $plan)])->invoice;
        // A domain order: ...
        $contact = $this->user->client()->primaryContact;
        $domainInvoice = (new PlaceOrderService)->place($this->user->client()->fresh(), 'EUR', [],
            domains: [new DomainOrderLine('mybusiness.com', $this->com->fresh('prices'), 'register', 1, $contact)])->invoice;

        $due = (new ReflectionMethod(AutoCharge::class, 'due'))->invoke(new AutoCharge, CarbonImmutable::parse('2026-10-27'))->pluck('id')->all();

        $this->assertNotContains($productInvoice->id, $due, 'precondition: an order is not the cron\'s to charge');
        $this->assertNotContains($domainInvoice->id, $due, 'the unpaid domain order would be charged on the saved card');
    }

    /**
     * ADV-4: the product page with «?edit=» naming a domain cart item crashes (its product is null): a 500 any visitor
     * can cause from a URL.
     */
    public function test_adv4_editing_a_domain_item_as_a_product_is_not_a_500(): void
    {
        [$product] = $this->product();
        Livewire::test(Search::class)->call('add', 'mybusiness.com');
        $item = $this->cartModel()->items()->sole();

        $response = $this->withCookie('cart', (string) $this->cartModel()->ulid)
            ->get(route('products.checkout', [$product->category, $product, 'edit' => $item->id]));

        $this->assertNotSame(500, $response->getStatusCode(), (string) $response->exception?->getMessage());
    }

    /**
     * ADV-5: the EPP map set whole (wire:model on «epp», a crafted Livewire update) reaches updatedEpp($value, $key)
     * with an array and a null key: «Array to string conversion», a 500.
     */
    public function test_adv5_setting_the_whole_epp_map_is_not_a_500(): void
    {
        Livewire::test(Search::class)->call('transfer', 'nixpal.com');
        $item = $this->cartModel()->items()->sole();

        $this->cart()->set('epp', [$item->id => 'Code-1'])->assertOk();
        $this->checkout(['epp' => [$item->id => 'Code-1']])->assertRedirect();
        $this->assertSame('Code-1', Domain::query()->sole()->epp_code);
    }

    /**
     * ADV-6: a domain-only order's total is 0.00 (Order::total adds only services): the staff order list and the order
     * e-mail ({{ order.formattedTotal }}) show an order of 23.33 as free.
     */
    public function test_adv6_a_domain_orders_total_is_not_zero(): void
    {
        Livewire::test(Search::class)->call('add', 'mybusiness.com');
        $this->checkout()->assertRedirect();

        $this->assertGreaterThan(0, (float) Order::query()->sole()->total, 'the order shows as free');
    }

    // ---------------------------------------------------------------- defended attacks (passing)

    public function test_def_years_cannot_be_tampered_nor_another_carts_item_touched(): void
    {
        Livewire::test(Search::class)->call('add', 'mybusiness.com');
        $mine = $this->cartModel()->items()->sole();
        foreach ([0, -1, 3, 11] as $years) {
            $this->cart()->call('updateDomainYears', $mine->id, $years);
            $this->assertSame(1, $mine->fresh()->domain['years']);
        }
        // Another visitor's cart: its item is not reachable by id from mine.
        $other = \App\Models\Cart::query()->create(['currency_code' => 'EUR']);
        $theirs = $other->items()->create(['quantity' => 1, 'domain' => ['fqdn' => 'theirs.com', 'tld_id' => $this->com->id, 'operation' => 'transfer', 'years' => 1]]);
        $theirs->epp_code = 'Their-Secret';
        $theirs->save();
        $this->cart()->call('updateDomainYears', $theirs->id, 2)->set('epp.' . $theirs->id, 'overwritten');
        $this->assertSame([1, 'Their-Secret'], [$theirs->fresh()->domain['years'], $theirs->fresh()->epp_code]);
        $this->assertStringNotContainsString('Their-Secret', $this->cart()->html());
    }

    public function test_def_the_invoice_bills_the_catalog_price_now_and_matches_the_cart_total_with_tax_and_a_coupon(): void
    {
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        Livewire::test(Search::class)->call('add', 'mybusiness.com');
        $this->addProductToCart();
        $coupon = Coupon::query()->create(['code' => 'HALF', 'type' => 'percentage', 'value' => 50]);
        $this->cartModel()->update(['coupon_id' => $coupon->id]);

        $shown = $this->cart()->get('total');
        $this->checkout()->assertRedirect();
        $invoice = Invoice::query()->sole();
        $domainLine = $invoice->items()->where('reference_type', Domain::class)->sole();
        $this->assertSame('23.33', $domainLine->price, 'a coupon never discounts a domain');
        $this->assertEqualsWithDelta((float) $shown->total, (float) $invoice->total, 0.001, 'cart total = invoice total');
    }

    public function test_def_a_price_raised_between_cart_and_checkout_is_billed_at_the_new_price(): void
    {
        Livewire::test(Search::class)->call('add', 'mybusiness.com');
        DomainTldPrice::query()->where('domain_tld_id', $this->com->id)->where('operation', 'register')->where('years', 1)->where('currency_code', 'EUR')->update(['price' => '99.00']);
        $this->checkout()->assertRedirect();
        $this->assertSame('99.00', InvoiceItem::query()->where('reference_type', Domain::class)->sole()->price);

        // A term no longer priced at checkout: refused, nothing made.
        Livewire::test(Search::class)->call('add', 'second.com');
        $item = $this->cartModel()->items()->sole();
        $this->cart()->call('updateDomainYears', $item->id, 2);
        DomainTldPrice::query()->where('domain_tld_id', $this->com->id)->where('years', 2)->update(['price' => null]);
        $this->checkout()->assertNoRedirect();
        $this->assertSame(0, Domain::query()->where('fqdn', 'second.com')->count());
    }

    public function test_def_the_session_currency_switched_after_adding_bills_in_the_carts(): void
    {
        Livewire::test(Search::class)->call('add', 'mybusiness.com');
        session(['currency' => 'USD']);
        $this->checkout()->assertRedirect();
        $invoice = Invoice::query()->sole();
        $this->assertSame(['EUR', '23.33'], [$invoice->currency_code, $invoice->items()->sole()->price]);
    }

    public function test_def_premium_taken_and_unpriced_tlds_are_refused(): void
    {
        $this->premium = ['gold.com'];
        Livewire::test(Search::class)->call('add', 'gold.com')->assertNoRedirect();
        Livewire::test(Search::class)->call('add', 'nixpal.com')->assertNoRedirect();
        Livewire::test(Search::class)->call('add', 'free.org')->assertNoRedirect();
        $this->assertSame(0, CartItem::query()->count());
        $this->expectException(DisplayException::class);
        CartClass::addDomain('x.com', 'renew');
    }

    public function test_def_a_name_here_or_pending_for_another_account_is_not_ordered_again(): void
    {
        $other = User::factory()->create();
        $this->complete($other->client()->ensurePrimaryContact());
        Livewire::test(Search::class)->call('add', 'mybusiness.com');
        Livewire::test(Search::class)->call('add', 'MyBusiness.COM.');
        $this->assertSame(1, $this->cartModel()->items()->count(), 'one item per name');
        $firstCart = $this->cartModel()->ulid;

        // A second account's cart with the same name, checked out first.
        $second = \App\Models\Cart::query()->create(['currency_code' => 'EUR']);
        $second->items()->create(['quantity' => 1, 'domain' => ['fqdn' => 'mybusiness.com', 'tld_id' => $this->com->id, 'operation' => 'register', 'years' => 1]]);
        $this->checkout([], $other)->assertRedirect();

        session([CurrentClient::SESSION_KEY => $this->user->client()->id]);
        Livewire::withCookie('cart', $firstCart)->actingAs($this->user)->test(Cart::class)->call('checkout')->assertNoRedirect();
        $this->assertSame(1, Domain::query()->count());
        // And a transfer of it (now pending for the other account) is refused.
        Livewire::test(Search::class)->call('transfer', 'mybusiness.com')->assertNoRedirect();
    }

    public function test_def_a_race_past_the_check_is_a_message_not_a_500(): void
    {
        Livewire::test(Search::class)->call('add', 'mybusiness.com');
        $otherClient = User::factory()->create()->client();
        // Another order commits the same name between the check and the insert.
        $this->app->bind(DomainOffer::class, fn ($app) => new class($app->make(DomainSearch::class), $otherClient->id) extends DomainOffer
        {
            public function __construct(DomainSearch $search, private int $other)
            {
                parent::__construct($search);
            }

            public function check(string $fqdn, string $operation, int $years, string $currency, bool $availability = true): array
            {
                $result = parent::check($fqdn, $operation, $years, $currency, $availability);
                DB::table('domains')->insert(['client_id' => $this->other, 'sld' => 'mybusiness', 'tld' => 'com', 'fqdn' => 'mybusiness.com',
                    'status' => 'pending_register', 'created_at' => now(), 'updated_at' => now()]);

                return $result;
            }
        });

        $this->checkout()->assertNoRedirect();
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_def_the_registrant_is_the_accounts_own_and_complete(): void
    {
        $foreign = $this->complete(Contact::factory()->create(['client_id' => User::factory()->create()->client()->id]));
        $this->expectException(DisplayException::class);
        (new PlaceOrderService)->place($this->user->client(), 'EUR', [],
            domains: [new DomainOrderLine('mybusiness.com', $this->com->fresh('prices'), 'register', 1, $foreign)]);
    }

    public function test_def_the_epp_code_never_leaves_in_json_lines_or_audits(): void
    {
        Livewire::test(Search::class)->call('transfer', 'nixpal.com');
        $item = $this->cartModel()->items()->sole();
        $this->cart()->set('epp.' . $item->id, 'Leak-Me-42');
        $this->assertStringNotContainsString('Leak-Me-42', (string) DB::table('cart_items')->value('epp_code'));
        $this->assertStringNotContainsString('Leak-Me-42', $item->fresh()->toJson());
        $this->checkout()->assertRedirect();

        $domain = Domain::query()->sole();
        $order = Order::query()->sole();
        $haystack = $domain->toJson() . $order->load('domains')->toJson() . Invoice::query()->sole()->load('items')->toJson()
            . json_encode(DB::table('audits')->get()) . json_encode(DB::table('invoice_items')->get());
        $this->assertStringNotContainsString('Leak-Me-42', $haystack);
        $this->assertSame('Leak-Me-42', $domain->epp_code);
    }

    public function test_def_paid_mixed_invoice_starts_the_service_and_never_renews_the_domain(): void
    {
        Livewire::test(Search::class)->call('add', 'mybusiness.com');
        $this->addProductToCart();
        $this->checkout()->assertRedirect();
        $invoice = Invoice::query()->sole();
        $this->assertSame('33.33', (string) $invoice->total);

        (new RecordPaymentService)->handle($invoice, null, (string) $invoice->total, transactionId: 'tx-adv');

        Queue::assertNotPushed(RenewDomainJob::class);
        $domain = Domain::query()->sole();
        $this->assertSame([DomainStatus::PendingRegister, null], [$domain->status, $domain->expires_at]);
    }

    public function test_def_an_unpaid_domain_only_order_with_two_names_is_cancelled_whole(): void
    {
        Livewire::test(Search::class)->call('add', 'one.com');
        $this->cartModel()->items()->create(['quantity' => 1, 'domain' => ['fqdn' => 'two.com', 'tld_id' => $this->com->id, 'operation' => 'register', 'years' => 1]]);
        $this->checkout()->assertRedirect();
        $this->assertSame(2, Invoice::query()->sole()->items()->count());

        Carbon::setTestNow('2026-10-28 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame([DomainStatus::Cancelled, DomainStatus::Cancelled], Domain::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame(Invoice::STATUS_CANCELLED, Invoice::query()->sole()->status);
        $this->assertSame(Order::STATUS_CANCELLED, Order::query()->sole()->status);
    }
}
