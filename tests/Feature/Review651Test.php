<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Models\BulkMail;
use App\Models\Client;
use App\Models\User;
use App\Services\Mail\BulkMailer;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Independent review of #651 (WG-8 closed accounts). Each test fails on the PR's head and names the defect. Fake data.
 */
class Review651Test extends TestCase
{
    use RefreshDatabase;

    /**
     * R651-1: a login signed in before staff close its only account keeps working: ResolveUserSession checks closedOut()
     * only when it signs the guard in again (cookie / new session), never while the session is live.
     */
    public function test_r651_1_a_live_session_ends_when_its_only_account_is_closed(): void
    {
        $user = User::factory()->create();
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')->call('submit')->assertHasNoErrors();
        $this->get('/dashboard')->assertOk();

        $user->clients()->sole()->update(['closed_at' => now()]);

        $this->get('/dashboard')->assertRedirect();
        $this->assertGuest('web');
    }

    /**
     * R651-2: a login with an open account and a closed one signs in on the open one, but can switch to the closed one
     * (CurrentClient::switchTo, the session's client_id) and work on it: pay, add funds, upgrade, manage services.
     */
    public function test_r651_2_a_closed_account_cannot_be_switched_to(): void
    {
        $user = User::factory()->create();
        $open = $user->clients()->sole();
        $closed = Client::factory()->ownedBy($user)->create(['closed_at' => now()]);
        Auth::guard('web')->setUser($user);

        $this->assertSame($open->id, CurrentClient::model()?->id);
        $this->assertFalse(app(CurrentClient::class)->switchTo($closed->id), 'switching to a closed account is refused');

        session()->put(CurrentClient::SESSION_KEY, $closed->id);
        app(CurrentClient::class)->forget();
        $this->assertSame($open->id, CurrentClient::model()?->id, 'a closed account in the session is not taken');
    }

    /** R651-3: a mass mail's news still goes to a closed account's owner (BulkMailer::recipients ignores closed_at). */
    public function test_r651_3_news_is_not_sent_to_a_closed_account(): void
    {
        $closed = User::factory()->create()->clients()->sole();
        $closed->update(['closed_at' => now()]);
        $open = User::factory()->create()->clients()->sole();
        $mail = BulkMail::query()->create(['name' => 'n', 'kind' => BulkMail::KIND_NEWS, 'subject' => 's', 'body' => 'b', 'filters' => []]);

        $ids = app(BulkMailer::class)->recipients($mail)->pluck('id')->all();

        $this->assertContains($open->id, $ids);
        $this->assertNotContains($closed->id, $ids);
    }
}
