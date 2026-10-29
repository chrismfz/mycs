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

    public function test_an_invoice_with_a_line_paid_another_way_is_not_charged(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-10-25']);
        foreach ([$this->service(), $this->service($this->bank)] as $service) {
            $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);
        }
        $this->assertNull(AutoCharge::agreementFor($invoice->fresh()), 'staff merged it, say: one line is paid by bank transfer, none of it is charged');

        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'payment_gateway_id' => $this->paypal->id]);
        $other = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-10-25']);
        $other->items()->create(['description' => 'Domain', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Domain::class, 'reference_id' => $domain->id]);
        $this->assertNull(AutoCharge::agreementFor($other->fresh()), 'a domain\'s too');
        $this->assertNull(ItemPaymentMethod::chargedBy($domain));
        $this->assertSame($this->card->id, ItemPaymentMethod::chargedBy($this->service($this->stripe))?->id);
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
    }
}
