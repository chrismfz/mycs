<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Livewire\Client\Emails;
use App\Models\Client;
use App\Models\ClientInvite;
use App\Models\ClientUser;
use App\Models\EmailLog;
use App\Models\User;
use App\Support\CurrentClient;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\TestCase;

/**
 * Adversarial review of #311 («My emails»). `test_holds_*` pass on the PR (a hole looked for and not found);
 * the others fail on it.
 */
class ClientEmailsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['email' => 'owner@example.test']);
        $this->client = $this->owner->clients()->firstOrFail();
    }

    private function member(?array $permissions): User
    {
        $user = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $user->id, 'owner' => false,
            'permissions' => $permissions, 'granted_at' => now()]);

        return $user;
    }

    private function workOn(User $user): static
    {
        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $this->client->id]));
    }

    private function mailOn(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);
        (new EmailTemplateSeeder)->run();
    }

    private function snapshotOf(string $html): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $m);
        foreach ($m[1] as $raw) {
            $decoded = html_entity_decode($raw, ENT_QUOTES);
            if ((json_decode($decoded, true)['memo']['name'] ?? null) === 'client.emails') {
                return $decoded;
            }
        }
        $this->fail('no client.emails component on the page');
    }

    private function livewireUpdate(string $snapshot, array $calls)
    {
        return $this->withHeaders(['X-Livewire' => '1'])->postJson(app(HandleRequests::class)->getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => new \stdClass, 'calls' => $calls]],
        ]);
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Findings (fail on 16d1fc8b)

    /**
     * The mass mail's «Stop these emails» link is the owner's signed, never-expiring unsubscribe link. The log keeps it
     * as sent, so a member allowed `emails` (not the owner) takes the owner off the news with it.
     */
    public function test_the_owners_unsubscribe_link_is_not_shown_to_the_account(): void
    {
        $this->mailOn();
        $url = URL::signedRoute('announcements.unsubscribe', ['user' => $this->owner->id]);
        NotificationHelper::announcementNotification($this->client, 'announcement_news',
            ['subject' => 'Offers', 'content' => 'Our offers', 'client' => $this->client, 'user' => $this->owner, 'unsubscribe_url' => $url]);
        $log = EmailLog::query()->where('client_id', $this->client->id)->sole();
        $log->update(['status' => EmailLog::STATUS_SENT, 'sent_at' => now()]);
        $signature = (string) parse_url($url, PHP_URL_QUERY);
        $this->assertStringContainsString('signature=', $signature);

        $member = $this->member(['emails']);
        $this->workOn($member);
        Livewire::actingAs($member)->test(Emails::class)->call('show', $log->id)
            ->assertSee('srcdoc', false)
            ->assertDontSee(substr($signature, strpos($signature, 'signature=') + 10), false);
    }

    /**
     * Rows logged before the masking (the secrets sweep, 2026-10-15) and rows moved from `user_id` to `client_id` by the
     * split of accounts (a login's own mails: password reset, verification, «new login») have no template_key. The page
     * takes every row without one, so they are shown as logged: a live reset link or a service password in clear, to a
     * member as well as to the owner. The page promises «secrets masked»: only rows of the audit (with a template) keep it.
     */
    public function test_rows_from_before_the_masking_are_not_shown(): void
    {
        $old = EmailLog::query()->create(['client_id' => $this->client->id, 'subject' => 'Reset your password', 'to' => 'owner@example.test',
            'body' => '<a href="https://mycs.example.test/reset-password/live-reset-token-123">Reset</a>',
            'status' => EmailLog::STATUS_SENT, 'sent_at' => now()->subDays(20)]);
        $this->assertNull($old->template_key);

        $member = $this->member(['emails']);
        $this->workOn($member);
        Livewire::actingAs($member)->test(Emails::class)->assertDontSee('Reset your password')
            ->call('show', $old->id)->assertDontSee('live-reset-token-123', false);
    }

    /** Holds: `?open=abc` in the address (the property is `?int`, from the URL) is the page, not a 500. */
    public function test_holds_a_garbage_open_in_the_address_is_not_a_server_error(): void
    {
        $this->workOn($this->owner)->get(route('account.emails', ['open' => 'abc']))->assertOk();
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Holds (pass on 16d1fc8b)

    /** Real Livewire requests go through `client.can:emails` (persistent middleware): a member who lost it gets a 403. */
    public function test_holds_a_member_who_lost_the_permission_cannot_open_a_mail_through_livewire(): void
    {
        $log = EmailLog::query()->create(['client_id' => $this->client->id, 'subject' => 'Invoice', 'to' => 'owner@example.test',
            'body' => '<p>secret-ish body</p>', 'status' => EmailLog::STATUS_SENT, 'sent_at' => now(), 'template_key' => 'new_invoice_created']);
        $member = $this->member(['emails']);
        $html = $this->workOn($member)->get(route('account.emails'))->assertOk()->getContent();
        $snapshot = $this->snapshotOf($html);

        $call = [['path' => '', 'method' => 'show', 'params' => [$log->id]]];
        $this->livewireUpdate($snapshot, $call)->assertOk(); // control: reaches the component

        ClientUser::query()->where('user_id', $member->id)->update(['permissions' => json_encode(['tickets'])]);
        app(CurrentClient::class)->forget();
        Livewire::flushState();
        $this->assertSame(403, $this->livewireUpdate($snapshot, $call)->status());

        ClientUser::query()->where('user_id', $member->id)->update(['revoked_at' => now(), 'permissions' => json_encode(['emails'])]);
        app(CurrentClient::class)->forget();
        Livewire::flushState();
        $response = $this->livewireUpdate($snapshot, $call);
        $this->assertStringNotContainsString('secret-ish body', $response->getContent());
    }

    /** The invitation's token is taken out of the log before anyone of the account can read it. */
    public function test_holds_an_invitation_link_is_not_shown(): void
    {
        $this->mailOn();
        [$invite, $token] = ClientInvite::issue($this->client, 'new@example.test', ['tickets'], $this->owner);
        NotificationHelper::clientInviteNotification($invite, $token);
        $log = EmailLog::query()->where('client_id', $this->client->id)->sole();
        $log->update(['status' => EmailLog::STATUS_SENT, 'sent_at' => now()]);

        Livewire::actingAs($this->owner)->test(Emails::class)->call('show', $log->id)
            ->assertSee('srcdoc', false)->assertDontSee($token, false);
    }

    /** The body cannot leave the srcdoc attribute (Blade escapes quotes), and the frame runs no script. */
    public function test_holds_the_body_stays_in_the_sandbox(): void
    {
        $log = EmailLog::query()->create(['client_id' => $this->client->id, 'subject' => 'x', 'to' => 'owner@example.test',
            'body' => '"><script>alert(1)</script><iframe srcdoc=\'x\'>', 'status' => EmailLog::STATUS_SENT, 'sent_at' => now(),
            'template_key' => 'new_invoice_created']);

        $html = Livewire::actingAs($this->owner)->test(Emails::class)->call('show', $log->id)->html();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertMatchesRegularExpression('/<iframe sandbox="allow-popups allow-popups-to-escape-sandbox"/', $html);
        $this->assertStringNotContainsString('allow-scripts', $html);
        $this->assertStringNotContainsString('allow-same-origin', $html);
    }
}
