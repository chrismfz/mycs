<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Livewire\Client\PaymentMethods;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «How each service is paid» on «Payment methods» (docs/pay-flow-ux.md, PR 6b): each service and domain with a payment
 * method to choose, its «Paid with» the same change as on its own page (ItemPaymentMethod::set, ItemPaymentAccess), and
 * what the cron does with it.
 */
class PaymentMethodsWaysTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Gateway $bank;

    private Gateway $stripe;

    private Gateway $paypal;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $this->bank = Gateway::query()->create(['name' => 'Bank Transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $this->stripe = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $this->paypal = Gateway::query()->create(['name' => 'PayPal', 'extension' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        $card = BillingAgreement::query()->create(['ulid' => (string) Str::ulid(), 'client_id' => $this->client->id, 'gateway_id' => $this->stripe->id,
            'name' => 'Visa **** 4242', 'type' => 'visa', 'external_reference' => 'pm_' . Str::random(8)]);
        $this->client->forceFill(['default_billing_agreement_id' => $card->id, 'auto_charge' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $with = [], ?Client $client = null): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1,
            'type' => $with['plan_type'] ?? 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'setup_fee' => 0, 'currency_code' => 'USD']);
        unset($with['plan_type']);

        return Service::factory()->create(array_merge(['client_id' => ($client ?? $this->client)->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-10-25', 'label' => 'Hosting ' . Str::random(4)], $with));
    }

    private function domain(array $with = []): Domain
    {
        return Domain::factory()->create(array_merge(['client_id' => $this->client->id, 'currency_code' => 'USD'], $with));
    }

    public function test_the_table_lists_what_has_a_payment_method_to_choose(): void
    {
        $service = $this->service();
        $suspended = $this->service(['status' => Service::STATUS_SUSPENDED]);
        $cancelled = $this->service(['status' => Service::STATUS_CANCELLED]);
        $once = $this->service(['plan_type' => 'one-time']);
        $subscribed = $this->service(['subscription_id' => 'sub_1']);
        $domain = $this->domain();
        $theirs = $this->service([], Client::factory()->ownedBy()->create());

        $page = $this->livewireAs($this->user)->test(PaymentMethods::class)
            ->assertSee(__('account.ways.title'))
            ->assertSeeInOrder([$service->label, $suspended->label, $domain->fqdn])
            ->assertDontSee($cancelled->label)->assertDontSee($once->label)->assertDontSee($subscribed->label)->assertDontSee($theirs->label);

        $this->assertSame(['s' . $service->id => '', 's' . $suspended->id => '', 'd' . $domain->id => ''], $page->get('ways'));
    }

    public function test_paid_with_is_the_same_change_as_on_the_services_page(): void
    {
        $service = $this->service(['payment_gateway_id' => $this->paypal->id]);
        $page = $this->livewireAs($this->user)->test(PaymentMethods::class);
        $this->assertSame((string) $this->paypal->id, $page->get('ways')['s' . $service->id]);

        $page->set('ways.s' . $service->id, (string) $this->bank->id);
        $this->assertSame($this->bank->id, $service->fresh()->payment_gateway_id);
        $page->set('ways.s' . $service->id, '');
        $this->assertNull($service->fresh()->payment_gateway_id, 'the account\'s again');

        // One its invoice would not offer (no USD): refused, unchanged, and the row says what it still is.
        $this->bank->settings()->create(['key' => 'currencies', 'value' => 'EUR']);
        $page->set('ways.s' . $service->id, (string) $this->bank->id);
        $this->assertNull($service->fresh()->payment_gateway_id);
        $this->assertSame('', $page->get('ways')['s' . $service->id]);
    }

    public function test_a_domains_paid_with_changes_as_on_its_page(): void
    {
        $domain = $this->domain();
        $page = $this->livewireAs($this->user)->test(PaymentMethods::class);

        $page->set('ways.d' . $domain->id, (string) $this->paypal->id);
        $this->assertSame($this->paypal->id, $domain->fresh()->payment_gateway_id);
        $page->set('ways.d' . $domain->id, '0');
        $this->assertNull($domain->fresh()->payment_gateway_id);
    }

    public function test_crafted_rows_and_values_change_nothing(): void
    {
        $service = $this->service();
        $theirs = $this->service([], Client::factory()->ownedBy()->create());
        $cancelled = $this->service(['status' => Service::STATUS_CANCELLED]);
        $expired = $this->domain(['status' => DomainStatus::Cancelled]);
        $page = $this->livewireAs($this->user)->test(PaymentMethods::class);

        foreach (['s' . $theirs->id, 's' . $cancelled->id, 'd' . $expired->id, 'x' . $service->id, 's0', 's' . $service->id . 'x', 'd999999'] as $key) {
            $page->set('ways.' . $key, (string) $this->bank->id);
        }
        foreach (['-1', '1.5', 'abc', ' ' . $this->bank->id] as $value) {
            $page->set('ways.s' . $service->id, $value);
        }

        $this->assertNull($theirs->fresh()->payment_gateway_id, 'another account\'s');
        $this->assertNull($cancelled->fresh()->payment_gateway_id, 'one with nothing to choose');
        $this->assertNull($expired->fresh()->payment_gateway_id);
        $this->assertNull($service->fresh()->payment_gateway_id);
        $this->assertSame('', $page->get('ways')['s' . $service->id]);
    }

    public function test_a_login_that_does_not_pay_the_accounts_invoices_sees_no_table(): void
    {
        $this->service();
        $login = User::factory()->create();
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $login->id, 'permissions' => ['manageproducts'], 'granted_at' => now()]);

        $this->actingAs($login)->withSession($this->loginUser($login, [CurrentClient::SESSION_KEY => $this->client->id]))->get(route('account.payment-methods'))->assertForbidden();
    }

    public function test_a_login_that_does_not_manage_the_service_sees_its_way_but_cannot_change_it(): void
    {
        $service = $this->service(['payment_gateway_id' => $this->paypal->id]);
        $login = User::factory()->create();
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $login->id, 'permissions' => ['invoices'], 'granted_at' => now()]);
        $this->actingAs($login);
        app(CurrentClient::class)->switchTo($this->client->id);

        $page = $this->livewireAs($login)->test(PaymentMethods::class)
            ->assertSee($service->label)->assertDontSee('ways.s' . $service->id, false);
        $page->set('ways.s' . $service->id, (string) $this->bank->id)->assertForbidden();
        $this->assertSame($this->paypal->id, $service->fresh()->payment_gateway_id);
    }

    private function impersonate(array $permissions): void
    {
        session(['impersonating' => $this->user->id, CurrentClient::SESSION_KEY => $this->client->id, CurrentClient::IMPERSONATING_KEY => $this->client->id]);
        Auth::guard('staff')->setUser(Staff::factory()->withPermissions(array_merge(['admin.users.impersonate', 'admin.services.update', 'admin.domains.update'], $permissions))->create());
        Auth::guard('web')->setUser($this->user);
        app(CurrentClient::class)->forget();
    }

    public function test_staff_without_the_money_permission_change_no_way(): void
    {
        $service = $this->service();
        $domain = $this->domain();
        $this->impersonate([]);

        $page = Livewire::test(PaymentMethods::class)
            ->set('ways.s' . $service->id, (string) $this->bank->id)
            ->set('ways.d' . $domain->id, (string) $this->bank->id);

        $this->assertNull($service->fresh()->payment_gateway_id, 'SA-5');
        $this->assertNull($domain->fresh()->payment_gateway_id, 'SA-5');
        $this->assertSame('', $page->get('ways')['s' . $service->id]);
    }

    /** The control of the one above: with the money permission (`pay`: admin.invoices.update) the same staff change both. */
    public function test_staff_with_the_money_permission_change_the_ways(): void
    {
        $service = $this->service();
        $domain = $this->domain();
        $this->impersonate(['admin.invoices.update']);

        Livewire::test(PaymentMethods::class)
            ->set('ways.s' . $service->id, (string) $this->bank->id)
            ->set('ways.d' . $domain->id, (string) $this->bank->id);

        $this->assertSame($this->bank->id, $service->fresh()->payment_gateway_id);
        $this->assertSame($this->bank->id, $domain->fresh()->payment_gateway_id);
    }

    public function test_a_page_opened_for_one_account_changes_no_way_of_another(): void
    {
        $service = $this->service();
        $b = Client::factory()->ownedBy()->create();
        ClientUser::query()->create(['client_id' => $b->id, 'user_id' => $this->user->id, 'granted_at' => now()]);
        $theirs = $this->service([], $b);
        $this->actingAs($this->user);
        $page = $this->livewireAs($this->user)->test(PaymentMethods::class);

        $this->assertTrue(app(CurrentClient::class)->switchTo($b->id)); // another tab switched account
        $page->set('ways.s' . $service->id, (string) $this->bank->id);
        $page->set('ways.s' . $theirs->id, (string) $this->bank->id);

        $this->assertNull($service->fresh()->payment_gateway_id);
        $this->assertNull($theirs->fresh()->payment_gateway_id);
    }

    public function test_the_automatic_column_says_what_the_cron_does(): void
    {
        $service = $this->service();
        $manual = $this->service(['payment_gateway_id' => $this->bank->id]);

        $this->livewireAs($this->user)->test(PaymentMethods::class)
            ->assertSeeInOrder([$service->label, __('services.auto_charge_status.charged', ['method' => 'Visa **** 4242']), $manual->label]);
    }

    public function test_the_table_is_a_page_at_a_time_services_then_domains(): void
    {
        config(['settings.pagination' => 2]);
        $this->storeSettings(['pagination' => 2]);
        $a = $this->service();
        $b = $this->service();
        $domain = $this->domain();

        $this->livewireAs($this->user)->test(PaymentMethods::class)
            ->assertSee($a->label)->assertSee($b->label)->assertDontSee($domain->fqdn)
            ->call('gotoPage', 2, 'items')
            ->assertDontSee($a->label)->assertSee($domain->fqdn);
    }

    public function test_greek_words(): void
    {
        app()->setLocale('el');
        $this->service();

        $this->livewireAs($this->user)->test(PaymentMethods::class)
            ->assertSee('Πώς πληρώνεται κάθε υπηρεσία')->assertSee('Πληρώνεται με');
    }
}
