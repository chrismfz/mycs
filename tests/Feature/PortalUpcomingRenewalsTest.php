<?php

namespace Tests\Feature;

use App\Classes\Price;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price as PlanPrice;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\User;
use App\Providers\SettingsProvider;
use App\Support\CurrentClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P4d of the portal (owner 2026-10-19): the home page shows what renews this month and the next two, by month, with what
 * it will cost, by the rules of the renewal billing.
 */
class PortalUpcomingRenewalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
    }

    private function workOn(User $user, ?Client $client = null): static
    {
        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => ($client ?? $user->client())->id]));
    }

    /** The renewals card of the home page. */
    private function card(string $html): string
    {
        $start = (int) strpos($html, 'id="renewals-title"');

        return substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
    }

    private function money(string $amount): string
    {
        return e((string) new Price(['price' => $amount, 'currency' => Currency::query()->find('USD')], tax: 0));
    }

    private function service(Client $client, string $label, string $expires, string $price = '10.00', string $type = 'recurring'): Service
    {
        $product = $this->createProduct();
        Plan::query()->whereKey($product->plan->id)->update(['type' => $type]);

        return Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => $price, 'quantity' => 1, 'expires_at' => $expires, 'label' => $label]);
    }

    private function domain(Client $client, string $fqdn, string $expires, bool $autoRenew, string $price = '15.00'): Domain
    {
        [$sld, $tld] = explode('.', $fqdn, 2);
        $domainTld = DomainTld::query()->firstOrCreate(['tld' => $tld], ['grace_days' => 15, 'redemption_days' => 0, 'is_active' => true]);

        return Domain::factory()->create(['client_id' => $client->id, 'sld' => $sld, 'tld' => $tld, 'fqdn' => $fqdn, 'domain_tld_id' => $domainTld->id,
            'status' => DomainStatus::Active, 'expires_at' => $expires, 'auto_renew' => $autoRenew, 'years' => 1,
            'currency_code' => 'USD', 'recurring_amount' => $price]);
    }

    public function test_the_next_three_months_by_month_with_their_cost(): void
    {
        $user = User::factory()->create();
        $client = $user->client();
        $this->service($client, 'Hosting Business', '2026-11-05', '10.00');
        $this->service($client, 'Static IP', '2026-11-20', '2.50');
        $this->domain($client, 'example.gr', '2026-10-28', autoRenew: true);
        $this->domain($client, 'later.gr', '2027-02-01', autoRenew: true); // past the three months

        $html = $this->workOn($user)->get(route('dashboard'))->assertOk()
            ->assertSee(__('dashboard.renewals.title'))
            ->assertSee('Hosting Business')->assertSee('Static IP')->assertSee('example.gr')->assertDontSee('later.gr')
            ->getContent();

        // October: the domain alone; November: both services, summed.
        $october = substr($html, (int) strpos($html, 'wire:key="renewals-2026-10"'), 2000);
        $this->assertStringContainsString(trans_choice('dashboard.renewals.count', 1, ['count' => 1]), $october);
        $this->assertStringContainsString($this->money('15.00'), $october);
        $november = substr($html, (int) strpos($html, 'wire:key="renewals-2026-11"'), 2000);
        $this->assertStringContainsString(trans_choice('dashboard.renewals.count', 2, ['count' => 2]), $november);
        $this->assertStringContainsString($this->money('12.50'), $november);
        $this->assertStringContainsString('wire:key="renewals-2026-12"', $html, 'three months, an empty one too');
        $this->assertStringNotContainsString('wire:key="renewals-2027-01"', $html);
    }

    public function test_what_will_not_renew_is_not_counted(): void
    {
        $user = User::factory()->create();
        $client = $user->client();
        $cancelled = $this->service($client, 'Being cancelled', '2026-11-05');
        ServiceCancellation::query()->create(['service_id' => $cancelled->id, 'reason' => 'no longer needed', 'type' => 'end_of_period']);
        $this->service($client, 'One time setup', '2026-11-05', type: 'one-time');
        $this->service($client, 'Free thing', '2026-11-05', type: 'free');
        Service::query()->whereKey($this->service($client, 'Suspended one', '2026-11-05')->id)->update(['status' => Service::STATUS_SUSPENDED]);
        $this->domain($client, 'not-renewing.gr', '2026-11-05', autoRenew: false);

        $card = $this->card($this->workOn($user)->get(route('dashboard'))->assertOk()->getContent());
        foreach (['Being cancelled', 'One time setup', 'Free thing', 'Suspended one', 'not-renewing.gr'] as $name) {
            $this->assertStringNotContainsString($name, $card); // the services widget lists the active ones, not this card
        }
    }

    public function test_a_member_sees_only_the_areas_of_their_grant(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $this->service($client, 'Hosting Business', '2026-11-05');
        $this->domain($client, 'example.gr', '2026-11-05', autoRenew: true);

        $domainsOnly = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $domainsOnly->id, 'permissions' => ['domains'], 'granted_at' => now()]);
        $card = $this->card($this->workOn($domainsOnly, $client)->get(route('dashboard'))->assertOk()->getContent());
        $this->assertStringContainsString('example.gr', $card);
        $this->assertStringNotContainsString('Hosting Business', $card);

        $ticketsOnly = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $ticketsOnly->id, 'permissions' => ['tickets'], 'granted_at' => now()]);
        $this->workOn($ticketsOnly, $client)->get(route('dashboard'))->assertOk()
            ->assertDontSee(__('dashboard.renewals.title'))->assertDontSee('example.gr');
    }

    public function test_a_domain_priced_by_staff_is_listed_without_a_price(): void
    {
        $user = User::factory()->create();
        $domain = $this->domain($user->client(), 'ab.gr', '2026-11-05', autoRenew: true);
        Domain::query()->whereKey($domain->id)->update(['recurring_amount' => null]); // no TLD price either

        $this->workOn($user)->get(route('dashboard'))->assertOk()
            ->assertSee('ab.gr')->assertSee(__('dashboard.renewals.priced_by_us'));
    }

    public function test_the_cost_is_with_vat_as_the_renewal_invoice_charges(): void
    {
        foreach (['tax_enabled' => [true, 'boolean'], 'tax_type' => ['exclusive', 'string']] as $key => [$value, $type]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $type]);
        }
        SettingsProvider::reload();
        TaxRate::query()->create(['name' => 'VAT', 'rate' => 24, 'country' => 'all']);
        $user = User::factory()->create();
        $this->service($user->client(), 'Hosting Business', '2026-11-05', '10.00');
        $this->domain($user->client(), 'example.gr', '2026-11-06', autoRenew: true, price: '15.00');

        $card = $this->card($this->workOn($user)->get(route('dashboard'))->assertOk()->getContent());
        $this->assertStringContainsString($this->money('12.40'), $card, 'the service: 10.00 + 24%');
        $this->assertStringContainsString($this->money('18.60'), $card, 'the domain: 15.00 + 24%');
        $this->assertStringContainsString($this->money('31.00'), $card, 'the month');
    }

    /** The items of a month's box, as the page lists them. */
    private function month(string $html, string $month): string
    {
        $start = (int) strpos($html, 'wire:key="renewals-' . $month . '"');

        return substr($html, $start, (int) strpos($html, '</details>', $start) - $start);
    }

    public function test_a_monthly_service_renews_every_month_a_yearly_one_once(): void
    {
        // Code review of #231: a monthly service is billed each month of the three, not once.
        $user = User::factory()->create();
        $client = $user->client();
        $this->service($client, 'Monthly hosting', '2026-10-25', '10.00');
        $yearly = $this->service($client, 'Yearly backup', '2026-11-03', '50.00');
        Plan::query()->whereKey($yearly->plan_id)->update(['billing_unit' => 'year']);

        $html = $this->workOn($user)->get(route('dashboard'))->assertOk()->getContent();
        foreach (['2026-10' => '25/10/2026', '2026-11' => '25/11/2026', '2026-12' => '25/12/2026'] as $month => $date) {
            $this->assertStringContainsString($date . ' · Monthly hosting', $this->month($html, $month));
        }
        $this->assertStringContainsString('Yearly backup', $this->month($html, '2026-11'));
        $this->assertStringNotContainsString('Yearly backup', $this->month($html, '2026-12'));
        $this->assertStringContainsString($this->money('60.00'), $this->month($html, '2026-11'));
    }

    public function test_the_last_day_of_the_window_counts(): void
    {
        $user = User::factory()->create();
        $this->service($user->client(), 'New year eve', '2026-12-31 00:00:00');

        $html = $this->workOn($user)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('New year eve', $this->month($html, '2026-12'));
    }

    public function test_an_invoiced_renewal_is_marked_a_paid_one_is_not_to_come_a_free_one_is_not_listed(): void
    {
        $user = User::factory()->create();
        $client = $user->client();
        $invoiced = $this->service($client, 'Already invoiced', '2026-10-27', '10.00');
        $paid = $this->service($client, 'Already paid', '2026-10-27', '10.00');
        $this->service($client, 'Free of charge', '2026-10-27', '0.00');
        foreach ([[$invoiced, Invoice::STATUS_UNPAID], [$paid, Invoice::STATUS_PAID]] as [$service, $status]) {
            $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
            InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'reference_type' => Service::class, 'reference_id' => $service->id,
                'price' => 10, 'quantity' => 1, 'description' => 'Renewal', 'period_start' => '2026-10-27', 'period_end' => '2026-11-27']);
            Invoice::query()->whereKey($invoice->id)->update(['status' => $status]);
        }

        $html = $this->workOn($user)->get(route('dashboard'))->assertOk()->getContent();
        $october = $this->month($html, '2026-10');
        $this->assertStringContainsString('Already invoiced', $october);
        $this->assertStringContainsString(__('dashboard.renewals.invoiced'), $october);
        $this->assertStringNotContainsString('Already paid', $october, 'paid, waiting to be applied: not to come');
        $this->assertStringContainsString('Already paid', $this->month($html, '2026-11'), 'its next period is');
        $this->assertStringNotContainsString('Free of charge', $html);
    }

    public function test_a_cycle_change_from_the_next_renewal_is_forecast_at_the_next_plans_price(): void
    {
        $user = User::factory()->create();
        $service = $this->service($user->client(), 'Changing cycle', '2026-11-10', '10.00');
        $yearly = Plan::factory()->create(['priceable_id' => $service->product_id, 'priceable_type' => $service->plan->priceable_type,
            'name' => 'Yearly', 'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        PlanPrice::factory()->create(['plan_id' => $yearly->id, 'price' => 100.00, 'currency_code' => 'USD']);
        Service::query()->whereKey($service->id)->update(['next_plan_id' => $yearly->id]);

        $html = $this->workOn($user)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString($this->money('100.00'), $this->month($html, '2026-11'));
        $this->assertStringNotContainsString('Changing cycle', $this->month($html, '2026-12'), 'yearly from then on');
    }
}
