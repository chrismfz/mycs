<?php

namespace App\Admin\Auth;

use App\Models\LoginAttempt;
use App\Models\Staff;
use App\Support\AuthCookies;
use App\Support\CallerIp;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as FilamentLogin;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The staff login, counted by the caller's own address (an IPv6 one by its /64), never one a client names in
 * X-Forwarded-For, and by the member of staff across every address (security audit AUTHN-2): the keys to every server
 * sit behind it. Only failures count towards the member's limit, and a device a member already signed in on is never
 * held back by it: a stranger cannot lock the owner out from the office (review of #333).
 */
class Login extends FilamentLogin
{
    /** Failed logins of one member of staff, from devices it never signed in on, per hour. */
    public const ACCOUNT_FAILURES = 30;

    /** The cookie of a device a member of staff signed in on (encrypted, as every cookie here). */
    public const DEVICE_COOKIE = 'mycs_staff_device';

    protected function getRateLimitKey($method, $component = null): string
    {
        return 'livewire-rate-limiter:' . sha1(($component ?? static::class) . '|' . $method . '|' . CallerIp::key());
    }

    /** Filament's own limit per address: held back is in the history too. */
    protected function rateLimit($maxAttempts, $decaySeconds = 60, $method = null, $component = null): void
    {
        // The limit is per calling method (its key): the caller's, as Filament's would find it.
        $method ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, limit: 2)[1]['function'];
        try {
            parent::rateLimit($maxAttempts, $decaySeconds, $method, $component);
        } catch (TooManyRequestsException $e) {
            $email = (string) ($this->data['email'] ?? '');
            LoginAttempt::record(LoginAttempt::STAFF, Staff::query()->where('email', trim($email))->first(), $email, LoginAttempt::THROTTLED);

            throw $e;
        }
    }

    public function authenticate(): ?LoginResponse
    {
        // The member the database finds (its collation matches more spellings than any normalising: review of #333).
        $staff = Staff::query()->where('email', trim((string) ($this->data['email'] ?? '')))->first();
        $key = $staff === null ? null : 'staff-login-account:' . $staff->id;
        $known = $staff !== null && (string) request()->cookie(AuthCookies::name(self::DEVICE_COOKIE)) === (string) $staff->id;
        if ($key !== null && !$known && RateLimiter::tooManyAttempts($key, self::ACCOUNT_FAILURES)) {
            LoginAttempt::record(LoginAttempt::STAFF, $staff, $staff->email, LoginAttempt::THROTTLED);
            $this->getRateLimitedNotification(new TooManyRequestsException(static::class, 'authenticate', CallerIp::of(request()), RateLimiter::availableIn($key)))?->send();

            return null;
        }

        try {
            $response = parent::authenticate();
        } catch (ValidationException $e) {
            // The password's step fails on the form's fields, the 2FA challenge's on its own form.
            $atPassword = collect(array_keys($e->errors()))
                ->contains(fn (string $field) => str_starts_with($field, 'data.') && !str_starts_with($field, 'data.multiFactor'));
            LoginAttempt::record(LoginAttempt::STAFF, $staff, (string) ($this->data['email'] ?? ''),
                match (true) {
                    $staff === null => LoginAttempt::UNKNOWN,
                    $atPassword => LoginAttempt::FAILED,
                    default => LoginAttempt::FAILED_2FA,
                });
            if ($key !== null) {
                RateLimiter::hit($key, 3600);
            }

            throw $e;
        }
        if ($response !== null && $staff !== null) {
            Cookie::queue(AuthCookies::name(self::DEVICE_COOKIE), (string) $staff->id, 60 * 24 * 90, null, null, null, true, false, 'lax');
        }

        return $response;
    }
}
