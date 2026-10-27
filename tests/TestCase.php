<?php

namespace Tests;

use App\Console\Commands\CronJob;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserSession;
use App\Providers\SettingsProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Once;
use Livewire\Features\SupportDisablingBackButtonCache\SupportDisablingBackButtonCache;
use Livewire\Livewire;
use Livewire\LivewireManager;
use LogicException;
use Random\Engine\Mt19937;
use Random\Randomizer;

abstract class TestCase extends BaseTestCase
{
    protected bool $seed = true;

    /** A DatabaseTruncation test ran in this process and left its rows committed (setUpTraits). */
    private static bool $truncatedRowsLeft = false;

    /**
     * A DatabaseTruncation test truncates before it runs, not after: its rows stay committed, and on MariaDB (where those
     * tests run) every RefreshDatabase test after it in the same process ran on top of them, inside its transaction. The
     * cron of a later test then billed, suspended and mailed those services too (BillingCursorFuzzTest drew other numbers
     * and ran another simulation, CI of #482). The first RefreshDatabase test after one migrates afresh instead.
     */
    protected function setUpTraits()
    {
        $uses = class_uses_recursive(static::class);
        if (self::$truncatedRowsLeft && isset($uses[RefreshDatabase::class])) {
            RefreshDatabaseState::$migrated = false;
            self::$truncatedRowsLeft = false;
        }
        if (isset($uses[DatabaseTruncation::class])) {
            self::$truncatedRowsLeft = true;
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A daily cron run that finds another one waits up to 10 minutes for it: never in a test (it holds the lock itself).
        CronJob::$lockWait = 0;

        // Livewire marks «a component rendered» in a static that only its own test helper resets: a later plain HTTP test
        // in the same process got Livewire's no-cache headers instead of its own (AltchaCaptchaTest, 2026-10-24).
        SupportDisablingBackButtonCache::$disableBackButtonCache = false;

        // The seeder runs code that memoises with once() (e.g. the settings' currency list, read before the
        // seeder creates USD): start every test from what the seeded database says.
        Once::flush();

        // The settings were read when the app booted, before RefreshDatabase seeded: from the base database, which a
        // parallel run never migrates (each process switches to its own after the boot), or from whatever a serial run
        // left there. Read them from the database this test sees.
        SettingsProvider::reload();

        // The tickets' folder, made once here and quietly: the parallel processes' tests made it at the same moment, and
        // the loser's mkdir() («File exists») failed its test (CI, #323).
        if (!is_dir(storage_path('app/tickets/uploads'))) {
            @mkdir(storage_path('app/tickets/uploads'), 0755, true);
        }
    }

    /**
     * Settings as rows, read back into config. A config() does not last past a saved setting: its flush
     * (FlushCacheListener) reloads the settings from the database when the test's next request terminates.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function storeSettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            // The type as the value's (the seeder's database_type), so it reads back as given: Saving encodes it.
            $type = match (true) {
                is_array($value) => 'array', is_bool($value) => 'boolean', is_int($value) => 'integer',
                is_float($value) => 'float', default => 'string',
            };
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $type]);
        }
        SettingsProvider::reload();
    }

    /** The seeded simulations' own generator (seedRandom). */
    private ?Randomizer $random = null;

    /**
     * Seeds the simulation's own generator: the same seed replays the same run. Not mt_srand(): the global generator is
     * shared with the code under test and its libraries (Faker in every factory, dompdf for each PDF it renders, one
     * mt_rand() and four rand()), so a run that rendered one more PDF, or created a row one more time, drew different
     * numbers from then on (BillingCursorFuzzTest, CI of #482: a seed 1 that never ran locally).
     */
    protected function seedRandom(int $seed): void
    {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    /** A number in [$min, $max] from the simulation's generator (seedRandom first). */
    protected function rand(int $min, int $max): int
    {
        return ($this->random ?? throw new LogicException('seedRandom() first'))->getInt($min, $max);
    }

    /** $items in an order from the simulation's generator: shuffle() draws from the global one. */
    protected function shuffled(array $items): array
    {
        return ($this->random ?? throw new LogicException('seedRandom() first'))->shuffleArray($items);
    }

    /**
     * One of $items, picked with the simulation's generator: Collection::random() uses a CSPRNG that no seed replays.
     */
    protected function pickOne(Collection $items): mixed
    {
        return $items->values()->get($this->rand(0, $items->count() - 1));
    }

    //
    protected function createProduct(array $attributes = [])
    {

        // Create product + plan + price
        $product = Product::factory()->create(array_merge([
            'name' => 'Test Product',
            'description' => 'This is a test product.',
        ], $attributes));

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Test Product',
            'description' => 'This is a test product.',
        ]);

        $plan = Plan::factory()->create([
            'priceable_id' => $product->id,
            'priceable_type' => Product::class,
            'name' => 'Test Plan',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'name' => 'Test Plan',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);

        Price::factory()->create([
            'plan_id' => $plan->id,
            'price' => 10.00,
            'currency_code' => 'USD',
        ]);

        $this->assertDatabaseHas('prices', [
            'plan_id' => $plan->id,
            'price' => 10.00,
            'currency_code' => 'USD',
        ]);

        return (object) [
            'product' => $product,
            'plan' => $plan,
        ];
    }

    /** Livewire::actingAs() with the guard of who acts: staff on theirs, customers on the default one. */
    protected function livewireAs(Authenticatable $user): LivewireManager
    {
        return Livewire::actingAs($user, $user instanceof Staff ? 'staff' : null);
    }

    /** Staff act on their own guard (docs/accounts.md, PR 1), customers on theirs, whatever the default is now. */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        return parent::actingAs($user, $guard ?? ($user instanceof Staff ? 'staff' : 'web'));
    }

    /**
     * Helper to create a user session and set it in the session. Staff need none: the admin panel uses the plain
     * session guard, not the customers' user_sessions.
     */
    protected function loginUser(User|Staff $user, array $sessionData = []): array
    {
        if ($user instanceof Staff) {
            return $sessionData;
        }
        $userSession = UserSession::create([
            'user_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => substr(request()->userAgent() ?? '', 0, 512),
            'last_activity' => now(),
            'expires_at' => null,
        ]);

        return array_merge([
            'user_session' => $userSession->ulid,
        ], $sessionData);
    }
}
