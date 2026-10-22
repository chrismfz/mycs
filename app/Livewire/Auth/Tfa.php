<?php

namespace App\Livewire\Auth;

use App\Actions\Auth\Login;
use App\Livewire\Component;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;

class Tfa extends Component
{
    private const LOCKOUT_SECONDS = 900;

    public $code;

    public function mount()
    {
        $session = Session::get('2fa');
        if (!$session || $session['expires'] < now()) {
            return $this->redirect(route('login'), true);
        }
    }

    public function verify(Login $loginAction)
    {
        $this->validate([
            'code' => 'required|numeric',
        ]);

        $session = Session::get('2fa');

        if (!$session || $session['expires'] < now()) {
            Session::forget('2fa');

            return $this->redirect(route('login'), true);
        }

        // Back-off (AUTH-20): 5 wrong codes lock the account's 2FA for 15 minutes, not for a minute (which let anyone
        // try 5 codes a minute for ever). Staff have Filament's own limit on their guard.
        if (RateLimiter::tooManyAttempts('2fa:' . $session['user_id'], 5)) {
            $this->addError('code', __('Too many attempts. Try again in :minutes minutes.', [
                'minutes' => (int) ceil(RateLimiter::availableIn('2fa:' . $session['user_id']) / 60),
            ]));

            return;
        }

        RateLimiter::increment('2fa:' . $session['user_id'], self::LOCKOUT_SECONDS);

        $user = User::findOrfail($session['user_id']);

        if (!$user->tfa_secret) {
            return $this->redirect(route('login'), true);
        }

        $tfa = new TwoFactorAuth(new EndroidQrCodeProvider, config('app.name'));
        if (!$tfa->verifyCode($user->tfa_secret, $this->code)) {
            return $this->addError('code', 'Invalid code.');
        }

        // Check if code has been used before, preventing replay attacks
        if (Cache::has('tfa_used_code_' . $user->id . '_' . $this->code)) {
            return $this->addError('code', 'Invalid code.');
        }
        // Mark code as used for 60 seconds just in case
        Cache::put('tfa_used_code_' . $user->id . '_' . $this->code, true, 60);

        $loginAction->execute($user, $session['remember']);

        Session::forget('2fa');

        RateLimiter::clear('2fa:' . $session['user_id']);

        // Back where the login started from (an invitation's link, PR 2b), as Login does.
        return $this->redirect(session()->pull('url.intended', route('dashboard'))); // a full load, as Login (#334)
    }

    public function render()
    {
        return view('auth.2fa');
    }
}
