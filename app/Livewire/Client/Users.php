<?php

namespace App\Livewire\Client;

use App\Helpers\NotificationHelper;
use App\Livewire\Component;
use App\Models\Client;
use App\Models\ClientInvite;
use App\Models\ClientUser;
use App\Models\Staff;
use App\Models\User;
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
        $owns = $client !== null && (Actor::staffMayAs('profile') // staff: with the admin permission (SA-5)
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
        // The mail goes to any address: only from a verified login, and a few a minute, an hour and a day per account.
        if (config('settings.mail_must_verify') && !Auth::user()?->hasVerifiedEmail()) {
            $this->notify(__('Please verify your email address first.'), 'error');

            return;
        }
        if ($this->pastTheRole($this->allPermissions ? null : array_values($this->permissions))) {
            return;
        }
        $limits = ['minute' => [5, 60], 'hour' => [20, 3600], 'day' => [50, 86400]];
        foreach ($limits as $window => [$max]) {
            if (RateLimiter::tooManyAttempts("client-invite:{$window}:{$client->id}", $max)) {
                $this->notify(__('Too many attempts. Please try again later.'), 'error');

                return;
            }
        }
        foreach ($limits as $window => [, $decay]) {
            RateLimiter::hit("client-invite:{$window}:{$client->id}", $decay);
        }

        // Staff in «Login as client» invite as themselves, not as the customer.
        $by = Actor::impersonating() ? Auth::guard('staff')->user() : Auth::user();
        [$invite, $token] = ClientInvite::issue($client, $this->email, $this->allPermissions ? null : $this->permissions,
            $by instanceof Staff || $by instanceof User ? $by : null);
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
        if ($this->pastTheRole($this->editAll ? null : array_values($this->editPermissions), $grant->permissions)) {
            return;
        }
        $this->account()->grantTo($grant->user, $this->editAll ? null : $this->editPermissions);

        $this->editing = null;
        $this->notify(__('account.permissions_saved'), 'success');
    }

    public function revoke(int $grantId): void
    {
        $this->account()->revoke($this->grant($grantId));
        $this->notify(__('account.access_removed'), 'success');
    }

    /**
     * Staff in «Login as client» give a login only what they could use there themselves, as in the admin's Users tab
     * (AUD3-1, its review): told, and nothing changes, otherwise. The owner is not limited.
     *
     * @param  list<string>|null  $permissions
     * @param  list<string>|null  $had
     */
    private function pastTheRole(?array $permissions, ?array $had = []): bool
    {
        if (!Actor::impersonating()) {
            return false;
        }
        $staff = Auth::guard('staff')->user();
        if (Actor::staffMayGrant($staff instanceof Staff ? $staff : null, $permissions, $had)) {
            return false;
        }
        $this->notify(__('account.grant_past_role'), 'error');

        return true;
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
