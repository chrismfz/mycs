<?php

namespace Tests\Feature;

use App\Classes\Price;
use App\Classes\Settings;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Adversarial review of PR #50 (KR-6): Settings::tax() memoised per user id in a scoped binding.
 * Failing tests prove a problem; test_holds_* are edge cases that hold.
 */
class SettingsTaxRateAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private function customer(?string $country): User
    {
        $user = User::factory()->create();
        if ($country !== null) {
            $user->client()->properties()->create(['key' => 'country', 'name' => 'Country', 'value' => $country]);
        }

        return $user;
    }

    private function rates(): void
    {
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        TaxRate::create(['name' => 'MwSt', 'rate' => 19, 'country' => 'DE']);
    }

    /**
     * ADV-1. Same request: the customer's country changes (profile save, admin edit, a checkout step that sets the
     * country), then the invoice is created. The snapshot freezes the rate of the country BEFORE the change, because
     * the memo is keyed only by the user id. once() recomputed for a fresh User object (different object id); the
     * per-id memo does not, so this is a (narrow) regression for code that reloads the user.
     */
    public function test_country_changed_in_the_same_request_is_seen_by_the_invoice_snapshot(): void
    {
        $this->rates();
        $user = $this->customer('GR');
        $this->assertEquals(24, Settings::tax($user)->rate); // e.g. a product page / cart price earlier in the request

        $user->client()->properties()->where('key', 'country')->update(['value' => 'DE']);

        $fresh = User::findOrFail($user->id);
        $invoice = Invoice::create([
            'client_id' => $fresh->client()->id,
            'currency_code' => 'USD',
            'due_at' => now()->addDays(7),
            'status' => Invoice::STATUS_UNPAID,
        ]);

        $this->assertEquals(19, (float) $invoice->fresh()->snapshot->tax_rate, 'snapshot keeps the old country\'s VAT');
    }

    /**
     * ADV-2. An unsaved User (getKey() null) is keyed 'guest'. If it carries a country (relation set in memory, e.g.
     * a preview/quote for a not-yet-created customer), it shares the guest entry: it gets the guest rate, or,
     * computed first, it poisons every real guest's prices for the rest of the request.
     */
    public function test_unsaved_user_with_a_country_does_not_share_the_guest_entry(): void
    {
        $this->rates();
        TaxRate::create(['name' => 'VAT', 'rate' => 20, 'country' => 'all']);

        $unsaved = new User;
        $unsaved->setRelation('properties', collect([new Property(['key' => 'country', 'value' => 'DE'])]));

        $this->assertEquals(19, Settings::tax($unsaved)->rate);
        Auth::logout();
        $this->assertEquals(20, Settings::tax()->rate, 'a real guest got the unsaved DE user\'s rate');
    }

    public function test_holds_guest_then_login_in_the_same_request_get_their_own_rates(): void
    {
        $this->rates();
        TaxRate::create(['name' => 'VAT', 'rate' => 20, 'country' => 'all']);
        $de = $this->customer('DE');
        $gr = $this->customer('GR');

        $this->assertEquals(20, Settings::tax()->rate); // guest
        Auth::login($de);
        $this->assertEquals(19, Settings::tax()->rate);
        Auth::login($gr); // impersonation / switching user
        $this->assertEquals(24, Settings::tax()->rate);
        Auth::logout();
        $this->assertEquals(20, Settings::tax()->rate);
        // Explicit user wins over Auth::user()
        Auth::login($gr);
        $this->assertEquals(19, Settings::tax($de)->rate);
    }

    public function test_holds_all_rate_fallback_and_specific_country_wins(): void
    {
        TaxRate::create(['name' => 'VAT', 'rate' => 20, 'country' => 'all']);
        TaxRate::create(['name' => 'MwSt', 'rate' => 19, 'country' => 'DE']);

        $this->assertEquals(19, Settings::tax($this->customer('DE'))->rate);
        $this->assertEquals(20, Settings::tax($this->customer('US'))->rate);
        $this->assertEquals(20, Settings::tax($this->customer(null))->rate);
    }

    public function test_holds_zero_is_memoised_and_returned_as_zero(): void
    {
        $user = $this->customer('US');
        $this->assertSame(0, Settings::tax($user));
        TaxRate::create(['name' => 'VAT', 'rate' => 20, 'country' => 'all']);
        // memoised within the request (documented behaviour), still the int 0, not null/false
        $this->assertSame(0, Settings::tax($user));
        $this->app->forgetScopedInstances();
        $this->assertEquals(20, Settings::tax($user)->rate);
    }

    public function test_holds_tax_disabled_adds_no_tax(): void
    {
        $this->rates();
        config(['settings.tax_enabled' => false, 'settings.tax_type' => 'exclusive']);
        Auth::login($this->customer('GR'));
        $price = new Price(['price' => 100, 'currency' => (object) ['code' => 'USD', 'prefix' => '$', 'suffix' => '', 'format' => '1.000,00']], apply_exclusive_tax: true);
        $this->assertEquals(100, (float) $price->price);
        $this->assertEquals(0, (float) $price->tax);
    }

    public function test_holds_exclusive_price_uses_each_customers_rate_in_a_loop(): void
    {
        $this->rates();
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        $ids = [$this->customer('GR')->id => 124.0, $this->customer('DE')->id => 119.0, $this->customer('GR')->id => 124.0];
        $got = [];
        foreach (User::query()->whereIn('id', array_keys($ids))->orderBy('id')->cursor() as $u) {
            $got[$u->id] = (float) (new Price(['price' => 100, 'currency' => (object) ['code' => 'USD', 'prefix' => '$', 'suffix' => '', 'format' => '1.000,00']], apply_exclusive_tax: true, tax: Settings::tax($u)))->price;
        }
        $this->assertEquals($ids, $got);
    }

    public function test_holds_snapshots_in_a_loop_get_each_customers_rate(): void
    {
        $this->rates();
        $expected = [];
        foreach (['GR', 'DE', 'GR', 'DE'] as $c) {
            $u = $this->customer($c);
            $inv = Invoice::create(['client_id' => $u->client()->id, 'currency_code' => 'USD', 'due_at' => now(), 'status' => Invoice::STATUS_UNPAID]);
            $expected[$inv->id] = $c === 'GR' ? 24.0 : 19.0;
            unset($u, $inv);
        }
        $got = Invoice::with('snapshot')->get()->mapWithKeys(fn ($i) => [$i->id => (float) $i->snapshot->tax_rate])->all();
        $this->assertEquals($expected, $got);
    }

    /** The real database queue worker (daemon loop) resets the scoped memo before each job. */
    public function test_holds_queue_worker_resets_the_memo_between_jobs(): void
    {
        config(['queue.default' => 'database']);
        $rate = TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        $user = $this->customer('GR');
        TaxProbeJob::$seen = [];

        TaxProbeJob::dispatch($user->id, $rate->id, 13);
        TaxProbeJob::dispatch($user->id, null, null);
        // --memory: the whole test process counts; past the 128MB default the worker stops with exit code 12.
        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--memory' => 4096])->assertExitCode(0);

        $this->assertEquals([24.0, 13.0], TaxProbeJob::$seen);
    }

    /**
     * Info: a single console process (the cron run) is never reset, so a rate edited mid-run is seen only by the next
     * run. Bounded by one run; documents the behaviour rather than a defect.
     */
    public function test_holds_one_cron_process_keeps_the_rate_for_the_run(): void
    {
        $rate = TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        $user = $this->customer('GR');
        $this->assertEquals(24, Settings::tax($user)->rate);
        $rate->update(['rate' => 13]);
        $this->assertEquals(24, Settings::tax(User::find($user->id))->rate);
    }
}

class TaxProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var list<float> */
    public static array $seen = [];

    public function __construct(public int $userId, public ?int $rateId, public ?int $newRate) {}

    public function handle(): void
    {
        self::$seen[] = (float) Settings::tax(User::findOrFail($this->userId))->rate;
        if ($this->rateId) {
            TaxRate::whereKey($this->rateId)->update(['rate' => $this->newRate]);
        }
    }
}
