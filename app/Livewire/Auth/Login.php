<?php

namespace App\Livewire\Auth;

use App\Livewire\Component;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Support\CallerIp;
use App\Traits\Captchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Attributes\Validate;

class Login extends Component
{
    use Captchable;

    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required')]
    public string $password = '';

    public bool $remember = false;

    /** A hash of nothing anyone knows, made once with the current cost. */
    private static function unknownLoginHash(): string
    {
        return Cache::rememberForever('login-unknown-hash:' . config('hashing.driver'), fn () => Hash::make(Str::random(40)));
    }

    /**
     * The captcha on sign-in (setting «Captcha on sign-in», owner 2026-11-06): always, never, or by default only once the
     * address has failed to sign in (its failures, 15 minutes) or has a try under way: a customer typing the right
     * password never sees it.
     */
    public function captchaNeeded(): bool
    {
        return match (self::captchaMode()) {
            'always' => true,
            'never' => false,
            default => RateLimiter::attempts('login-ip:' . CallerIp::key()) > 0 || Cache::has(self::freeTryKey()),
        };
    }

    private static function captchaMode(): string
    {
        $mode = (string) config('settings.captcha_login', 'after_failure');
        if (in_array($mode, ['always', 'never'], true)) {
            return $mode;
        }

        // Only ours waits hidden without doing anything: a provider's (reCAPTCHA, Turnstile, hCaptcha) runs and is
        // checked at its provider while hidden, so sign-in would hang on it anyway: asked always (re-review of #684).
        return config('settings.captcha') === 'altcha' ? 'after_failure' : 'always';
    }

    /**
     * The address's one try without the captcha: taken atomically (a file lock), so many requests sent at once get one
     * between them, never one each (review of #684: the failures are counted only after the password is checked).
     */
    private static function freeTryKey(): string
    {
        return 'login-free-try:' . CallerIp::key();
    }

    /** Whether this sign-in must have its captcha solved: the free try taken here, if it is this one's. */
    private function captchaRequiredForThisTry(): bool
    {
        return match (self::captchaMode()) {
            'always' => true,
            'never' => false,
            default => RateLimiter::attempts('login-ip:' . CallerIp::key()) > 0 || !Cache::add(self::freeTryKey(), 1, 900),
        };
    }

    public function submit(\App\Actions\Auth\Login $loginAction)
    {
        if ($this->captchaRequiredForThisTry()) {
            $this->captcha();
        }
        $this->validate();

        // By the address as it reaches us (an IPv6 one by its /64), never one it names in X-Forwarded-For; and the login
        // the database finds, across every address, by its id: its collation matches more spellings of an email than
        // any normalising (AUTHN-2, review of #333). Only failures count towards the login's own limit.
        $ip = CallerIp::key();
        $user = User::where('email', trim($this->email))->first();
        // The address's own count is of failures only (below): many customers behind one office's address sign in.
        $keys = ['login:' . ($user->id ?? 'none') . ':' . $ip => [5, 900]];
        if (RateLimiter::tooManyAttempts('login-ip:' . $ip, 30)) {
            LoginAttempt::record(LoginAttempt::CLIENT, $user, $this->email, LoginAttempt::THROTTLED);
            $this->addError('email', __('auth.throttle', ['seconds' => RateLimiter::availableIn('login-ip:' . $ip)]));

            return;
        }
        foreach ($keys as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                LoginAttempt::record(LoginAttempt::CLIENT, $user, $this->email, LoginAttempt::THROTTLED);
                $this->addError('email', __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]));

                return;
            }
        }
        $account = $user === null ? null : 'login-account:' . $user->id;
        if ($account !== null && RateLimiter::tooManyAttempts($account, 20)) {
            LoginAttempt::record(LoginAttempt::CLIENT, $user, $this->email, LoginAttempt::THROTTLED);
            $this->addError('email', __('auth.throttle', ['seconds' => RateLimiter::availableIn($account)]));

            return;
        }
        foreach ($keys as $key => [, $decay]) {
            RateLimiter::increment($key, $decay);
        }

        try {
            // An unknown email costs the same hash check: the time taken never tells whether it has an account (G9).
            $valid = Hash::check($this->password, $user === null ? self::unknownLoginHash() : (string) $user->password) && $user !== null;
        } catch (\RuntimeException) {
            $valid = false; // a hash in an old format (imported): «Forgot password» sets a new one
        }
        if (!$valid) {
            LoginAttempt::record(LoginAttempt::CLIENT, $user, $this->email, $user === null ? LoginAttempt::UNKNOWN : LoginAttempt::FAILED);
            RateLimiter::increment('login-ip:' . $ip, 900);
            if ($account !== null) {
                RateLimiter::increment($account, 900);
            }
            $this->addError('email', __('auth.failed'));

            return;
        }

        // The password was right: the next sign-in from here is free again (with 2FA, or closed, too).
        Cache::forget(self::freeTryKey());

        // Its every account closed (WG-8): told so, after the password, as WHMCS does.
        if ($user->closedOut()) {
            LoginAttempt::record(LoginAttempt::CLIENT, $user, $this->email, LoginAttempt::FAILED);
            $this->addError('email', __('auth.closed'));

            return;
        }

        // Check 2FA
        if ($user->tfa_secret) {
            Session::put('2fa', [
                'user_id' => $user->id,
                'remember' => $this->remember,
                'expires' => now()->addMinutes(5),
            ]);

            return $this->redirect(route('2fa'), true);
        }

        RateLimiter::clear('login:' . $user->id . ':' . $ip);

        $loginAction->execute($user, $this->remember);

        // A full page load, never a Livewire navigation: the login page's scripts (the staff's analytics) would keep
        // running on the next page, a private link's too (review of #334).
        return $this->redirect(\App\Actions\Auth\Login::intended(route('dashboard')));
    }

    public function render()
    {
        return view('auth.login');
    }
}
