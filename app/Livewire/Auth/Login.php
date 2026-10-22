<?php

namespace App\Livewire\Auth;

use App\Livewire\Component;
use App\Models\User;
use App\Traits\Captchable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
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

        $emailKey = strtolower($this->email);

        if (RateLimiter::tooManyAttempts('login:' . $emailKey . ':' . request()->ip(), 5)) {
            $this->addError('email', __('auth.throttle', [
                'seconds' => RateLimiter::availableIn('login:' . $emailKey . ':' . request()->ip()),
            ]));

            return;
        }

        RateLimiter::increment('login:' . $emailKey . ':' . request()->ip());

        // Manually validate credentials instead of Auth::attempt
        $user = User::where('email', $this->email)->first();

        if (!$user || !Hash::check($this->password, $user->password)) {
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

        RateLimiter::clear('login:' . $emailKey . ':' . request()->ip());

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
