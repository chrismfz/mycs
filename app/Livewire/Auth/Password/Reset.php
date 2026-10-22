<?php

namespace App\Livewire\Auth\Password;

use App\Livewire\Component;
use App\Models\User;
use App\Traits\Captchable;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Request;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;

class Reset extends Component
{
    use Captchable;

    public string $token;

    #[Url, Locked]
    public string $email = '';

    public string $password = '';

    /** The customers' one password rule (AppServiceProvider: Password::defaults), as at registration (AUTHN-12). */
    protected function rules(): array
    {
        return ['password' => ['required', 'string', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()]];
    }

    #[Validate('required')]
    public string $password_confirmation = '';

    public function mount()
    {
        if (!$this->token || !Request::has('email')) {
            return abort(404);
        }
        // Validate token
        if (!$this->tokenIsTheAccounts(User::where('email', $this->email)->firstOrFail())) {
            return abort(404);
        }
    }

    /**
     * The token was mailed to exactly the account's address: the table's collation matches «exämple» to «example», and a
     * reset confirms the address.
     */
    private function tokenIsTheAccounts(User $user): bool
    {
        return DB::table((string) config('auth.passwords.users.table'))->where('email', $user->email)->get()
            ->contains(fn ($row) => hash_equals((string) $user->email, (string) $row->email) && Hash::check($this->token, (string) $row->token))
            && Password::tokenExists($user, $this->token);
    }

    public function submit()
    {
        $this->validate();

        $this->captcha();

        $user = User::where('email', $this->email)->first();
        if (!$user || !$this->tokenIsTheAccounts($user)) {
            return $this->notify(__(Password::INVALID_TOKEN), 'error');
        }

        $status = Password::reset(
            ['email' => $this->email, 'password' => $this->password, 'password_confirmation' => $this->password_confirmation, 'token' => $this->token],
            function ($user) {
                // The reset link reached the address: it is now confirmed.
                $user->forceFill([
                    'password' => Hash::make($this->password),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ]);

                $user->save();

                // Revoke all existing sessions
                $user->sessions()->delete();

                event(new PasswordReset($user));
            }
        );

        return $status === Password::PASSWORD_RESET ? $this->redirect(route('login')) : $this->notify(__($status), 'error');
    }

    public function render()
    {
        return view('auth.password.reset');
    }
}
