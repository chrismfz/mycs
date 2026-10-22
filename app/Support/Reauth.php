<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * The login's password asked again before a change that would hand the account over (its email, its password, its
 * two-factor authentication): a few tries per quarter-hour per login, so a stolen session cannot guess it (review of
 * #332), and a hash in an old format (imported from WHMCS) is a wrong password, not an error.
 */
final class Reauth
{
    public const TRIES = 5;

    public const DECAY_SECONDS = 900;

    /** Null when it is the login's password; else what to tell the customer. */
    public static function refusal(User $user, string $password): ?string
    {
        $key = 'reauth:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, self::TRIES)) {
            return __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]);
        }
        try {
            $right = $password !== '' && Hash::check($password, (string) $user->password);
        } catch (RuntimeException) {
            $right = false; // an old hash format: «Forgot password» sets a new one
        }
        if (!$right) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return __('account.notifications.password_incorrect');
        }

        return null;
    }
}
