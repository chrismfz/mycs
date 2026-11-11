<?php

namespace Tests\Feature;

use App\Livewire\Auth\Register;
use App\Livewire\Client\AcceptInvite;
use App\Models\Client;
use App\Models\ClientInvite;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Sweep 8, «access»: adversarial tests of the candidates (failing until fixed). */
class Sweep8AccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /**
     * S8-ACCESS-1: a login created to accept an invitation gets no account of its own (Register::forInvite), yet the
     * page it is sent to (/invite, in the `web` group) runs EnsureClientAccount, which opens one before the invitation
     * is accepted. That empty account, being owned, is the login's default from then on.
     */
    public function test_registering_from_an_invitation_and_returning_to_it_opens_no_own_account(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        [, $token] = ClientInvite::issue($client, 'new@example.test', ['tickets'], $owner);
        $clientsBefore = Client::query()->count();

        // The invitation's page as a guest: «create a login».
        Livewire::test(AcceptInvite::class, ['token' => $token])->call('register')->assertRedirect(route('register'));

        Livewire::test(Register::class)
            ->set('first_name', 'New')->set('last_name', 'Person')->set('email', 'new@example.test')
            ->set('password', 'secret-pass-1')->set('password_confirmation', 'secret-pass-1')
            ->call('submit')
            ->assertRedirect(route('invites.continue'));

        $user = User::query()->where('email', 'new@example.test')->sole();
        $this->assertSame(0, ClientUser::query()->where('user_id', $user->id)->count(), 'the registration itself opens none');

        // Where the registration sends the browser: back to the invitation, logged in.
        $this->actingAs($user)->withSession($this->loginUser($user, [AcceptInvite::SESSION_KEY => session(AcceptInvite::SESSION_KEY)]))
            ->get(route('invites.continue'))->assertOk();

        $this->assertSame(0, ClientUser::query()->where('user_id', $user->id)->count(), 'loading the invitation opened an own account');
        $this->assertSame($clientsBefore, Client::query()->count(), 'a stray empty account was created');

        Livewire::test(AcceptInvite::class)->call('accept')->assertRedirect(route('dashboard'));

        $this->assertSame(1, ClientUser::query()->where('user_id', $user->id)->count());
        $this->assertSame($client->id, CurrentClient::defaultFor($user)?->id, 'the next login lands on the invited account');
    }
}
