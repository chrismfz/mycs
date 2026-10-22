<?php

namespace App\Http\Controllers;

use App\Actions\Auth\Login;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Session;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\GoogleProvider;
use SocialiteProviders\Discord\Provider as DiscordProvider;

class SocialLoginController extends Controller
{
    protected $discord_driver;

    protected $github_driver;

    protected $google_driver;

    public function __construct()
    {
        $this->discord_driver = Socialite::buildProvider(DiscordProvider::class, [
            'client_id' => config('settings.oauth_discord_client_id'),
            'client_secret' => config('settings.oauth_discord_client_secret'),
            'redirect' => '/oauth/discord/callback',
        ]);

        $this->github_driver = Socialite::buildProvider(GithubProvider::class, [
            'client_id' => config('settings.oauth_github_client_id'),
            'client_secret' => config('settings.oauth_github_client_secret'),
            'redirect' => '/oauth/github/callback',
        ]);

        $this->google_driver = Socialite::buildProvider(GoogleProvider::class, [
            'client_id' => config('settings.oauth_google_client_id'),
            'client_secret' => config('settings.oauth_google_client_secret'),
            'redirect' => '/oauth/google/callback',
        ]);
    }

    public function redirect($provider)
    {
        if (!config("settings.oauth_$provider")) {
            abort(404);
        }

        return match ($provider) {
            'discord' => $this->discord_driver->scopes(['email'])->redirect(),
            'github' => $this->github_driver->scopes(['user:email'])->redirect(),
            'google' => $this->google_driver->scopes(['email'])->redirect(),
            default => abort(404)
        };
    }

    public function handle($provider)
    {
        if (!config("settings.oauth_$provider")) {
            abort(404);
        }

        if ($provider == 'discord') {
            $oauth_user = $this->discord_driver->user();

            if (($oauth_user->user['verified'] ?? false) !== true) {
                return $this->refuse('login', __('auth.oauth.unverified_discord_account'));
            }

            return $this->findUserAndLogin($oauth_user->email);
        } elseif ($provider == 'google') {
            $oauth_user = $this->google_driver->user();

            if (($oauth_user->user['email_verified'] ?? false) !== true) {
                return $this->refuse('login', __('auth.oauth.unverified_google_account'));
            }

            return $this->findUserAndLogin($oauth_user->email);
        } elseif ($provider == 'github') {
            // Socialite asks GitHub for a primary, verified address when the profile shows none.
            return $this->findUserAndLogin($this->github_driver->user()->email);
        } else {
            return redirect()->route('login');
        }
    }

    private function findUserAndLogin(?string $email): RedirectResponse
    {
        // The column's collation matches «ä» to «a» and «ｂ» to «b»: the provider's address must be the account's own.
        $user = User::where('email', $email)->first();
        if ($email === null || $email === '' || !$user || strtolower($user->email) !== strtolower($email)) {
            return $this->refuse('login', __('auth.oauth.not_logged_in')); // never says whether an account has it
        }

        // Whoever registered an address they never confirmed knows its password: logging its owner in through the
        // provider would hand them that account. A password reset proves the address (and ends the other sessions).
        if (!$user->email_verified_at) {
            return $this->refuse('login', __('auth.oauth.not_logged_in')); // as one that does not exist: a reset confirms it
        }

        if ($user->tfa_secret) {
            Session::put('2fa', [
                'user_id' => $user->id,
                'remember' => true,
                'expires' => now()->addMinutes(5),
            ]);

            return redirect()->route('2fa');
        }

        (new Login)->execute($user, true);

        // Back where the login started from (an invitation's link, PR 2b).
        return redirect(session()->pull('url.intended', route('home')));
    }

    /** Back to a page with why (the pages show the session's notification, not a flashed «error»). */
    private function refuse(string $route, string $message): RedirectResponse
    {
        Session::put('notification', ['message' => $message, 'type' => 'error']);

        return redirect()->route($route);
    }
}
