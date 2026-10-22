<?php

namespace App\Admin\Auth;

use App\Support\CallerIp;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as FilamentLogin;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The staff login, counted by the caller's own address (an IPv6 one by its /64), never one a client names in
 * X-Forwarded-For, and by the login across every address (security audit AUTHN-2): the keys to every server sit behind it.
 */
class Login extends FilamentLogin
{
    protected function getRateLimitKey($method, $component = null): string
    {
        return 'livewire-rate-limiter:' . sha1(($component ?? static::class) . '|' . $method . '|' . CallerIp::key());
    }

    public function authenticate(): ?LoginResponse
    {
        $key = 'staff-login-account:' . Str::lower(Str::ascii(trim((string) ($this->data['email'] ?? ''))));
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->getRateLimitedNotification(new TooManyRequestsException(static::class, 'authenticate', CallerIp::of(request()), RateLimiter::availableIn($key)))?->send();

            return null;
        }
        RateLimiter::hit($key, 900);

        $response = parent::authenticate();
        if ($response !== null) {
            RateLimiter::clear($key);
        }

        return $response;
    }
}
