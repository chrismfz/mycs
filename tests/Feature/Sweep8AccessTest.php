<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureClientAccount;
use App\Livewire\Auth\Register;
use App\Livewire\Client\AcceptInvite;
use App\Models\Client;
use App\Models\ClientInvite;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
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

    /** S8-ACCESS-1, the button itself: «Accept» is a Livewire call from the invitation's page, through the same middleware. */
    public function test_the_accept_call_of_the_invitations_page_opens_no_own_account(): void
    {
        $user = User::factory()->withoutClient()->create();
        $this->assertSame(0, ClientUser::query()->where('user_id', $user->id)->count(), 'a login with no account');
        $this->actingAs($user);

        foreach (['invite', 'invite/some-token'] as $path) {
            $request = Request::create(app('livewire')->getUpdateUri(), 'POST', ['components' => [['snapshot' => json_encode(['memo' => ['path' => $path]])]]]);
            $request->headers->set('X-Livewire', '1');
            $route = Route::getRoutes()->match($request); // Livewire's own update route: only there is the page it came from asked
            $request->setRouteResolver(fn () => $route);
            $this->app->instance('request', $request);
            (new EnsureClientAccount)->handle($request, fn () => new Response);
            $this->assertSame(0, ClientUser::query()->where('user_id', $user->id)->count(), "a call from /{$path} opened an own account");
        }

        // A call from any other page, also one whose address only starts alike: its own account, as before.
        $request = Request::create(app('livewire')->getUpdateUri(), 'POST', ['components' => [['snapshot' => json_encode(['memo' => ['path' => 'invitex']])]]]);
        $request->headers->set('X-Livewire', '1');
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);
        (new EnsureClientAccount)->handle($request, fn () => new Response);
        $this->assertSame(1, ClientUser::query()->where('user_id', $user->id)->where('owner', true)->count());
    }

    /** The neighbour: a login that registered for an invitation and never accepts gets its own account on any other page. */
    public function test_a_login_without_an_account_still_gets_its_own_away_from_the_invitation(): void
    {
        $user = User::factory()->withoutClient()->create();
        $this->assertSame(0, ClientUser::query()->where('user_id', $user->id)->count());

        $this->actingAs($user)->withSession($this->loginUser($user))->get(route('dashboard'))->assertOk();

        $this->assertSame(1, ClientUser::query()->where('user_id', $user->id)->where('owner', true)->count());
    }

    /** The neighbour: an ordinary registration, no invitation, opens the login's own account, its default. */
    public function test_an_ordinary_registration_still_opens_its_own_account(): void
    {
        $clientsBefore = Client::query()->count();

        Livewire::test(Register::class)->set('contact.country', 'GR')
            ->set('first_name', 'Plain')->set('last_name', 'Person')->set('email', 'plain@example.test')
            ->set('password', 'secret-pass-1')->set('password_confirmation', 'secret-pass-1')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'plain@example.test')->sole();
        $this->assertSame($clientsBefore + 1, Client::query()->count());
        $this->assertSame(1, ClientUser::query()->where('user_id', $user->id)->where('owner', true)->count());
        $this->assertTrue((bool) CurrentClient::defaultFor($user)?->grants()->where('user_id', $user->id)->value('owner'));
    }
}
