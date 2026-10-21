<?php

namespace Tests\Feature;

use App\Livewire\Client\Emails;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\EmailLog;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** «My emails» (owner 2026-10-22): the account sees what we sent it, as it was sent, and nothing else. */
class ClientEmailsTest extends TestCase
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

    /** @param  array<string, mixed>  $attributes */
    private function mail(string $subject, array $attributes = []): EmailLog
    {
        return EmailLog::query()->create(['client_id' => $this->client->id, 'subject' => $subject, 'to' => 'owner@example.test',
            'body' => "<p>{$subject} body</p>", 'status' => EmailLog::STATUS_SENT, 'sent_at' => now(), ...$attributes]);
    }

    public function test_the_owner_sees_the_sent_mails_of_the_account_newest_first_and_opens_one(): void
    {
        $this->mail('Invoice #12 created', ['sent_at' => now()->subDay()]);
        $welcome = $this->mail('Your hosting is ready');

        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner))->get(route('account.emails'))->assertOk()
            ->assertSeeInOrder(['Your hosting is ready', 'Invoice #12 created'])->assertSee(__('account.emails.title'));

        Livewire::actingAs($this->owner)->test(Emails::class)->call('show', $welcome->id)
            ->assertSee('srcdoc', false)->assertSee(e('<p>Your hosting is ready body</p>'), false)->assertSee('sandbox="allow-popups', false);
    }

    public function test_not_another_accounts_nor_failed_queued_staff_or_system_mails(): void
    {
        $this->mail('Ours');
        $other = User::factory()->create()->clients()->firstOrFail();
        $theirs = $this->mail('Someone else’s invoice', ['client_id' => $other->id]);
        $this->mail('Bounced', ['status' => EmailLog::STATUS_FAILED, 'sent_at' => null]);
        $this->mail('Still queued', ['status' => EmailLog::STATUS_PENDING, 'sent_at' => null]);
        $this->mail('Cron failed', ['template_key' => 'system']);
        Staff::factory()->create(['email' => 'desk@example.test']);
        $this->mail('Copy staff resent to themselves', ['to' => 'desk@example.test']);

        $page = Livewire::actingAs($this->owner)->test(Emails::class);
        $page->assertSee('Ours')->assertDontSee('Someone else')->assertDontSee('Bounced')->assertDontSee('Still queued')
            ->assertDontSee('Cron failed')->assertDontSee('Copy staff resent');

        // Its id typed in: not shown, said so.
        $page->call('show', $theirs->id)->assertDontSee('Someone else’s invoice body')->assertSee(__('account.emails.not_found'));
        Livewire::actingAs($this->owner)->withQueryParams(['open' => $theirs->id])->test(Emails::class)->assertDontSee('Someone else’s invoice body');
    }

    public function test_a_user_of_the_account_needs_the_view_emails_permission(): void
    {
        $this->mail('Ours');
        $helper = User::factory()->withoutClient()->create();
        $grant = ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $helper->id, 'owner' => false,
            'permissions' => ['tickets'], 'granted_at' => now()]);

        $this->actingAs($helper)->withSession($this->loginUser($helper))->get(route('account.emails'))->assertForbidden();
        $this->actingAs($helper)->withSession($this->loginUser($helper))->get(route('account'))->assertOk()->assertDontSee(route('account.emails'));

        $grant->update(['permissions' => ['tickets', 'emails']]);
        app()->forgetInstance(CurrentClient::class); // its grant is kept for the request; a test is one app
        $this->actingAs($helper)->withSession($this->loginUser($helper))->get(route('account.emails'))->assertOk()->assertSee('Ours');
    }

    public function test_a_guest_is_sent_to_log_in(): void
    {
        $this->get(route('account.emails'))->assertRedirect(route('login'));
    }

    public function test_it_reads_in_greek(): void
    {
        app()->setLocale('el');
        $this->assertSame('Τα email μου', __('account.emails.title'));
    }
}
