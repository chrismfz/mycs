<?php

namespace Tests\Feature;

use App\Events\Service\RenewalTaxChanged;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Tax\EuVatRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use MyCS\EuVatRates\StandardRates;
use Tests\TestCase;

/** Review of the tax-reports sweep fixes (TAX-REPORTS-1..4). */
class RevTaxReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.default_currency' => 'EUR']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function subscribed(string $country, string $subscription): Service
    {
        $client = User::factory()->create()->client();
        $client->primaryContact->update(['country' => $country]);
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'p',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'setup_fee' => 0, 'currency_code' => 'EUR']);

        return Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-11-05',
            'subscription_id' => $subscription]);
    }

    /**
     * TAX-REPORTS-1 fix: «once per commit however many rates (a seed of the EU rates saves dozens)». EuVatRates::seed
     * (app:eu-vat-rates-seed) creates the 27 rates one by one with no transaction, so DB::afterCommit runs at once for
     * each and every subscription is repriced 27 times: 27 synchronous Stripe/PayPal updateSubscription calls per
     * subscribed service, inline in the command.
     */
    public function test_seeding_the_eu_rates_reprices_each_subscription_once(): void
    {
        $this->subscribed('EE', 'sub_rev_1');
        Event::fake([RenewalTaxChanged::class]);

        $added = app(EuVatRates::class)->seed();

        $this->assertCount(count(StandardRates::EU), $added, 'all the EU rates added');
        $this->assertGreaterThan(1, TaxRate::query()->count());
        Event::assertDispatchedTimes(RenewalTaxChanged::class, 1);
    }
}
