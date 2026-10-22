<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;

/**
 * A customer's code from the authenticator app (security audit AUTHN-9): accepted once only, and never one of the same
 * time step or an earlier one again (a code seen over the shoulder, phished, or sent twice at once), however long the
 * library's ±30 s window keeps it valid. Wrong codes count towards one limit per login, wherever they are typed.
 */
final class Totp
{
    /** Wrong codes of one login, at the login and anywhere else, before it waits (AUTH-20). */
    public const MAX_WRONG = 5;

    public const LOCKOUT_SECONDS = 900;

    public static function limitKey(User $user): string
    {
        return '2fa:' . $user->id;
    }

    public static function tooManyWrong(User $user): bool
    {
        return RateLimiter::tooManyAttempts(self::limitKey($user), self::MAX_WRONG);
    }

    /** Whether $code is this login's, for its current secret, and not used before. A wrong one counts. */
    public static function accept(User $user, ?string $secret, string $code): bool
    {
        $code = (string) preg_replace('/\s+/', '', $code);
        // Counted before it is checked (parallel requests never slip more past the limit), cleared when it is right.
        RateLimiter::hit(self::limitKey($user), self::LOCKOUT_SECONDS);
        $step = 0;
        if (!is_string($secret) || $secret === '' || !(new TwoFactorAuth(new EndroidQrCodeProvider, config('app.name')))->verifyCode($secret, $code, 1, null, $step)) {
            return false;
        }

        // Under a lock: two requests with the same code at once, one is accepted. A lock not had in time refuses.
        try {
            return (bool) Cache::lock('totp-step-lock:' . $user->id, 5)->block(3, fn () => self::claim($user, $step));
        } catch (LockTimeoutException) {
            return false;
        }
    }

    private static function claim(User $user, int $step): bool
    {
        // Redis gives a stored number back as a string (review of #360).
        $last = Cache::get('totp-step:' . $user->id);
        if (is_numeric($last) && $step <= (int) $last) {
            return false;
        }
        Cache::put('totp-step:' . $user->id, $step, now()->addMinutes(10));
        RateLimiter::clear(self::limitKey($user));

        return true;
    }
}
