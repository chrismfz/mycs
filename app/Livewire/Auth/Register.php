<?php

namespace App\Livewire\Auth;

use App\Actions\Auth\Login;
use App\Attributes\DisabledIf;
use App\Helpers\NotificationHelper;
use App\Livewire\Client\AcceptInvite;
use App\Livewire\ComponentWithProperties;
use App\Livewire\Traits\EditsContact;
use App\Models\Client;
use App\Models\User;
use App\Support\CallerIp;
use App\Traits\Captchable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules\Password;

#[DisabledIf('registration_disabled')]
class Register extends ComponentWithProperties
{
    use Captchable, EditsContact;

    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $tos = false;

    /** Registrations an hour from one network (an IPv6 /64), and from everyone together (API-9). */
    public const PER_NETWORK_HOUR = 5;

    public const ALL_HOUR = 100;

    public function mount()
    {
        $this->initializeProperties(null, Client::class);
        $this->fillContact(null);
        if ($this->forInvite()) {
            $this->custom_properties = new Collection; // no account of their own: no account details to ask for
        }
    }

    /**
     * Registering to accept an invitation (PR 2b): the login works on the account it was invited to, so it does not
     * open one of its own (it would become its default). Should it never accept, EnsureClientAccount opens one later.
     */
    private function forInvite(): bool
    {
        return session()->has(AcceptInvite::SESSION_KEY) || str_starts_with((string) session('url.intended'), url('/invite'));
    }

    public function rules()
    {
        $rules = [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            // No «taken»: an address that has an account gets the same answer as a new one (below).
            'email' => 'required|email|max:255',
            'password' => ['required', 'confirmed', Password::defaults()],
        ];

        if (config('settings.tos')) {
            $rules['tos'] = 'accepted';
        }

        return array_merge($rules, $this->forInvite() ? [] : $this->contactRules(), $this->getRulesForProperties());
    }

    public function submit(Login $login)
    {
        $this->captcha();

        // Registration mails strangers (a verification, the owner of an address taken) and opens accounts: so many an
        // hour from one network, and from everyone together (security audit API-9, captcha off by default).
        $network = 'register:' . CallerIp::key();
        if (RateLimiter::tooManyAttempts($network, self::PER_NETWORK_HOUR) || RateLimiter::tooManyAttempts('register:all', self::ALL_HOUR)) {
            $this->addError('email', __('auth.register_throttle', ['seconds' => max(RateLimiter::availableIn($network), RateLimiter::availableIn('register:all'))]));

            return;
        }

        $validatedData = $this->validate();
        // Counted once the form is valid: a typo costs no try, and only a valid one mails or opens an account.
        RateLimiter::hit($network, 3600);
        RateLimiter::hit('register:all', 3600);

        // An address that already has an account (security audit, owner's decision 2026-10-23): never «taken», which
        // tells anyone whose address it is. The page answers «we sent you an email»; the owner is told how to log in
        // or reset, once an hour at most. The password still costs a hash, as for a new one.
        $existing = User::query()->where('email', $this->email)->first();
        if ($existing !== null) {
            Hash::make($this->password);
            if (RateLimiter::attempt('register-taken:' . $existing->id, 1, fn () => true, 3600)) {
                NotificationHelper::sendSystemEmailNotification(__('auth.register_taken.subject'), e(__('auth.register_taken.body', [
                    'app' => config('app.name'), 'login' => route('login'), 'reset' => route('password.request'),
                ])), email: $existing->email);
            }
            Session::put('notification', ['message' => __('auth.register_taken.sent', ['email' => $this->email]), 'type' => 'success']);

            return $this->redirect(route('login'), true);
        }

        // The login and its own account, with the account's details (docs/accounts.md, PR 2a), in one go.
        $user = DB::transaction(function () use ($validatedData) {
            $user = User::create([
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'email' => $this->email,
                'password' => Hash::make($this->password),
            ]);
            if (!$this->forInvite()) {
                $client = Client::openFor($user);
                // The account's details (its primary contact, PR 3a): the login's name and email where left empty.
                $client->primaryContact?->update(array_filter($this->contactValues(), fn ($value) => $value !== null && $value !== []));
                if (array_key_exists('properties', $validatedData)) {
                    $this->updateProperties($client, $validatedData['properties']);
                }
            }

            return $user;
        });

        $login->execute($user);

        return $this->redirectIntended(route('dashboard'), true);
    }

    public function render()
    {
        return view('auth.register');
    }
}
