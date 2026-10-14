<?php

namespace App\Livewire\Auth;

use App\Livewire\Component;

class Logout extends Component
{
    public function logout(\App\Actions\Auth\Logout $logoutAction)
    {
        // Staff logged in as a client (AUTH-19) leave the client's view, back to the admin, still logged in there.
        if (session()->has('impersonating')) {
            session()->forget('impersonating');

            return redirect('/admin');
        }

        $logoutAction->execute();

        return redirect('/');
    }

    public function render()
    {
        return view('auth.logout');
    }
}
