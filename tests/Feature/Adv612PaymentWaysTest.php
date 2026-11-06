<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Livewire\Client\PaymentMethods;
use App\Livewire\Domains\Show as DomainShow;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Invoice;
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
use Throwable;

/**
 * Adversarial review of PR #612 (ux/methods-services-table): «How each service is paid» on «Payment methods»
 * (PaymentMethods::updatedWays, items(), ItemPaymentAccess). Tests named test_adv_612_N fail on the PR head and prove a
 * finding; those named test_pin_ pass and pin a guard the review tried and could not break.
 */
class Adv612PaymentWaysTest extends TestCase
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

    private function service(array $with = [], ?Client $client = null): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1,
            'type' => $with['plan_type'] ?? 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'setup_fee' => 0, 'currency_code' => 'USD']);
        unset($with['plan_type']);

        return Service::factory()->create(array_merge(['client_id' => ($client ?? $this->client)->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-10-25', 'label' => 'Hosting ' . Str::random(6)], $with));
    }

    private function domain(array $with = []): Domain
    {
        return Domain::factory()->create(array_merge(['client_id' => $this->client->id, 'currency_code' => 'USD'], $with));
    }

    /** A second login on the account with these permissions only (WHMCS names), working on it. */
    private function login(array $permissions): User
    {
        $login = User::factory()->create();
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $login->id, 'permissions' => $permissions, 'granted_at' => now()]);
        $this->actingAs($login);
        app(CurrentClient::class)->switchTo($this->client->id);

        return $login;
    }

    /** Staff in «Login as client» on the account, with exactly these admin permissions (and impersonate). */
    private function impersonate(array $permissions): void
    {
        session(['impersonating' => $this->user->id, CurrentClient::SESSION_KEY => $this->client->id, CurrentClient::IMPERSONATING_KEY => $this->client->id]);
        Auth::guard('staff')->setUser(Staff::factory()->withPermissions(array_merge(['admin.users.impersonate'], $permissions))->create());
        Auth::guard('web')->setUser($this->user);
        app(CurrentClient::class)->forget();
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Findings (fail on the PR head)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * ADV-612-1 (low: the page breaks, 500). items() reads the page straight from WithPagination::getPage('items'), which
     * returns $paginators['items'] as the client set it (gotoPage/setPage keep a non-numeric value as it is; `paginators`
     * is a public property). «($page - 1) * $perPage» on 'abc' throws a TypeError. Laravel's own paginate() (the
     * transactions below) goes through Paginator::resolveCurrentPage, whose resolver validates the number: it is safe.
     */
    public function test_adv_612_1_a_crafted_page_name_value_does_not_break_the_page(): void
    {
        $service = $this->service();
        $page = $this->livewireAs($this->user)->test(PaymentMethods::class);

        try {
            $page->call('gotoPage', 'abc', 'items');
        } catch (Throwable $e) {
            $this->fail('a crafted page of the table must show page 1, not fail: ' . $e::class . ': ' . $e->getMessage());
        }
        $page->assertSee($service->label);
    }

    /**
     * ADV-612-2 (low: the table is empty). The setting «Pagination» is a number with no minimum (Settings.php): 0 is
     * accepted, and a missing one reads as null. Laravel's paginate() falls back to the model's per page on 0 (the
     * transactions list below still shows), items() takes «(int) config('settings.pagination')» as it is: take(0), so the
     * table shows its heading and no row on any page, while its pager counts them. Suggested: max(1, ...) or the
     * paginate() fallback (15).
     */
    public function test_adv_612_2_a_pagination_setting_of_zero_does_not_break_the_page(): void
    {
        config(['settings.pagination' => 0]);
        $this->storeSettings(['pagination' => 0]);
        $service = $this->service();

        try {
            $this->livewireAs($this->user)->test(PaymentMethods::class)->assertSee($service->label);
        } catch (Throwable $e) {
            $this->fail('the table must list the service with pagination 0, as the transactions do: ' . $e::class . ': ' . $e->getMessage());
        }
    }

    /**
     * ADV-612-3 (low: what a login may not see). The rows are listed to any login that pays the account's invoices,
     * whatever its other permissions: a login with `invoices` only (no `products`, no `domains`: WHMCS's «view products»
     * and «view domains») sees every service's name, every domain, their renewal dates, how each is paid and which saved
     * method charges it, with links to pages that refuse it. The PR's own test pins this for services
     * (test_a_login_that_does_not_manage_the_service_sees_its_way_but_cannot_change_it): an owner's call. Suggested: list
     * a row only when Gate::allows('view', $item) (services `products`, domains `domains`).
     */
    public function test_adv_612_3_a_login_that_may_not_see_services_or_domains_is_not_shown_them(): void
    {
        $service = $this->service();
        $domain = $this->domain();
        $login = $this->login(['invoices']);
        $this->assertFalse($login->can('view', $service), 'precondition: the login may not see the service');
        $this->assertFalse($login->can('view', $domain), 'precondition: the login may not see the domain');

        $this->livewireAs($login)->test(PaymentMethods::class)
            ->assertDontSee($service->label)
            ->assertDontSee($domain->fqdn);
    }

    /**
     * ADV-612-4 (low: the two places disagree). The PR says who may change it is ItemPaymentAccess (mayChange and
     * offersChoice) and that the row's change is the same as the item's own page's. The pages use mayChange only: a
     * crafted call on a service's page changes the way of one paid by a subscription, or cancelled, and on a domain's page
     * of one not billed (pending registration), all of which the table refuses as «not available». Suggested: both pages
     * abort/refuse unless ItemPaymentAccess::offersChoice too.
     */
    public function test_adv_612_4_the_items_own_pages_refuse_what_the_table_refuses(): void
    {
        $subscribed = $this->service(['subscription_id' => 'I-SUB612']);
        $cancelled = $this->service(['status' => Service::STATUS_CANCELLED]);
        $pending = $this->domain(['status' => DomainStatus::PendingRegister]);

        // The table refuses all three (the PR's own guard).
        $table = $this->livewireAs($this->user)->test(PaymentMethods::class);
        $table->set('ways.s' . $subscribed->id, (string) $this->bank->id)->set('ways.s' . $cancelled->id, (string) $this->bank->id)
            ->set('ways.d' . $pending->id, (string) $this->bank->id);
        $this->assertNull($subscribed->fresh()->payment_gateway_id, 'precondition: the table refuses it');
        $this->assertNull($pending->fresh()->payment_gateway_id, 'precondition: the table refuses it');

        // Their own pages, with a crafted update.
        foreach ([$subscribed, $cancelled] as $service) {
            try {
                $this->livewireAs($this->user)->test(ServiceShow::class, ['service' => $service])->set('paymentGatewayId', (string) $this->bank->id);
            } catch (Throwable) {
                // refused by an abort: fine
            }
        }
        try {
            $this->livewireAs($this->user)->test(DomainShow::class, ['domain' => $pending])->call('setPaymentMethod', $this->bank->id);
        } catch (Throwable) {
            // refused by an abort: fine
        }

        $this->assertNull($subscribed->fresh()->payment_gateway_id, 'a service paid by a subscription: no way to choose on its page either');
        $this->assertNull($cancelled->fresh()->payment_gateway_id, 'a cancelled service: no way to choose on its page either');
        $this->assertNull($pending->fresh()->payment_gateway_id, 'a domain not billed: no way to choose on its page either');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Pins (pass on the PR head): guards the review tried and could not break
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Pin: a change while a charge of its open invoice is on its way changes the service, but the open invoice keeps the
     * way that charge was made with (followOnOpenInvoices, chargePending): no second method is sent a charge of it.
     */
    public function test_pin_a_change_while_a_charge_of_its_open_invoice_is_on_its_way_leaves_that_invoice_as_it_was(): void
    {
        $service = $this->service();
        $open = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-10-20']);
        $open->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);
        $preferred = $open->fresh()->preferred_gateway_id;
        ChargeAttempt::query()->forceCreate(['invoice_id' => $open->id, 'client_id' => $this->client->id, 'billing_agreement_id' => $this->card->id,
            'source' => ChargeAttempt::SOURCE_CLIENT, 'status' => ChargeAttempt::STATUS_STARTED, 'amount' => '10.00', 'currency_code' => 'USD',
            'key' => (string) Str::uuid(), 'started_at' => now(), 'sent_at' => now()]);

        $this->livewireAs($this->user)->test(PaymentMethods::class)->set('ways.s' . $service->id, (string) $this->paypal->id);

        $this->assertSame($this->paypal->id, $service->fresh()->payment_gateway_id, 'the service takes the new way (its next renewal)');
        $this->assertNull($open->items()->value('payment_gateway_id'), 'its open invoice keeps the card\'s way while that charge may land');
        $this->assertSame($preferred, $open->fresh()->preferred_gateway_id);
        $this->assertSame(1, Invoice::query()->where('client_id', $this->client->id)->count(), 'not split either');
    }

    /** Pin: `invoices` + `manageproducts`, no `managedomains`: the service changes, the domain's crafted change is refused. */
    public function test_pin_partial_permissions_change_only_what_the_login_manages(): void
    {
        $service = $this->service();
        $domain = $this->domain();
        $login = $this->login(['invoices', 'products', 'domains', 'manageproducts']);

        $page = $this->livewireAs($login)->test(PaymentMethods::class);
        $page->set('ways.s' . $service->id, (string) $this->bank->id);
        $this->assertSame($this->bank->id, $service->fresh()->payment_gateway_id);
        $page->set('ways.d' . $domain->id, (string) $this->bank->id)->assertForbidden();
        $this->assertNull($domain->fresh()->payment_gateway_id);
    }

    /** Pin: `managedomains` without `manageproducts`: the reverse. */
    public function test_pin_partial_permissions_the_reverse(): void
    {
        $service = $this->service();
        $domain = $this->domain();
        $login = $this->login(['invoices', 'products', 'domains', 'managedomains']);

        $page = $this->livewireAs($login)->test(PaymentMethods::class);
        $page->set('ways.d' . $domain->id, (string) $this->bank->id);
        $this->assertSame($this->bank->id, $domain->fresh()->payment_gateway_id);
        $page->set('ways.s' . $service->id, (string) $this->bank->id)->assertForbidden();
        $this->assertNull($service->fresh()->payment_gateway_id);
    }

    /** Pin: a grant revoked after the page was opened changes nothing. */
    public function test_pin_a_revoked_grant_changes_nothing(): void
    {
        $service = $this->service();
        $login = $this->login([]); // a grant without a list: all permissions
        $page = $this->livewireAs($login)->test(PaymentMethods::class);

        ClientUser::query()->where('user_id', $login->id)->update(['revoked_at' => now()]);
        app(CurrentClient::class)->forget(); // a new request

        $page->set('ways.s' . $service->id, (string) $this->bank->id)->assertForbidden();
        $this->assertNull($service->fresh()->payment_gateway_id);
    }

    /** Pin: staff with `pay` but without the item's admin permission (services.update, domains.update) change neither. */
    public function test_pin_staff_with_pay_but_without_the_items_admin_permission_change_nothing(): void
    {
        $service = $this->service();
        $domain = $this->domain();

        $this->impersonate(['admin.invoices.update', 'admin.domains.update']);
        Livewire::test(PaymentMethods::class)->set('ways.s' . $service->id, (string) $this->bank->id)->assertForbidden();
        $this->assertNull($service->fresh()->payment_gateway_id, 'no admin.services.update');

        $this->impersonate(['admin.invoices.update', 'admin.services.update']);
        Livewire::test(PaymentMethods::class)->set('ways.d' . $domain->id, (string) $this->bank->id)->assertForbidden();
        $this->assertNull($domain->fresh()->payment_gateway_id, 'no admin.domains.update');

        // Without `pay` (admin.invoices.update), with both item permissions: refused, the row as it is.
        $this->impersonate(['admin.services.update', 'admin.domains.update']);
        $page = Livewire::test(PaymentMethods::class)->set('ways.s' . $service->id, (string) $this->bank->id)->set('ways.d' . $domain->id, (string) $this->bank->id);
        $this->assertNull($service->fresh()->payment_gateway_id);
        $this->assertNull($domain->fresh()->payment_gateway_id);
        $this->assertSame('', $page->get('ways')['s' . $service->id]);
    }

    /** Pin: a gateway disabled or deleted after the page was rendered, and huge or odd values and keys, are refused. */
    public function test_pin_gateways_not_on_offer_and_odd_values_are_refused(): void
    {
        $service = $this->service();
        $page = $this->livewireAs($this->user)->test(PaymentMethods::class);

        $this->paypal->forceFill(['enabled' => false])->save();
        $page->set('ways.s' . $service->id, (string) $this->paypal->id);
        $this->assertNull($service->fresh()->payment_gateway_id, 'a disabled gateway');

        $this->bank->delete();
        $page->set('ways.s' . $service->id, (string) $this->bank->id);
        $this->assertNull($service->fresh()->payment_gateway_id, 'a deleted gateway');

        foreach (['99999999999999999999', '00', '+' . $this->stripe->id, $this->stripe->id . ' ', '0x' . $this->stripe->id, ['1'], true, 1.0] as $value) {
            $page->set('ways.s' . $service->id, $value);
        }
        $this->assertNull($service->fresh()->payment_gateway_id, 'odd values');
        foreach (['s9999999999999999999', 'S' . $service->id, 's 1', 's0' . $service->id] as $key) {
            $page->set('ways.' . $key, (string) $this->stripe->id);
        }
        $this->assertNull($service->fresh()->payment_gateway_id, 'odd keys');
        $this->assertSame('', $page->get('ways')['s' . $service->id], 'the row says what it still is');
    }

    /** Pin: the whole array replaced at once changes nothing (updatedWays has no key then). */
    public function test_pin_a_whole_array_replace_changes_nothing(): void
    {
        $service = $this->service();
        $page = $this->livewireAs($this->user)->test(PaymentMethods::class);

        $page->set('ways', ['s' . $service->id => (string) $this->stripe->id]);

        $this->assertNull($service->fresh()->payment_gateway_id);
        $this->assertSame('', $page->get('ways')['s' . $service->id]);
    }

    /** Pin: services then domains across pages: each row once, none missing, when the services do not fill a page. */
    public function test_pin_pages_split_between_services_and_domains_show_every_row_once(): void
    {
        config(['settings.pagination' => 2]);
        $this->storeSettings(['pagination' => 2]);
        $s = [$this->service(), $this->service(), $this->service()];
        $d = [$this->domain(), $this->domain()];
        $page = $this->livewireAs($this->user)->test(PaymentMethods::class);

        $seen = [];
        foreach ([1, 2, 3, 4] as $n) {
            $page->call('gotoPage', $n, 'items');
            $seen = array_merge($seen, array_keys($page->get('ways')));
        }

        $this->assertSame(['s' . $s[0]->id, 's' . $s[1]->id, 's' . $s[2]->id, 'd' . $d[0]->id, 'd' . $d[1]->id], $seen);
    }

    /** Pin: the extraction kept the domain page's rule: staff without admin.domains.update may not set its way there. */
    public function test_pin_the_domain_page_still_asks_staff_for_domains_update(): void
    {
        $domain = $this->domain();
        $this->impersonate(['admin.invoices.update']);

        Livewire::test(DomainShow::class, ['domain' => $domain])->call('setPaymentMethod', $this->bank->id)->assertForbidden();
        $this->assertNull($domain->fresh()->payment_gateway_id);
    }
}
