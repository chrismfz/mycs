<?php

namespace App\Livewire\Client;

use App\Helpers\NotificationHelper;
use App\Livewire\Component;
use App\Models\Client;
use App\Models\ClientInvite;
use App\Models\ClientUser;
use App\Support\Actor;
use App\Support\CurrentClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * The users of the current account (docs/accounts.md, PR 2b; WHMCS «User Management»): its owner invites logins by
 * email, sets what each may do (the WHMCS permission names) and takes access away. Only the owner, as WHMCS; staff
 * in «Login as client» act as the owner. Making someone else the owner is staff's (admin).
 */
class Users extends Component
{
    public string $email = '';

    public bool $allPermissions = true;

    /** @var array<int, string> */
    public array $permissions = [];

    /** The grant whose permissions are being edited. */
    public ?int $editing = null;

    public bool $editAll = true;

    /** @var array<int, string> */
    public array $editPermissions = [];

    public function mount(): void
    {
        $this->account();
    }

    /** The current account, when the login owns it (or staff act as its owner). */
    private function account(): Client
    {
        $client = CurrentClient::model();
        $user = Auth::user();
        $owns = $client !== null && (Actor::impersonating()
            || $client->grants()->where('user_id', $user?->id)->whereNotNull('owner_of_client_id')->whereNull('revoked_at')->exists());
        abort_unless($owns, 403, __('account.only_owner_manages_users'));

        return $client;
    }

    public function invite(): void
    {
        $client = $this->account();
        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(ClientUser::PERMISSIONS)],
        ]);
        // A few invitations a minute per account: the mail goes to any address.
        $key = 'client-invite:' . $client->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->notify(__('Too many attempts. Please try again later.'), 'error');

            return;
        }
        RateLimiter::hit($key, 60);

        [$invite, $token] = ClientInvite::issue($client, $this->email, $this->allPermissions ? null : $this->permissions, Auth::user());
        NotificationHelper::clientInviteNotification($invite, $token);

        $this->reset('email', 'permissions');
        $this->allPermissions = true;
        $this->notify(__('account.invite_sent'), 'success');
    }

    public function cancelInvite(int $id): void
    {
        $this->account()->invites()->whereKey($id)->pending()->update(['revoked_at' => now()]);
    }

    public function edit(int $grantId): void
    {
        $grant = $this->grant($grantId);
        $this->editing = $grant->id;
        $this->editAll = $grant->permissions === null;
        $this->editPermissions = $grant->permissions ?? [];
    }

    public function savePermissions(): void
    {
        $this->validate([
            'editPermissions' => ['array'],
            'editPermissions.*' => [Rule::in(ClientUser::PERMISSIONS)],
        ]);
        $grant = $this->grant((int) $this->editing);
        $this->account()->grantTo($grant->user, $this->editAll ? null : $this->editPermissions);

        $this->editing = null;
        $this->notify(__('account.permissions_saved'), 'success');
    }

    public function revoke(int $grantId): void
    {
        $this->account()->revoke($this->grant($grantId));
        $this->notify(__('account.access_removed'), 'success');
    }

    /** A live grant of this account that is not the owner's. */
    private function grant(int $grantId): ClientUser
    {
        return $this->account()->grants()->whereKey($grantId)->whereNull('revoked_at')->whereNull('owner_of_client_id')->with('user')->firstOrFail();
    }

    public function render()
    {
        $client = $this->account();

        return view('client.account.users', [
            'grants' => $client->grants()->whereNull('revoked_at')->with('user')->orderByDesc('owner')->orderBy('id')->get(),
            'invites' => $client->invites()->pending()->latest()->get(),
            'names' => ClientUser::PERMISSIONS,
        ])->layoutData([
            'sidebar' => true,
            'title' => __('account.users'),
        ]);
    }
}
