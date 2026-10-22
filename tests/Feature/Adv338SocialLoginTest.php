<?php

namespace Tests\Feature;

use App\Livewire\Auth\Password\Reset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OauthUser;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Adversarial review of #338: a password reset now marks the address confirmed, but the reset token is found by the
 * account's address under the column's collation. A token mailed to a lookalike address the attacker owns
 * («victim@exämple.test», an IDN domain) stays valid for «victim@example.test» once the account's address is changed
 * to it: the attacker confirms an address they never received mail at, and the owner's provider login opens it.
 *
 * MariaDB only (its collation is accent-insensitive; SQLite compares bytes, so there the token is not found).
 */
class Adv338SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $email): void
    {
        $user = (new OauthUser)->setRaw(['email_verified' => true, 'verified' => true])->map(['id' => '1', 'email' => $email]);
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andReturn($user);
        $socialite = Mockery::mock();
        $socialite->shouldReceive('buildProvider')->andReturn($driver);
        Socialite::swap($socialite);
        config(['settings.oauth_google' => true]);
    }

    private function requireAccentInsensitiveCollation(): void
    {
        if (DB::connection()->getDriverName() !== 'mariadb' && DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Needs the accent-insensitive collation of MariaDB.');
        }
    }

    public function test_a_reset_token_for_a_lookalike_address_does_not_confirm_the_real_one(): void
    {
        $this->requireAccentInsensitiveCollation();

        // The attacker registers with a lookalike address they own and asks for a reset: the link reaches them.
        $attacker = User::factory()->create(['email' => 'victim@exämple.test', 'email_verified_at' => null]);
        $token = Password::createToken($attacker);

        // Then moves the account to the victim's address (unconfirmed: the change clears the confirmation).
        $attacker->forceFill(['email' => 'victim@example.test', 'email_verified_at' => null])->save();

        Livewire::withQueryParams(['email' => 'victim@example.test'])
            ->test(Reset::class, ['token' => $token])
            ->set('password', 'attacker-knows-1')->set('password_confirmation', 'attacker-knows-1')
            ->call('submit');

        $this->assertNull($attacker->fresh()->email_verified_at, 'A token mailed to another address confirmed this one.');

        // The victim logs in with Google: they must not land in the account whose password the attacker set.
        $this->provider('victim@example.test');
        $this->get('/oauth/google/callback');
        $this->assertGuest();
    }

    public function test_a_lookalike_accounts_token_does_not_confirm_a_second_account(): void
    {
        $this->requireAccentInsensitiveCollation();

        // Two accounts: the lookalike one asks for a reset, moves away, and a second one takes the victim's address.
        $lookalike = User::factory()->create(['email' => 'victim@exämple.test', 'email_verified_at' => now()]);
        $token = Password::createToken($lookalike);
        $lookalike->forceFill(['email' => 'elsewhere@attacker.test'])->save();

        $squatter = User::factory()->create(['email' => 'victim@example.test', 'email_verified_at' => null]);

        Livewire::withQueryParams(['email' => 'victim@example.test'])
            ->test(Reset::class, ['token' => $token])
            ->set('password', 'attacker-knows-1')->set('password_confirmation', 'attacker-knows-1')
            ->call('submit');

        $this->assertNull($squatter->fresh()->email_verified_at, 'Another account\'s reset token confirmed this address.');

        $this->provider('victim@example.test');
        $this->get('/oauth/google/callback');
        $this->assertGuest();
    }
}
