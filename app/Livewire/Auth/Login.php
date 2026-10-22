<?php

namespace App\Livewire\Auth;

use App\Livewire\Component;
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

    public function submit(\App\Actions\Auth\Login $loginAction)
    {
        $this->captcha();
        $this->validate();

        // By the address as it reaches us (an IPv6 one by its /64), never one it names in X-Forwarded-For; and the login
        // the database finds, across every address, by its id: its collation matches more spellings of an email than
        // any normalising (AUTHN-2, review of #333). Only failures count towards the login's own limit.
        $ip = CallerIp::key();
        $user = User::where('email', trim($this->email))->first();
        // The address's own count is of failures only (below): many customers behind one office's address sign in.
        $keys = ['login:' . ($user->id ?? 'none') . ':' . $ip => [5, 900]];
        if (RateLimiter::tooManyAttempts('login-ip:' . $ip, 30)) {
            $this->addError('email', __('auth.throttle', ['seconds' => RateLimiter::availableIn('login-ip:' . $ip)]));

            return;
        }
        foreach ($keys as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $this->addError('email', __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]));

                return;
            }
        }
        $account = $user === null ? null : 'login-account:' . $user->id;
        if ($account !== null && RateLimiter::tooManyAttempts($account, 20)) {
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
            RateLimiter::increment('login-ip:' . $ip, 900);
            if ($account !== null) {
                RateLimiter::increment($account, 900);
            }
            $this->addError('email', __('auth.failed'));

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
        return $this->redirect(session()->pull('url.intended', default: route('dashboard')));
    }

    public function render()
    {
        return view('auth.login');
    }
}
