<?php

namespace App\Livewire\Auth;

use App\Livewire\Component;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use App\Support\CallerIp;
use App\Traits\Captchable;
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

    public function submit(\App\Actions\Auth\Login $loginAction)
    {
        $this->captcha();
        $this->validate();

        // By the address as it reaches us (an IPv6 one by its /64), and by the login across every address: a forged
        // X-Forwarded-For or a new address per try gets no fresh count (AUTHN-2). The email as the database matches it
        // (accents and case aside).
        $emailKey = Str::lower(Str::ascii(trim($this->email)));
        $keys = ['login:' . $emailKey . ':' . CallerIp::key() => 5, 'login-account:' . $emailKey => 20];
        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $this->addError('email', __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]));

                return;
            }
        }
        foreach (array_keys($keys) as $key) {
            RateLimiter::increment($key, 900);
        }

        // Manually validate credentials instead of Auth::attempt
        $user = User::where('email', $this->email)->first();

        try {
            $valid = $user !== null && Hash::check($this->password, $user->password);
        } catch (\RuntimeException) {
            $valid = false; // a hash in an old format (imported): «Forgot password» sets a new one
        }
        if (!$valid) {
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

        RateLimiter::clear('login:' . $emailKey . ':' . CallerIp::key());

        $loginAction->execute($user, $this->remember);

        $intendedUrl = session()->pull('url.intended', default: route('dashboard'));
        $isAdminRoute = str_starts_with($intendedUrl, url('/' . AdminPanelProvider::PATH . '/'));

        // Redirect normally if it is an admin route, otherwise navigate using livewire
        return $this->redirect($intendedUrl, navigate: !$isAdminRoute);
    }

    public function render()
    {
        return view('auth.login');
    }
}
