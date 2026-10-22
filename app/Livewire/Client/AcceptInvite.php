<?php

namespace App\Livewire\Client;

use App\Exceptions\DisplayException;
use App\Livewire\Component;
use App\Models\ClientInvite;
use App\Models\User;
use App\Support\Actor;
use App\Support\CurrentClient;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
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

    /** The session's key for an invitation being accepted after a login (kept encrypted, never the raw link: #334). */
    public const SESSION_KEY = 'invite.pending';

    public function mount(?string $token = null): void
    {
        if ($token === null) {
            // Back from logging in (/invite, no secret in the URL): the invitation kept for it.
            try {
                $token = Crypt::decryptString((string) session(self::SESSION_KEY));
            } catch (DecryptException) {
                $token = '';
            }
        }
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
        $this->keepForLater();
        $this->redirect(route('login'), true);
    }

    public function register(): void
    {
        $this->keepForLater();
        $this->redirect(route('register'), true);
    }

    /** Back here after logging in, to /invite: the link's token kept encrypted in the session, not as a URL in clear. */
    private function keepForLater(): void
    {
        session()->put(self::SESSION_KEY, Crypt::encryptString($this->token));
        session()->put('url.intended', route('invites.continue'));
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

        session()->forget(self::SESSION_KEY);
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
