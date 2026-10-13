<?php

namespace Tests\Feature;

use App\Classes\Settings;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Settings::tax() per customer (audit sweep KR-6, INV-2). It was memoised with once(): never flushed, so a worker
 * (queue, cron run) kept a customer's rate after the rate or the customer's country changed, until it restarted
 * (reproduced below). once() also keyed it by the User object's id, which PHP reuses after an object is freed; that
 * collision did not reproduce here (the models stay referenced), the per-id memo removes it anyway.
 */
class SettingsTaxRateTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $country): User
    {
        $user = User::factory()->create();
        $user->properties()->create(['key' => 'country', 'name' => 'Country', 'value' => $country]);

        return $user;
    }

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

    public function test_no_rate_is_zero(): void
    {
        $this->assertSame(0, Settings::tax($this->customer('US')));
    }
}
