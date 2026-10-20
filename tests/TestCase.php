<?php

namespace Tests;

use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserSession;
use App\Providers\SettingsProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Livewire\LivewireManager;

abstract class TestCase extends BaseTestCase
{
    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        // The seeder runs code that memoises with once() (e.g. the settings' currency list, read before the
        // seeder creates USD): start every test from what the seeded database says.
        Once::flush();

        // The settings were read when the app booted, before RefreshDatabase seeded: from the base database, which a
        // parallel run never migrates (each process switches to its own after the boot), or from whatever a serial run
        // left there. Read them from the database this test sees.
        SettingsProvider::reload();
    }

    /**
     * One of $items, picked with mt_rand (seeded simulations): Collection::random() uses Random\Randomizer, a CSPRNG
     * that mt_srand() does not seed, so a seed did not replay the same run.
     */
    protected function pickOne(Collection $items): mixed
    {
        return $items->values()->get(mt_rand(0, $items->count() - 1));
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
