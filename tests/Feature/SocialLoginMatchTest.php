<?php

namespace Tests\Feature;

use App\Livewire\Auth\Password\Reset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OauthUser;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A provider's login opens only the account whose address is exactly the provider's, and only once that address was
 * confirmed here: an account someone else registered with it (and so knows its password) is never handed over.
 */
class SocialLoginMatchTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $email, bool $verified = true): void
    {
        $user = (new OauthUser)->setRaw(['email_verified' => $verified, 'verified' => $verified])->map(['id' => '1', 'email' => $email]);
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andReturn($user);
        $socialite = Mockery::mock();
        $socialite->shouldReceive('buildProvider')->andReturn($driver);
        Socialite::swap($socialite);
        config(['settings.oauth_google' => true, 'settings.oauth_github' => true, 'settings.oauth_discord' => true]);
    }

    public function test_the_owner_of_a_confirmed_account_logs_in(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);
        $this->provider('owner@example.test');

        $this->get('/oauth/google/callback')->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_an_unconfirmed_account_is_not_opened_through_a_provider(): void
    {
        User::factory()->create(['email' => 'victim@example.test', 'email_verified_at' => null]);
        $this->provider('victim@example.test');

        foreach (['google', 'github', 'discord'] as $provider) {
            $this->get("/oauth/$provider/callback")->assertRedirect(route('password.request'));
            $this->assertGuest();
            $this->assertSame(__('auth.oauth.unverified_account'), session('notification')['message']);
        }
    }

    /** @return array<string, array{string}> */
    public static function lookalikes(): array
    {
        return ['accent' => ['änna@example.test'], 'full width' => ['ａnna@example.test'], 'domain accent' => ['anna@exämple.test']];
    }

    #[DataProvider('lookalikes')]
    public function test_a_lookalike_address_never_matches_another_account(string $lookalike): void
    {
        // A collation that ignores accents and widths finds «anna@» for «änna@» and «ａnna@» (MariaDB's does; SQLite's
        // compares bytes, so there the lookup itself already misses).
        User::factory()->create(['email' => 'anna@example.test', 'email_verified_at' => now()]);

        $this->provider($lookalike);

        $this->get('/oauth/google/callback')->assertRedirect(route('register'));
        $this->assertGuest();
    }

    public function test_a_provider_without_a_confirmed_address_logs_no_one_in(): void
    {
        User::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);

        $this->provider('owner@example.test', verified: false);
        $this->get('/oauth/google/callback')->assertRedirect(route('login'));
        $this->get('/oauth/discord/callback')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_github_login_without_an_address_logs_no_one_in(): void
    {
        User::factory()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);
        $this->provider('');

        $this->get('/oauth/github/callback')->assertRedirect(route('register'));

        $this->assertGuest();
    }

    public function test_a_disabled_provider_has_no_callback(): void
    {
        $this->provider('owner@example.test');
        config(['settings.oauth_google' => false]);

        $this->get('/oauth/google/callback')->assertNotFound();
    }

    public function test_a_password_reset_confirms_the_address(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.test', 'email_verified_at' => null]);
        $token = Password::createToken($user);

        Livewire::withQueryParams(['email' => $user->email])
            ->test(Reset::class, ['token' => $token])
            ->set('password', 'a-new-password-1')->set('password_confirmation', 'a-new-password-1')
            ->call('submit');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }
}
