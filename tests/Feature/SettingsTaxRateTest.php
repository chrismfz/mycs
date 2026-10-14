<?php

namespace Tests\Feature;

use App\Classes\Settings;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Settings::tax() per customer (audit sweep KR-6, INV-2). It was memoised with once(): never flushed, so a worker
 * (queue, cron run) kept a customer's rate after the rate or the customer's country changed, until it restarted
 * (reproduced below). once() also keyed it by the User object's id, which PHP reuses after an object is freed; that
 * collision did not reproduce here (the models stay referenced); the memo is now per country, so it cannot happen.
 */
class SettingsTaxRateTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $country): User
    {
        $user = User::factory()->create();
        $user->client()->properties()->create(['key' => 'country', 'name' => 'Country', 'value' => $country]);

        return $user;
    }

    /** A regression guard (passes on the old code too): the object-id collision did not reproduce. */
    public function test_each_customer_in_a_loop_gets_their_own_rate(): void
    {
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        TaxRate::create(['name' => 'MwSt', 'rate' => 19, 'country' => 'DE']);
        $expected = [];
        foreach (range(1, 6) as $i) {
            $country = $i % 2 ? 'GR' : 'DE';
            $expected[$this->customer($country)->id] = $country === 'GR' ? 24 : 19;
        }

        $got = [];
        // One customer at a time, the previous one freed: as the cron's renewal loop does.
        foreach (User::query()->whereIn('id', array_keys($expected))->orderBy('id')->cursor() as $user) {
            $got[$user->id] = (float) Settings::tax($user)->rate;
        }

        $this->assertEquals($expected, $got);
    }

    public function test_a_rate_changed_meanwhile_is_seen_by_the_next_job(): void
    {
        $rate = TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        $user = $this->customer('GR');
        $this->assertEquals(24, Settings::tax($user)->rate);

        $rate->update(['rate' => 13]);
        $this->app->forgetScopedInstances(); // what the queue worker does after every job

        // The same model instance (a job holding the customer): once() kept 24 for the life of the worker.
        $this->assertEquals(13, Settings::tax($user)->rate);
    }

    public function test_the_rate_is_looked_up_once_per_country_within_a_request(): void
    {
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        $first = $this->customer('GR');
        $second = $this->customer('GR');
        Settings::tax($first);

        DB::enableQueryLog();
        Settings::tax($first);
        Settings::tax($second);
        $lookups = array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'tax_rates'));

        $this->assertCount(0, $lookups);
    }

    public function test_no_rate_is_zero(): void
    {
        $this->assertSame(0, Settings::tax($this->customer('US')));
    }
}
