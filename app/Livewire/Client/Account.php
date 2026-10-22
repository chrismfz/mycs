<?php

namespace App\Livewire\Client;

use App\Helpers\NotificationHelper;
use App\Livewire\ComponentWithProperties;
use App\Livewire\Traits\EditsContact;
use App\Models\Client;
use App\Models\User;
use App\Services\Tax\EuVat;
use App\Support\Actor;
use App\Support\CurrentClient;
use App\Support\Reauth;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Account extends ComponentWithProperties
{
    use EditsContact;

    /** The login may change the account's details (`profile`): its primary contact and custom fields. */
    public bool $canEditDetails = false;

    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public function mount()
    {
        $user = Auth::user();

        $this->first_name = $user->first_name;
        $this->last_name = $user->last_name;
        $this->email = $user->email;

        // The details (address, phone, company…) are the current account's (docs/accounts.md, PR 2a), shown and saved
        // with `profile` (WHMCS, PR 2b); the login's own name and email always.
        $this->canEditDetails = CurrentClient::allows('profile');
        if ($this->canEditDetails) {
            // The account's details are its primary contact (PR 3a), as WHMCS's client profile.
            $this->fillContact(CurrentClient::model()?->primaryContact);
            $this->initializeProperties(CurrentClient::model(), Client::class);
        } else {
            $this->custom_properties = new Collection;
        }
    }

    public function rules()
    {
        return array_merge([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . Auth::id(),
        ], CurrentClient::allows('profile') ? $this->contactRules() : [], $this->getRulesForProperties());
    }

    public function validationAttributes()
    {
        return $this->getAttributesForProperties();
    }

    /** The login's current password, asked only when its email changes (security audit AUTHN-1). */
    public string $email_password = '';

    public function submit()
    {
        $validatedData = $this->validate();

        /** @var User $user */
        $user = Auth::user();
        // A new login email is a new way into the account (a password reset goes there): its owner's own password, never
        // staff in «Login as client» (AUTHN-1, AUTHN-6). The old address is told.
        $oldEmail = (string) $user->email;
        if (Str::lower(trim((string) $validatedData['email'])) !== Str::lower($oldEmail)) {
            if (Actor::impersonating()) {
                $this->addError('email', __('account.notifications.not_while_impersonating'));

                return;
            }
            if (($refusal = Reauth::refusal($user, $this->email_password)) !== null) {
                $this->addError('email_password', $refusal);

                return;
            }
        }
        // Staff in «Login as client» change the account's details only with the admin permission they need there (SA-5).
        if (Actor::impersonating() && !Actor::staffMayAs('profile')) {
            $this->notify(__('account.notifications.not_while_impersonating'), 'error');

            return;
        }
        $this->reset('email_password');
        $client = CurrentClient::allows('profile') ? CurrentClient::model() : null;
        // All of it or nothing (code review of #247): the account's details refused (a domain's contact, P1b-1) leave the
        // login's name as it was too.
        $emailChanged = DB::transaction(function () use ($user, $client, $validatedData) {
            $user->update(array_intersect_key($validatedData, array_flip(['first_name', 'last_name', 'email'])));
            $emailChanged = $user->wasChanged('email');
            if ($emailChanged) {
                $user->email_verified_at = null;
                $user->save();
            }
            if ($client !== null) {
                $this->identityLocked = (bool) $client->primaryContact?->identityLocked();
                $client->primaryContact?->update($this->contactValues());
                if (array_key_exists('properties', $validatedData)) {
                    $this->updateProperties($client, $validatedData['properties']);
                }
            }

            return $emailChanged;
        });

        // If email was changed, we should mark it as unverified and send a new verification email
        if ($emailChanged) {
            NotificationHelper::emailVerificationNotification($user);
            NotificationHelper::sendSystemEmailNotification(__('account.notifications.email_changed_subject'), e(__('account.notifications.email_changed_body', [
                'site' => config('app.name'), 'old' => $oldEmail, 'new' => $user->email,
            ])), [], $oldEmail);
        }

        // A new EU VAT number is charged VAT until VIES finds it valid (PR G): the customer is told.
        $pending = $client !== null && EuVat::enabled() && $client->primaryContact?->fresh()?->vat_status === EuVat::STATUS_PENDING;

        $this->notify(__('Account updated successfully.') . ($pending ? ' ' . __('vat.saved_pending') : ''));
    }

    public function render()
    {
        return view('client.account.index')->layoutData([
            'sidebar' => true,
            'title' => __('navigation.account'),
        ]);
    }
}
