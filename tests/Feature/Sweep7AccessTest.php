<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Livewire\Domains\Show as DomainShow;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\BillingAgreement;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Gateway;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Payment\ItemPaymentMethod;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep 7, «access» (adversarial verification). S7-ACCESS-1: a page reached by id (a domain or service of account B)
 * asks the session's current account for the permission, not the record's account. A login that manages B with a
 * narrow grant and owns its own account A (the session's default) is shown what B's owner did not give it:
 * B's contacts' emails (`contacts`) and B's saved payment method (`invoices`).
 *
 * The tests named test_s7_* fail as written (each the finding); test_guard_* pass and show the same page is right once
 * the session is on B, i.e. the only difference is which account the session is on.
 */
class Sweep7AccessTest extends TestCase
{
    use RefreshDatabase;

    /** The co-managing login (an agency): owns its own account A, has a narrow grant on the customer's B. */
    private User $agency;

    /** The customer who owns account B. */
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['code' => 0, 'data' => ['token' => 'tok', 'id' => 30391502, 'domain' => ['name' => 'sweep7', 'extension' => 'com'],
            'status' => 'ACT', 'expiration_date' => '2027-11-17 00:00:00', 'name_servers' => [['name' => 'ns1.example.test'], ['name' => 'ns2.example.test']],
            'owner_handle' => 'OWN1-GR']]));
        $this->agency = User::factory()->create();
        $this->customer = User::factory()->create();
        $this->assertNotNull($this->agency->client(), 'precondition: the agency owns an account of its own (A)');
    }

    /** The agency's grant on B, with only these permissions. */
    private function grant(array $permissions): void
    {
        ClientUser::query()->create(['client_id' => $this->customer->client()->id, 'user_id' => $this->agency->id, 'granted_at' => now(),
            'permissions' => $permissions]);
        $this->assertFalse(ClientUser::liveGrant($this->agency, $this->customer->client()->id)->allows('contacts'));
        $this->assertFalse(ClientUser::liveGrant($this->agency, $this->customer->client()->id)->allows('invoices'));
    }

    /** A manageable domain of B, and a contact of B with a private address. */
    private function domainOfB(): Domain
    {
        $b = $this->customer->client()->id;
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Not-A-Real-Pa55'], ['password']);
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'com'], ['grace_days' => 40, 'redemption_days' => 30, 'is_active' => true]);
        $owner = Contact::factory()->create(['client_id' => $b, 'first_name' => 'Owner', 'country' => 'GR']);
        Contact::factory()->create(['client_id' => $b, 'first_name' => 'Tech', 'last_name' => 'Person', 'email' => 'private-tech@example.test', 'country' => 'GR']);

        return Domain::factory()->create(['client_id' => $b, 'sld' => 'sweep7', 'tld' => 'com', 'fqdn' => 'sweep7.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $op->id, 'registrar_domain_id' => '30391502', 'status' => DomainStatus::Active,
            'expires_at' => '2027-11-17', 'registrant_contact_id' => $owner->id, 'registrant_handle' => 'OWN1-GR',
            'meta' => ['registrar' => ['modify_owner_allowed' => false]]]);
    }

    /** An active recurring service of B, whose account charges a saved card automatically. */
    private function serviceOfB(): Service
    {
        if (!class_exists('MyCS\\Extensions\\Gateways\\GwSweep7Pay\\GwSweep7Pay')) {
            eval('namespace MyCS\\Extensions\\Gateways\\GwSweep7Pay; class GwSweep7Pay {
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) { return true; } }');
        }
        $client = $this->customer->client();
        $gateway = Gateway::query()->create(['name' => 'Cards', 'extension' => 'GwSweep7Pay', 'type' => 'gateway', 'enabled' => true]);
        BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa 4242',
            'external_reference' => 'pm_sweep7', 'type' => 'visa']);
        $client->forceFill(['auto_charge' => true])->save();
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);

        return Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => 10,
            'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addMonth()]);
    }

    /** Guard: with the session on B, the agency (no `contacts` on B) chooses by name and is not shown the address. */
    public function test_guard_no_contact_emails_while_the_session_is_on_the_domains_account(): void
    {
        $this->grant(['domains', 'managedomains']);
        $domain = $this->domainOfB();
        session([CurrentClient::SESSION_KEY => $this->customer->client()->id]);

        $this->livewireAs($this->agency)->test(DomainShow::class, ['domain' => $domain])
            ->set('editContacts', true)->assertSee('Tech Person')->assertDontSee('private-tech@example.test');
    }

    /**
     * S7-ACCESS-1 (a): the same page while the session is on the agency's own account A (where it lands after login,
     * being the one it owns): Domains\Show::seesContacts() asks CurrentClient::allows('contacts'), i.e. A's grant, and
     * B's contacts are listed with their emails.
     */
    public function test_s7_access_1_contact_emails_of_another_account_follow_the_sessions_account(): void
    {
        $this->grant(['domains', 'managedomains']);
        $domain = $this->domainOfB();
        $this->actingAs($this->agency);
        $this->assertSame($this->agency->client()->id, CurrentClient::id(), 'precondition: the session is on the account the login owns (A)');

        $this->livewireAs($this->agency)->test(DomainShow::class, ['domain' => $domain])
            ->set('editContacts', true)->assertSee('Tech Person')
            ->assertDontSee('private-tech@example.test');
    }

    /** Guard: with the session on B, the agency (`products` and `manageproducts` on B, no `invoices`) is not told how B's invoices are paid. */
    public function test_guard_no_auto_pay_line_while_the_session_is_on_the_services_account(): void
    {
        $this->grant(['products', 'manageproducts']);
        $service = $this->serviceOfB();
        session([CurrentClient::SESSION_KEY => $this->customer->client()->id]);

        $this->livewireAs($this->agency)->test(ServiceShow::class, ['service' => $service])
            ->assertDontSee('Visa 4242');
    }

    /**
     * S7-ACCESS-1 (b): services/partials/billing-agreement.blade.php gates the «auto pay» line on
     * CurrentClient::allows('invoices'): with the session on A (owned), B's saved payment method is named to a login
     * that has `products` + `manageproducts` on B but not `invoices` (the partial sits inside @can('update'), so
     * `products` alone does not reach it).
     */
    public function test_s7_access_1_saved_payment_method_of_another_account_follows_the_sessions_account(): void
    {
        $this->grant(['products', 'manageproducts']);
        $service = $this->serviceOfB();
        $this->assertSame(__('services.auto_charge_status.charged', ['method' => 'Visa 4242']),
            ItemPaymentMethod::autoChargeStatus($service), 'precondition: the status names the saved method');
        $this->actingAs($this->agency);
        $this->assertSame($this->agency->client()->id, CurrentClient::id(), 'precondition: the session is on the account the login owns (A)');

        $this->livewireAs($this->agency)->test(ServiceShow::class, ['service' => $service])
            ->assertDontSee('Visa 4242');
    }
}
