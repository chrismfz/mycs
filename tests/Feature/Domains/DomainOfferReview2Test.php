<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainTldResource\Pages\EditDomainTld;
use App\Admin\Resources\DomainTldResource\RelationManagers\PricesRelationManager;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\EmailLog;
use App\Models\InvoiceItem;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainSearch;
use App\Services\Domains\Ordering\DomainOffer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Review 2 of #696 (domains, findings D1-D8): what is sold follows one rule (DomainTld::offeredYears): a registration for
 * the years it renews for, a transfer only where a renewal is offered; the price list, the cart and the checkout agree;
 * a 0.00 renewal tells staff; the admin shows a 0.00 registration or renewal apart from an empty price.
 */
class DomainOfferReview2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
    }

    /** @param  array<string, array<int, ?string>>  $prices */
    private function tld(string $name, array $prices): DomainTld
    {
        $tld = DomainTld::query()->create(['tld' => $name, 'is_active' => true]);
        foreach ($prices as $operation => $byYears) {
            foreach ($byYears as $years => $price) {
                DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
            }
        }

        return $tld->load('prices');
    }

    private function refused(string $fqdn, string $operation, int $years): bool
    {
        try {
            app(DomainOffer::class)->check($fqdn, $operation, $years, 'EUR', availability: false);
        } catch (DisplayException) {
            return true;
        }

        return false;
    }

    /** D1: a transfer of a TLD with no renewal offered (none, or 0.00) would lapse unbilled: never sold, free or priced. */
    public function test_d1_a_transfer_is_not_sold_where_no_renewal_is_offered(): void
    {
        $com = $this->tld('com', ['register' => [1 => '10.00'], 'renew' => [1 => '11.00'], 'transfer' => [1 => '9.00']]);
        DomainTldPrice::query()->where(['domain_tld_id' => $com->id, 'operation' => 'renew'])->update(['price' => '0.00']);
        $com->load('prices');
        $net = $this->tld('net', ['register' => [1 => '12.00'], 'transfer' => [1 => '0.00']]);

        foreach ([$com, $net] as $tld) {
            $this->assertSame([], app(DomainOffer::class)->years($tld, 'transfer', 'EUR')->all(), ".{$tld->tld}: no renewal, no transfer");
            $this->assertNull($tld->priceFor('transfer', 1, 'EUR'));
            $this->assertNull($tld->shortestYears('transfer', 'EUR'));
        }
        $this->assertTrue($this->refused('mybusiness.com', 'transfer', 1), 'a priced transfer that never renews');
        $this->assertTrue($this->refused('mybusiness.net', 'transfer', 1), 'a free transfer that never renews');
    }

    /** D1: a 1-year TLD with its renewal offered sells its transfer, as before. */
    public function test_d1_a_transfer_is_sold_where_its_renewal_is_offered(): void
    {
        $org = $this->tld('org', ['register' => [1 => '10.00'], 'renew' => [1 => '11.00'], 'transfer' => [1 => '9.00']]);

        $this->assertSame([1], app(DomainOffer::class)->years($org, 'transfer', 'EUR')->all());
        $this->assertSame('9.00', $org->priceFor('transfer', 1, 'EUR'));
        $this->assertFalse($this->refused('mybusiness.org', 'transfer', 1));
    }

    /**
     * D1, .gr: its renewals are even years only (2/4/...) and its transfer has no period (D-5): sold when a renewal is
     * offered at all, never «the transfer's one year renewed». Without one, not sold.
     */
    public function test_d1_a_gr_transfer_needs_a_renewal_offered_at_all_not_one_for_one_year(): void
    {
        $gr = $this->tld('gr', ['register' => [2 => '24.00'], 'renew' => [2 => '24.00', 4 => '48.00'], 'transfer' => [1 => '0.00']]);

        $this->assertSame([1], app(DomainOffer::class)->years($gr, 'transfer', 'EUR')->all());
        $this->assertSame('0.00', $gr->priceFor('transfer', 1, 'EUR'), 'free and offered');
        $this->assertFalse($this->refused('mybusiness.gr', 'transfer', 1));

        DomainTldPrice::query()->where(['domain_tld_id' => $gr->id, 'operation' => 'renew'])->update(['price' => null]);
        $gr->load('prices');
        $this->assertNull($gr->priceFor('transfer', 1, 'EUR'));
        $this->assertTrue($this->refused('mybusiness.gr', 'transfer', 1));
    }

    /** D2: the cart never prices a registration year the checkout refuses (its renewal not offered for it). */
    public function test_d2_the_cart_prices_only_the_years_the_checkout_sells(): void
    {
        $org = $this->tld('org', ['register' => [1 => '10.00', 3 => '30.00'], 'renew' => [1 => '11.00', 3 => '0.00']]);
        $cart = Cart::query()->create(['currency_code' => 'EUR']);
        $item = fn (int $years) => CartItem::query()->create(['cart_id' => $cart->id, 'quantity' => 1,
            'domain' => ['fqdn' => 'mybusiness.org', 'tld_id' => $org->id, 'operation' => 'register', 'years' => $years]]);

        $this->assertNull($org->priceFor('register', 3, 'EUR'), 'one rule with offeredYears');
        $this->assertNull($item(3)->price->currency, 'unavailable, not priced: three years it could never renew for');
        $this->assertTrue($this->refused('mybusiness.org', 'register', 3));
        $this->assertSame([10.0, 'EUR'], [$item(1)->price->price, $item(1)->price->currency?->code]);
        $this->assertFalse($this->refused('mybusiness.org', 'register', 1));
    }

    /** D5: years with nothing offered (register and renew 0.00, no transfer) are no row of the price list. */
    public function test_d5_the_price_list_shows_no_year_with_nothing_offered(): void
    {
        $this->tld('com', ['register' => [1 => '10.00', 2 => '20.00', 3 => '0.00'], 'renew' => [1 => '11.00', 2 => '22.00', 3 => '0.00'], 'transfer' => [1 => '9.00']]);

        $list = app(DomainSearch::class)->priceList('EUR');

        $this->assertSame([1, 2], array_keys($list[0]['years']));
        $this->assertSame(['register' => '10.00', 'renew' => '11.00', 'transfer' => '9.00'], $list[0]['years'][1]);
        $this->assertSame(['register' => '20.00', 'renew' => '22.00', 'transfer' => null], $list[0]['years'][2]);
    }

    /** D3: a renewal at 0.00 (not offered) is never billed, and staff are told why (the cron's «no renew price»). */
    public function test_d3_a_zero_renewal_is_not_billed_and_staff_are_told(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        config(['settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false,
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $tld = $this->tld('com', ['renew' => [1 => '0.00']]);
        $domain = Domain::factory()->create(['client_id' => User::factory()->create()->client()->id, 'sld' => 'mybusiness', 'tld' => 'com',
            'fqdn' => 'mybusiness.com', 'domain_tld_id' => $tld->id, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-01',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);

        try {
            $this->artisan('app:cron-job')->assertExitCode(0);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertFalse(InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->exists(), 'never billed 0.00');
        $this->assertTrue(EmailLog::query()->where('subject', 'like', '%needs staff attention%')
            ->where('body', 'like', '%Domain mybusiness.com: no renew price for 1 year(s) in EUR: not billed%')->exists(), 'staff are told');
    }

    /** D7: a 0.00 registration or renewal shows apart from an empty one, and its edit form says how to save it: cleared or priced. */
    public function test_d7_a_zero_renewal_is_labelled_apart_and_can_be_cleared(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $tld = DomainTld::query()->create(['tld' => 'gr']);
        $zero = DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR', 'price' => '0.00']);
        $empty = DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 2, 'currency_code' => 'EUR', 'price' => null]);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['*']])->id]);
        $page = fn () => $this->livewireAs($staff)->test(PricesRelationManager::class, ['ownerRecord' => $tld, 'pageClass' => EditDomainTld::class]);

        $page()->assertTableColumnStateSet('price', __('0.00 — not offered: clear or price it'), $zero)
            ->assertTableColumnStateSet('price', null, $empty);
        $page()->callTableAction('edit', $zero)
            ->assertHasTableActionErrors(['price' => __('A registration or renewal is not offered at 0.00. Clear the price (not offered) or give it a price.')]);
        $page()->callTableAction('edit', $zero, data: ['price' => null])->assertHasNoTableActionErrors();

        $this->assertNull($zero->fresh()->price, 'cleared: not offered');
    }
}
