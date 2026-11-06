<?php

namespace Tests\Feature\Domains;

use App\Exceptions\DisplayException;
use App\Models\Currency;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Services\Domains\DomainSearch;
use App\Services\Domains\Ordering\DomainOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Review of ADV-A5-1: a registration renews for the years it was registered for. One sold for years its renewal is not
 * offered for (none, or a year at 0.00 WHMCS never sold) is never billed at renewal and lapses: never sold.
 */
class RegisterNeedsRenewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
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

    public function test_a_registration_whose_renewal_is_not_offered_is_not_sold(): void
    {
        $this->tld('com', ['register' => [1 => '10.00'], 'renew' => [1 => '0.00']]);
        $this->tld('net', ['register' => [1 => '12.00']]);

        $this->assertSame([], app(DomainSearch::class)->sold('EUR')->pluck('tld')->all(), 'no renewal offered: not sold');
        $this->assertSame([], app(DomainSearch::class)->priceList('EUR'));
        $refused = null;
        try {
            app(DomainOffer::class)->check('mybusiness.com', 'register', 1, 'EUR', availability: false);
        } catch (DisplayException $e) {
            $refused = $e;
        }
        $this->assertNotNull($refused, 'never ordered');
    }

    public function test_a_registration_is_sold_only_for_the_years_it_renews_for(): void
    {
        $org = $this->tld('org', ['register' => [1 => '10.00', 2 => '20.00', 3 => '30.00'], 'renew' => [1 => '11.00', 2 => '22.00', 3 => '0.00']]);

        $this->assertSame([1, 2], app(DomainOffer::class)->years($org, 'register', 'EUR')->all());
        $list = app(DomainSearch::class)->priceList('EUR');
        $this->assertSame(['20.00', null], [$list[0]['years'][2]['register'], $list[0]['years'][3]['register']]);
        $refused = null;
        try {
            app(DomainOffer::class)->check('mybusiness.org', 'register', 3, 'EUR', availability: false);
        } catch (DisplayException $e) {
            $refused = $e;
        }
        $this->assertNotNull($refused, 'three years it could never renew for: refused');
        $this->assertSame('mybusiness.org', app(DomainOffer::class)->check('mybusiness.org', 'register', 2, 'EUR', availability: false)[0]);
    }
}
