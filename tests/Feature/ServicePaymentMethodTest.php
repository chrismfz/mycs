<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Livewire\Domains\Show as DomainShow;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainRenewalService;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\ItemPaymentMethod;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Each service's and domain's own payment method (owner 2026-10-29, as WHMCS's paymentmethod): its renewals go on an
 * invoice apart with it preferred, the account's saved method never charges them, and the customer (on its page) and
 * staff change it. None: the account's, as before.
 */
class ServicePaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Gateway $bank;

    private Gateway $stripe;

    private Gateway $paypal;

    private BillingAgreement $card;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        config(['settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $this->bank = Gateway::query()->create(['name' => 'Bank Transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $this->stripe = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $this->paypal = Gateway::query()->create(['name' => 'PayPal', 'extension' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        $this->card = BillingAgreement::query()->create(['ulid' => (string) Str::ulid(), 'client_id' => $this->client->id, 'gateway_id' => $this->stripe->id,
            'name' => 'Visa **** 4242', 'type' => 'visa', 'external_reference' => 'pm_' . Str::random(8)]);
        $this->client->forceFill(['default_billing_agreement_id' => $this->card->id, 'auto_charge' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(?Gateway $gateway = null): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-10-25', 'payment_gateway_id' => $gateway?->id]);
    }

    /** @return list<int> the open invoice each service's renewal went on */
    private function bill(Service ...$services): array
    {
        $billing = new RenewalBilling;
        foreach ($services as $service) {
            $this->assertSame(1, DB::transaction(fn () => $billing->billRenewal($service->id, now()->addDays(7))), "service #{$service->id} billed");
        }
        $billing->finishRenewalInvoices();

        return array_map(fn (Service $service) => (int) $service->invoices()->where('status', Invoice::STATUS_UNPAID)->value('invoices.id'), $services);
    }

    public function test_a_renewal_of_another_payment_method_goes_on_an_invoice_apart_never_charged_by_the_card(): void
    {
        $account = $this->service();
        $byCard = $this->service($this->stripe); // the saved method's own: with the account's ones
        $byPayPal = $this->service($this->paypal);

        [$a, $b, $c] = $this->bill($account, $byCard, $byPayPal);

        $this->assertSame($a, $b, 'the account\'s and the card\'s own: one invoice');
        $this->assertNotSame($a, $c, 'PayPal\'s: an invoice apart, as WHMCS bills each method on its own');
        $this->assertSame($this->stripe->id, Invoice::query()->find($a)->preferred_gateway_id);
        $this->assertSame($this->paypal->id, Invoice::query()->find($c)->preferred_gateway_id, 'its own method preferred: its page and email name it');
        $this->assertSame($this->card->id, AutoCharge::agreementFor(Invoice::query()->find($a))?->id);
        $this->assertNull(AutoCharge::agreementFor(Invoice::query()->find($c)), 'the card never charges what is paid by PayPal');
    }

    /**
     * Decided on the invoice as it was made (code review of #541): a change of an item counts from its next renewal, an
     * open invoice keeps its way. A gateway deleted since: the item is the account's again, billed with the rest.
     */
    public function test_an_invoice_keeps_the_way_it_was_made_with(): void
    {
        $byPayPal = $this->service($this->paypal);
        [$a, $c] = $this->bill($this->service(), $byPayPal);
        ItemPaymentMethod::set(Service::query()->find(Invoice::query()->find($a)->items()->value('reference_id')), $this->bank->id);
        $this->assertSame($this->card->id, AutoCharge::agreementFor(Invoice::query()->find($a))?->id, 'the open invoice keeps its way');
        ItemPaymentMethod::set($byPayPal, null);
        $this->assertNull(AutoCharge::agreementFor(Invoice::query()->find($c)), 'PayPal\'s stays PayPal\'s');

        $this->paypal->delete();
        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'payment_gateway_id' => $this->paypal->id]);
        $this->assertNull(ItemPaymentMethod::apart($domain), 'nor billed apart for a deleted gateway');
        $this->assertSame($this->card->id, ItemPaymentMethod::chargedBy($domain->fresh())?->id);
        $this->assertNull(ItemPaymentMethod::chargedBy($this->service($this->bank)));
    }

    public function test_the_customer_changes_it_on_the_service_page(): void
    {
        config(['audit.console' => true]);
        $service = $this->service($this->paypal);

        $page = $this->livewireAs($this->user)->test(ServiceShow::class, ['service' => $service])->assertSet('paymentGatewayId', $this->paypal->id);
        $page->set('paymentGatewayId', (string) $this->bank->id);
        $this->assertSame($this->bank->id, $service->fresh()->payment_gateway_id);
        $page->set('paymentGatewayId', '');
        $this->assertNull($service->fresh()->payment_gateway_id, 'the account\'s again');

        // One its invoice would not offer (no USD): refused, unchanged.
        $this->bank->settings()->create(['key' => 'currencies', 'value' => 'EUR']);
        $page->set('paymentGatewayId', (string) $this->bank->id);
        $this->assertNull($service->fresh()->payment_gateway_id);
        $this->assertTrue($service->audits()->where('event', 'updated')->exists(), 'audited');
    }

    public function test_a_login_that_does_not_pay_the_accounts_invoices_may_not_change_it(): void
    {
        $service = $this->service();
        $login = User::factory()->create();
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $login->id, 'permissions' => ['manageproducts'], 'granted_at' => now()]);

        $this->livewireAs($login)->test(ServiceShow::class, ['service' => $service])->set('paymentGatewayId', (string) $this->bank->id)->assertForbidden();
        $this->assertNull($service->fresh()->payment_gateway_id);
    }

    public function test_the_customer_changes_a_domains_on_its_page(): void
    {
        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'currency_code' => 'USD']);

        $this->livewireAs($this->user)->test(DomainShow::class, ['domain' => $domain])->call('setPaymentMethod', $this->paypal->id);
        $this->assertSame($this->paypal->id, $domain->fresh()->payment_gateway_id);
        $this->livewireAs($this->user)->test(DomainShow::class, ['domain' => $domain->fresh()])->call('setPaymentMethod', 0);
        $this->assertNull($domain->fresh()->payment_gateway_id);
    }

    /** From WHMCS: each service's and domain's paymentmethod, to a gateway here only (never installed); another: none. */
    public function test_the_import_brings_each_items_payment_method(): void
    {
        [$a, $b, $c] = [$this->service(), $this->service(), $this->service($this->stripe)];
        $domain = Domain::factory()->create(['client_id' => $this->client->id]);
        $whmcs = new \PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, paymentmethod TEXT)');
        $whmcs->exec('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, paymentmethod TEXT)');
        $whmcs->prepare("INSERT INTO tblhosting VALUES (?, 'banktransfer'), (?, 'paypal_ppcpv'), (?, 'eurobanklib')")->execute([$a->id, $b->id, $c->id]);
        $whmcs->prepare("INSERT INTO tbldomains VALUES (?, 'paypal')")->execute([$domain->id]);
        $command = app(ImportFromWhmcs::class);
        (new \ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        $gateways = Gateway::query()->count();

        (new \ReflectionMethod($command, 'importItemPaymentMethods'))->invoke($command);

        $this->assertSame([$this->bank->id, $this->paypal->id, $this->stripe->id], [$a->fresh()->payment_gateway_id, $b->fresh()->payment_gateway_id, $c->fresh()->payment_gateway_id],
            'a module with no gateway here leaves it as it was');
        $this->assertSame($this->paypal->id, $domain->fresh()->payment_gateway_id);
        $this->assertSame($gateways, Gateway::query()->count(), 'none installed');

        // myip's Eurobank vPOS (eurobanklib): Cardlink's, once it is here (a redirect, paid from the invoice: never charged).
        $cardlink = Gateway::query()->create(['name' => 'Cardlink', 'extension' => 'Cardlink', 'type' => 'gateway', 'enabled' => true]);
        $command = app(ImportFromWhmcs::class); // a run of its own: a run reads each module's gateway once
        (new \ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new \ReflectionMethod($command, 'importItemPaymentMethods'))->invoke($command);
        $this->assertSame($cardlink->id, $c->fresh()->payment_gateway_id);
        $this->assertNull(ItemPaymentMethod::chargedBy($c->fresh()), 'the Stripe card never charges it');
    }

    /** A domain renewed on an invoice of its own (staff's renewal): its way too, never the card's. */
    public function test_a_domains_own_renewal_invoice_is_its_way(): void
    {
        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'currency_code' => 'USD', 'years' => 1, 'recurring_amount' => '12.00',
            'expires_at' => '2026-11-05', 'payment_gateway_id' => $this->paypal->id]);

        $line = (new \ReflectionMethod(DomainRenewalService::class, 'billedOrBill'))->invoke(app(DomainRenewalService::class), $domain);

        $this->assertSame([$this->paypal->id, $this->paypal->id], [(int) $line->invoice->preferred_gateway_id, (int) $line->payment_gateway_id], 'its line records its way');
        $this->assertNull(AutoCharge::agreementFor($line->invoice));
    }
}
