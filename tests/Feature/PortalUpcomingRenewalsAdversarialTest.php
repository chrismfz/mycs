<?php

namespace Tests\Feature;

use App\Classes\Price;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\User;
use App\Providers\SettingsProvider;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\CurrentClient;
use App\Support\Domains\DomainRegistrarCapabilities;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Adversarial review of #231 (P4d-1, «Upcoming renewals»): the forecast against what the renewal billing (RenewalBilling,
 * the cron's invoices_created) actually invoices. The oracle: bill the item the day before it renews and compare.
 */
class PortalUpcomingRenewalsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-20 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
    }

    private function workOn(User $user, ?Client $client = null): static
    {
        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => ($client ?? $user->client())->id]));
    }

    private function card(User $user): string
    {
        $html = $this->workOn($user)->get(route('dashboard'))->assertOk()->getContent();
        $start = strpos($html, 'id="renewals-title"');
        $this->assertNotFalse($start, 'the renewals card is shown');

        return substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
    }

    private function money(string $amount, string $currency = 'USD'): string
    {
        return e((string) new Price(['price' => $amount, 'currency' => Currency::query()->find($currency)], tax: 0));
    }

    private function settings(array $values): void
    {
        foreach ($values as $key => [$value, $type]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $type]);
        }
        SettingsProvider::reload();
    }

    private function vat(string $mode): void
    {
        $this->settings(['tax_enabled' => [true, 'boolean'], 'tax_type' => [$mode, 'string']]);
        TaxRate::query()->create(['name' => 'VAT', 'rate' => 24, 'country' => 'all']);
    }

    private function service(Client $client, string $label, string $expires, string $price = '10.00', array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(array_merge(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => $price, 'quantity' => 1, 'expires_at' => $expires, 'label' => $label], $attributes));
    }

    private function domain(Client $client, string $fqdn, string $expires, string $price = '15.00', array $attributes = []): Domain
    {
        [$sld, $tld] = explode('.', $fqdn, 2);
        $domainTld = DomainTld::query()->firstOrCreate(['tld' => $tld], ['grace_days' => 15, 'redemption_days' => 0, 'is_active' => true]);

        return Domain::factory()->create(array_merge(['client_id' => $client->id, 'sld' => $sld, 'tld' => $tld, 'fqdn' => $fqdn,
            'domain_tld_id' => $domainTld->id, 'status' => DomainStatus::Active, 'expires_at' => $expires, 'auto_renew' => true, 'years' => 1,
            'currency_code' => 'USD', 'recurring_amount' => $price], $attributes));
    }

    /** What the cron invoices for it, the day before it renews (null: nothing billed); the clock is put back. */
    private function billed(Service|Domain $item): ?string
    {
        Carbon::setTestNow(Carbon::parse($item->fresh()->expires_at)->subDay()->setTime(10, 0));
        try {
            $billing = new RenewalBilling;
            $made = $item instanceof Service
                ? DB::transaction(fn () => $billing->billRenewal($item->id, now()->addDays(7)))
                : DB::transaction(fn () => $billing->billDomainRenewal($item->id, now()->addDays(15)));
            if ($made === 0) {
                return null;
            }
            $line = InvoiceItem::query()->where('reference_type', $item::class)->where('reference_id', $item->id)->latest('id')->first();

            return number_format((float) $line->invoice->fresh()->total, 2, '.', '');
        } finally {
            Carbon::setTestNow(self::NOW);
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Findings: the forecast differs from what is invoiced.
    // ---------------------------------------------------------------------------------------------------------------

    /** ADV-1: a domain whose renewal is paid (its registrar renewal not done yet, expiry not moved) is forecast again. */
    public function test_a_domain_whose_renewal_is_already_paid_is_not_forecast_again(): void
    {
        $user = User::factory()->create();
        $domain = $this->domain($user->client(), 'paid-already.com', '2026-11-03', '15.00');
        $invoice = Invoice::query()->create(['client_id' => $user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => '2026-11-03', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'price' => '15.00', 'quantity' => 1,
            'description' => 'paid-already.com renewal', 'period_start' => '2026-11-03', 'period_end' => '2027-11-03']);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_PAID]);

        $this->assertNull($this->billed($domain), 'precondition: the cron never bills this period again');
        $this->assertStringNotContainsString('paid-already.com', $this->card($user), 'paid: nothing more to pay for it');
    }

    /** ADV-2: a domain whose period was given up on (cancelled, forgone: Δ3) is never billed, yet forecast with its price. */
    public function test_a_domain_whose_renewal_was_given_up_is_not_forecast(): void
    {
        $user = User::factory()->create();
        $domain = $this->domain($user->client(), 'given-up.com', '2026-11-03', '15.00');
        $invoice = Invoice::query()->create(['client_id' => $user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => '2026-11-03', 'currency_code' => 'USD']);
        $line = $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'price' => '15.00', 'quantity' => 1,
            'description' => 'given-up.com renewal', 'period_start' => '2026-11-03', 'period_end' => '2027-11-03']);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_CANCELLED]);
        InvoiceItem::query()->whereKey($line->id)->update(['forgone_at' => now()]);

        $this->assertNull($this->billed($domain), 'precondition: the cron never bills a forgone period');
        $this->assertStringNotContainsString($this->money('15.00'), $this->card($user), 'not billed: no cost forecast');
    }

    /** ADV-3: a service whose renewal is paid, still to be applied (BIL-9): the cron does not bill it, the forecast asks for it. */
    public function test_a_service_whose_renewal_is_paid_but_not_applied_is_not_forecast(): void
    {
        $user = User::factory()->create();
        $service = $this->service($user->client(), 'Paid Hosting', '2026-11-05', '10.00');
        $invoice = Invoice::query()->create(['client_id' => $user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => '2026-11-05', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'price' => '10.00', 'quantity' => 1,
            'description' => 'Paid Hosting renewal', 'period_start' => '2026-11-05', 'period_end' => '2026-12-05']);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_PAID, 'apply_pending_since' => now()]);

        $this->assertNull($this->billed($service), 'precondition: the cron does not bill it (BIL-9)');
        $this->assertStringNotContainsString('Paid Hosting', $this->card($user), 'paid: nothing more to pay for it');
    }

    /** ADV-4: a change of cycle «from the next renewal» (SVC-14b, next_plan_id) is billed at the new plan's price. */
    public function test_a_change_of_cycle_at_the_next_renewal_is_forecast_at_its_price(): void
    {
        $user = User::factory()->create();
        $service = $this->service($user->client(), 'Hosting Yearly Next', '2026-11-05', '10.00');
        $yearly = Plan::factory()->create(['priceable_id' => $service->product_id, 'priceable_type' => Product::class, 'name' => 'Yearly',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        \App\Models\Price::factory()->create(['plan_id' => $yearly->id, 'price' => 100.00, 'currency_code' => 'USD']);
        Service::query()->whereKey($service->id)->update(['next_plan_id' => $yearly->id]);

        $card = $this->card($user);
        $this->assertSame('100.00', $this->billed($service), 'precondition: the cron bills the yearly plan');
        $this->assertStringContainsString($this->money('100.00'), $card, 'forecast at what it will be billed');
    }

    /** ADV-5: a coupon «for N cycles» that ends at this renewal: billed at the full price, forecast at the discounted one. */
    public function test_a_coupon_ending_at_this_renewal_is_forecast_at_the_full_price(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::query()->create(['type' => 'percentage', 'value' => 50, 'code' => 'HALF2', 'recurring' => 2]);
        $service = $this->service($user->client(), 'Coupon Hosting', '2026-11-05', '5.00', ['coupon_id' => $coupon->id]);
        Service::query()->whereKey($service->id)->update(['coupon_started_at' => '2026-08-05', 'created_at' => '2026-08-05']);

        $card = $this->card($user);
        $this->assertSame('10.00', $this->billed($service), 'precondition: the 4th cycle is billed at the full price');
        $this->assertStringContainsString($this->money('10.00'), $card, 'forecast at what it will be billed');
    }

    /** ADV-6: services.expires_at is a datetime: one renewing in the afternoon of the window's last day is left out. */
    public function test_a_service_renewing_late_on_the_last_day_of_the_window_is_counted(): void
    {
        $user = User::factory()->create();
        $service = $this->service($user->client(), 'Late Hosting', '2026-12-31', '10.00');
        // As a datetime column holds it when written with its time (an import, a raw update; the cast keeps it).
        DB::table('services')->where('id', $service->id)->update(['expires_at' => '2026-12-31 15:00:00']);
        $this->assertSame('2026-12-31', $service->fresh()->expires_at->toDateString(), 'precondition: it renews on 31/12');
        $this->assertSame('10.00', $this->billed($service->fresh()), 'precondition: the cron bills it');

        $this->assertStringContainsString('Late Hosting', $this->card($user), 'it renews in December');
    }

    /** ADV-7: a .gr domain set to an odd period: its registrar renews in steps of 2, the cron never bills it (#189). */
    public function test_a_domain_with_a_period_its_registrar_refuses_is_not_forecast_with_a_price(): void
    {
        config(['domains.registrars.advp4dgr' => AdvP4dGrRegistrar::class]);
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'advp4dgr', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $user = User::factory()->create();
        $domain = $this->domain($user->client(), 'odd-years.gr', '2026-11-05', '15.00', ['registrar_connection_id' => $gr->id, 'years' => 1]);

        $this->assertSame(2, $domain->renewYearStep(), 'precondition: the registrar renews in steps of two years');
        $this->assertNull($this->billed($domain), 'precondition: the cron never bills it');
        $card = $this->card($user);
        $this->assertStringContainsString('odd-years.gr', $card, 'listed: it is due');
        $this->assertStringNotContainsString($this->money('15.00'), $card, 'but not at a price it will never be billed at');
    }

    /** ADV-8: suspended for non-payment, paid since (Δ3a, D3A-9): the cron bills its next period, the forecast hides it. */
    public function test_a_suspended_service_paid_since_suspended_is_forecast(): void
    {
        $user = User::factory()->create();
        $service = $this->service($user->client(), 'Suspended Paid Since', '2026-11-05', '10.00');
        $invoice = Invoice::query()->create(['client_id' => $user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => '2026-09-05', 'currency_code' => 'USD']);
        $line = $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'price' => '10.00', 'quantity' => 1,
            'description' => 'renewal', 'period_start' => '2026-10-05', 'period_end' => '2026-11-05']);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        InvoiceItem::query()->whereKey($line->id)->update(['applied_at' => '2026-10-18 10:00:00']);
        Service::query()->whereKey($service->id)->update(['status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-10-10 10:00:00']);

        $card = $this->card($user);
        $this->assertSame('10.00', $this->billed($service), 'precondition: the cron bills its next period');
        $this->assertStringContainsString('Suspended Paid Since', $card, 'it will be invoiced: forecast it');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Holds: the forecast is the invoice's amount.
    // ---------------------------------------------------------------------------------------------------------------

    public function test_holds_vat_inclusive_prices_match_the_invoice(): void
    {
        $this->vat('inclusive');
        $user = User::factory()->create();
        $service = $this->service($user->client(), 'Incl Hosting', '2026-11-05', '8.07');
        $domain = $this->domain($user->client(), 'incl.com', '2026-12-06', '15.00');

        $card = $this->card($user);
        foreach ([$service, $domain] as $item) {
            $billed = $this->billed($item);
            $this->assertNotNull($billed);
            $this->assertStringContainsString($this->money($billed), $card, class_basename($item) . " billed at {$billed}");
        }
    }

    public function test_holds_tax_exempt_and_not_taxable_and_quantity_match_the_invoice(): void
    {
        $this->vat('exclusive');
        $exempt = User::factory()->create();
        Client::query()->whereKey($exempt->client()->id)->update(['tax_exempt' => true]);
        $exemptService = $this->service($exempt->client(), 'Exempt Hosting', '2026-11-05', '10.00');
        $exemptDomain = $this->domain($exempt->client(), 'exempt.com', '2026-12-06', '15.00');

        $user = User::factory()->create();
        $untaxed = $this->service($user->client(), 'Untaxed Hosting', '2026-11-07', '7.00');
        Product::query()->whereKey($untaxed->product_id)->update(['taxable' => false]);
        $several = $this->service($user->client(), 'Three IPs', '2026-12-08', '2.35', ['quantity' => 3]);

        $exemptCard = $this->card($exempt);
        foreach ([$exemptService, $exemptDomain] as $item) {
            $this->assertStringContainsString($this->money($this->billed($item)), $exemptCard);
        }
        $card = $this->card($user);
        foreach ([$untaxed, $several] as $item) {
            $this->assertStringContainsString($this->money($this->billed($item)), $card);
        }
    }

    public function test_holds_another_accounts_renewals_are_never_shown(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->service($other->client(), 'Someone Elses Hosting', '2026-11-05');
        $this->domain($other->client(), 'someone-else.com', '2026-11-05');

        $card = $this->card($user);
        $this->assertStringNotContainsString('Someone Elses Hosting', $card);
        $this->assertStringNotContainsString('someone-else.com', $card);
    }

    public function test_holds_a_login_with_no_account_does_not_crash_the_home_page(): void
    {
        $user = User::factory()->withoutClient()->create();
        $status = $this->actingAs($user)->withSession($this->loginUser($user))->get(route('dashboard'))->status();
        $this->assertLessThan(500, $status);
    }
}

/** A .gr-like registrar: renews in steps of two years. */
class AdvP4dGrRegistrar extends GrEppRegistrar
{
    public function key(): string
    {
        return 'advp4dgr';
    }

    public function capabilities(): DomainRegistrarCapabilities
    {
        return new DomainRegistrarCapabilities(renewsInGrace: true, renewYearStep: 2);
    }
}
