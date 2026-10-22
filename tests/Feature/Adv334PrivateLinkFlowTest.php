<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Client\AcceptInvite;
use App\Models\ClientInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Adversarial review of #334: the invitation flow still leaks its token past the page-level guards. Each test fails on the branch. */
class Adv334PrivateLinkFlowTest extends TestCase
{
    use RefreshDatabase;

    private function invite(): string
    {
        $owner = User::factory()->create();
        [, $token] = ClientInvite::issue($owner->client(), 'new@example.test', null, $owner);

        return $token;
    }

    /**
     * F1. Login sends the guest back to the invitation with a wire:navigate (SPA) redirect. The login page, unlike the
     * invitation's, carries the staff's head scripts and hooks, and an SPA visit keeps them running: an analytics or chat
     * script reads location.href on /invite/{token} and sends it away. The page's own guards are bypassed. A private
     * link must be reached by a full page load.
     */
    public function test_login_returns_to_an_invitation_with_a_full_page_load(): void
    {
        $token = $this->invite();
        User::factory()->create(['email' => 'new@example.test', 'password' => bcrypt('password-123')]);

        $component = Livewire::withoutLazyLoading()->test(Login::class)
            ->set('email', 'new@example.test')->set('password', 'password-123');
        session()->put('url.intended', route('invites.accept', ['token' => $token]));
        $component->call('submit')->assertRedirect(route('invites.accept', ['token' => $token]));

        $this->assertEmpty($component->effects['redirectUsingNavigate'] ?? null, 'an SPA navigation keeps the login page\'s scripts alive on the private URL');
    }

    /**
     * F2. The invitation page stores the whole token in the session (url.intended), for as long as the login takes
     * (and for good if it is never pulled). #252 kept private URLs out of the session for exactly this reason; the
     * invitation's own redirect puts it back in, in clear.
     */
    public function test_the_invitation_does_not_keep_its_token_in_the_session(): void
    {
        $token = $this->invite();

        Livewire::test(AcceptInvite::class, ['token' => $token])->call('login');

        $this->assertStringNotContainsString($token, (string) json_encode(session()->all()), 'the session keeps the secret in clear');
    }
}
