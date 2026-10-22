<?php

namespace App\Livewire\Auth\Password;

use App\Helpers\NotificationHelper;
use App\Livewire\Component;
use App\Models\User;
use App\Support\CallerIp;
use App\Traits\Captchable;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

class Request extends Component
{
    use Captchable;

    public string $email = '';

    public function submit()
    {
        $this->captcha();

        $this->validate([
            'email' => 'required|email',
        ]);

        $rateLimitKey = 'password-reset:' . CallerIp::key(); // the caller's own address (AUTHN-2)

        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $this->addError('email', 'Too many password reset attempts. Please try again later.');

            return;
        }

        RateLimiter::hit($rateLimitKey, 60);

        // Find the user
        $user = User::where('email', $this->email)->first();

        // Per account too (AUTHN-12): from any number of addresses, one mail a minute (the broker's own throttle) and
        // three an hour. The answer is the same either way.
        $perAccount = $user === null ? null : 'password-reset-account:' . $user->id;
        if ($user && !Password::broker()->getRepository()->recentlyCreatedToken($user)
            && !RateLimiter::tooManyAttempts((string) $perAccount, 3)) {
            RateLimiter::hit((string) $perAccount, 3600);
            NotificationHelper::passwordResetNotification($user, ['url' => url(route('password.reset', [
                'token' => Password::createToken($user),
                'email' => $user->email,
            ], false))]);
        }

        $this->notify(__('If the email address is associated with an account, you will receive an email with instructions on how to reset your password.'), 'success');
    }

    public function render()
    {
        return view('auth.password.request');
    }
}
