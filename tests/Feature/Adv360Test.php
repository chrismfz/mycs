<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;
use Tests\TestCase;

/**
 * Adversarial review of #360.
 */
class Adv360Test extends TestCase
{
    use RefreshDatabase;

    /**
     * Laravel's RedisStore keeps numeric values unserialized and hands them back as strings (RedisStore::serialize /
     * unserialize: `is_numeric($value) ? $value : unserialize(...)`). `.env.example` ships CACHE_STORE=redis. With such a
     * store `Cache::get('totp-step:<id>')` is "58... " (a string), `is_int($last)` is false, and the same code is
     * accepted again: the replay protection is gone. The store below reproduces exactly that read behaviour.
     */
    public function test_a_code_is_accepted_once_on_a_store_that_returns_numbers_as_strings(): void
    {
        Cache::extend('numeric-as-string', fn ($app) => Cache::repository(new class extends ArrayStore
        {
            public function get($key)
            {
                $value = parent::get($key);

                return is_int($value) ? (string) $value : $value; // as RedisStore::unserialize returns a numeric value
            }
        }));
        config(['cache.stores.numeric-as-string' => ['driver' => 'numeric-as-string']]);
        config(['cache.default' => 'numeric-as-string']);
        Cache::forgetDriver('numeric-as-string');

        $tfa = new TwoFactorAuth(new EndroidQrCodeProvider, config('app.name'));
        $secret = $tfa->createSecret();
        $user = User::factory()->create(['tfa_secret' => $secret]);
        $code = $tfa->getCode($secret);

        $this->assertTrue(Totp::accept($user, $secret, $code));
        $this->assertFalse(Totp::accept($user, $secret, $code), 'the same code replayed: Redis returns the stored step as a string');
    }
}
