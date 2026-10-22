<?php

namespace App\Livewire\Auth;

use App\Actions\Auth\Login;
use App\Livewire\Component;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;

class Tfa extends Component
{
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

        $user = User::findOrfail($session['user_id']);

        if (!$user->tfa_secret) {
            return $this->redirect(route('login'), true);
        }

        // Back-off (AUTH-20): 5 wrong codes lock the account's 2FA for 15 minutes, here and when turning it off; a code
        // is accepted once, never again in its time step (AUTHN-9). Staff have Filament's own on their guard.
        if (Totp::tooManyWrong($user)) {
            $this->addError('code', __('Too many attempts. Try again in :minutes minutes.', [
                'minutes' => (int) ceil(RateLimiter::availableIn(Totp::limitKey($user)) / 60),
            ]));

            return;
        }
        if (!Totp::accept($user, $user->tfa_secret, (string) $this->code)) {
            return $this->addError('code', 'Invalid code.');
        }

        $loginAction->execute($user, $session['remember']);

        Session::forget('2fa');

        // Back where the login started from (an invitation's link, PR 2b), as Login does.
        return $this->redirect(session()->pull('url.intended', route('dashboard'))); // a full load, as Login (#334)
    }

    public function render()
    {
        return view('auth.2fa');
    }
}
