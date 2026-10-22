<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\Tfa;
use App\Livewire\Client\AcceptInvite;
use App\Livewire\Client\Notifications;
use App\Models\ClientInvite;
use App\Models\ClientUser;
use App\Models\EmailLog;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;
use Tests\TestCase;

/** Code review of PR 2b (feat/client-users): the findings' tests stay as regression coverage. */
class ClientUsersCodeReviewTest extends TestCase
{
    use RefreshDatabase;

    /** CR-1: the forced, non-preference `client_invite` mail shows up on every login's preferences page (no label). */
    public function test_the_invitation_mail_is_not_a_notification_preference(): void
    {
        (new EmailTemplateSeeder)->run();
        $user = User::factory()->create();
        $this->actingAs($user);

        $keys = Livewire::test(Notifications::class)->instance()->notifications->pluck('key')->all();

        $this->assertNotContains('client_invite', $keys);
    }

    /** CR-2: an invitee with 2FA logs in from the invitation and is not brought back to it (Tfa ignores url.intended). */
    public function test_a_login_with_two_factor_returns_to_the_invitation(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        [, $token] = ClientInvite::issue($client, 'helper@example.test', ['tickets'], $owner);

        $secret = 'JBSWY3DPEHPK3PXP';
        $invitee = User::factory()->create(['tfa_secret' => $secret]);

        Livewire::test(AcceptInvite::class, ['token' => $token])->call('login')->assertRedirect(route('login'));
        Session::put('2fa', ['user_id' => $invitee->id, 'remember' => false, 'expires' => now()->addMinutes(5)]);
        $code = (new TwoFactorAuth(new EndroidQrCodeProvider, config('app.name')))->getCode($secret);

        // Back to the invitation without its secret in the URL (#334): the session keeps it, encrypted.
        Livewire::test(Tfa::class)->set('code', $code)->call('verify')
            ->assertRedirect(route('invites.continue'));
        $this->actingAs($invitee);
        Livewire::test(AcceptInvite::class)->call('accept');
        $this->assertTrue($client->fresh()->users()->whereKey($invitee->id)->exists(), 'accepted from /invite');
    }

    /** CR-3: in «Login as client» an invitation is recorded (and mailed) as the customer's, not the staff member's. */
    public function test_an_invitation_sent_by_staff_as_the_client_is_not_attributed_to_the_customer(): void
    {
        Mail::fake();
        config(['settings.default_currency' => 'USD']);
        $owner = User::factory()->create();
        $staff = Staff::factory()->withPermissions(['admin.users.impersonate'])->create();

        // Real requests, as staff in «Login as client» (the session carries the impersonation).
        $this->actingAs($owner); // what ImpersonateMiddleware sets on each request
        $html = $this->actingAs($staff, 'staff')->withSession($this->loginUser($owner, ['impersonating' => $owner->id]))
            ->get(route('account.users'))->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $m);
        $snapshot = collect($m[1])->map(fn ($s) => html_entity_decode($s))->first(fn ($s) => str_contains($s, '"name":"client.users"'));
        $this->assertNotNull($snapshot);
        $this->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), ['_token' => csrf_token(), 'components' => [[
            'snapshot' => $snapshot, 'updates' => ['email' => 'x@example.test'], 'calls' => [['method' => 'invite', 'params' => [], 'metadata' => []]],
        ]]])->assertOk();

        $invite = ClientInvite::query()->sole();
        $this->assertNull($invite->invited_by_user_id, 'staff acted, the owner did not invite anyone');
        $this->assertSame($staff->id, (int) $invite->invited_by_staff_id);
    }

    /** CR-5: the invitation's link is not kept in the email log. */
    public function test_the_email_log_does_not_keep_the_invitation_link(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);
        (new EmailTemplateSeeder)->run();
        $owner = User::factory()->create();
        $client = $owner->client();
        [$invite, $token] = ClientInvite::issue($client, 'helper@example.test', null, $owner);

        NotificationHelper::clientInviteNotification($invite, $token);

        $body = (string) EmailLog::query()->where('to', 'helper@example.test')->sole()->body;
        $this->assertStringNotContainsString($token, $body);
    }

    /** CR-4: registering from an invitation opens no account of one's own; the invited one becomes the default. */
    public function test_registering_from_an_invitation_opens_no_own_account(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        [, $token] = ClientInvite::issue($client, 'new@example.test', ['tickets'], $owner);
        session()->put('url.intended', route('invites.accept', ['token' => $token]));

        Livewire::test(Register::class)
            ->set('first_name', 'New')->set('last_name', 'Person')->set('email', 'new@example.test')
            ->set('password', 'secret-pass-1')->set('password_confirmation', 'secret-pass-1')
            ->call('submit');

        $user = User::query()->where('email', 'new@example.test')->sole();
        $this->assertSame(0, ClientUser::query()->where('user_id', $user->id)->count());
        ClientInvite::findByToken($token)->accept($user);
        $this->assertSame($client->id, CurrentClient::defaultFor($user)?->id);
    }

    /** CR-9: a Livewire call on a page whose area was taken away is refused (the persistent middleware). */
    public function test_a_livewire_call_on_a_page_whose_area_was_taken_away_is_refused(): void
    {
        $client = User::factory()->create()->client();
        $user = User::factory()->withoutClient()->create();
        $grant = ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $user->id, 'permissions' => ['tickets'], 'granted_at' => now()]);

        $session = $this->loginUser($user, [CurrentClient::SESSION_KEY => $client->id]);
        $html = $this->actingAs($user)->withSession($session)->get(route('tickets'))->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $m);
        $snapshot = collect($m[1])->map(fn ($s) => html_entity_decode($s))->first(fn ($s) => str_contains($s, '"name":"tickets"'));
        $this->assertNotNull($snapshot);

        $payload = ['_token' => csrf_token(), 'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => '$refresh', 'params' => [], 'metadata' => []]]]]];

        $grant->forceFill(['permissions' => ['invoices']])->save();
        app(CurrentClient::class)->forget();
        $this->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), $payload)->assertForbidden();
    }
}
