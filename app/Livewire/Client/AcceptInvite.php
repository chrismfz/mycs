<?php

namespace App\Livewire\Client;

use App\Exceptions\DisplayException;
use App\Livewire\Component;
use App\Models\ClientInvite;
use App\Models\User;
use App\Support\Actor;
use App\Support\CurrentClient;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * Accepting an invitation to an account (docs/accounts.md, PR 2b). The link is the proof: whoever holds it and logs
 * in (or creates a login) gets the access, once, before it expires, as in WHMCS. A guest is sent to log in and comes
 * back here.
 */
class AcceptInvite extends Component
{
    #[Locked]
    public string $token = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    private function invite(): ?ClientInvite
    {
        $invite = ClientInvite::findByToken($this->token);

        return $invite?->isPending() ? $invite : null;
    }

    /** To log in (or create a login), then back here: the invitation's link is where they return. */
    public function login(): void
    {
        session()->put('url.intended', route('invites.accept', ['token' => $this->token]));
        $this->redirect(route('login'), true);
    }

    public function register(): void
    {
        session()->put('url.intended', route('invites.accept', ['token' => $this->token]));
        $this->redirect(route('register'), true);
    }

    public function accept(): void
    {
        $user = Auth::guard('web')->user();
        $invite = $this->invite();
        if (!$user instanceof User || Actor::impersonating() || $invite === null) {
            $this->notify(__('account.invite_invalid'), 'error');

            return;
        }
        try {
            $grant = $invite->accept($user);
        } catch (DisplayException) {
            $this->notify(__('account.invite_invalid'), 'error');

            return;
        }
        app(CurrentClient::class)->switchTo((int) $grant->client_id);

        $this->notify(__('account.invite_accepted', ['name' => $invite->client->name]), 'success', true);
        $this->redirect(route('dashboard'), true);
    }

    public function render()
    {
        return view('client.account.accept-invite', [
            'invite' => $this->invite(),
            'loggedIn' => Auth::guard('web')->check(),
        ])->layoutData([
            'title' => __('account.invite_title'),
        ]);
    }
}
