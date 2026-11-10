<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketQuarantine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\Tests\Eml;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/**
 * Sweep 6, adversarial verifier for tickets-mail: failing tests for what the inbound router gets wrong.
 */
class Sweep6TicketsMailTest extends TestCase
{
    use RefreshDatabase;

    private FakeTicketMailbox $mailbox;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $this->mailbox);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
    }

    private function pass(string $domain): string
    {
        return "Authentication-Results: mymail.myip.test; dkim=pass header.d={$domain}; dmarc=pass header.from={$domain}";
    }

    /** @param  array<int|string, string>  $headers */
    private function receive(array $headers, string $text = 'Hello there'): void
    {
        $headers += ['Message-ID' => '<s6.' . bin2hex(random_bytes(6)) . '@example.com>'];
        $this->mailbox->unseen[] = Eml::make($headers, $text);
        $this->artisan('app:fetch-emails')->assertSuccessful();
    }

    /**
     * S6-TICKETS-MAIL-1: a login whose address was never verified (anyone may register any unused address, and
     * mail_must_verify is off by default) receives the real owner's mailed-in ticket.
     */
    public function test_a_mailed_in_ticket_never_goes_to_a_login_whose_email_is_unverified(): void
    {
        $this->assertFalse((bool) config('settings.mail_must_verify'), 'the default');
        $squatter = User::factory()->unverified()->create(['email' => 'billing@victimco.example']);

        // The real owner of the address writes in; their own server's DMARC passes.
        $this->receive(['From' => 'Victim <billing@victimco.example>', 'Subject' => 'Our VPS root password', $this->pass('victimco.example')], 'Secret details');

        $ticket = Ticket::query()->sole();
        $this->assertTrue($ticket->isGuest(), 'an unverified login is not proof the sender is that login: a guest ticket expected');
        $this->assertFalse($squatter->can('view', $ticket), 'the squatter reads the stranger\'s support mail');
    }

    /**
     * S6-TICKETS-MAIL-3: the subject tag MyCS itself sends («[Ticket #REF]», EmailTemplateSeeder) is not recognised, so a
     * reply that lost its References opens a second ticket (and a «we received it») instead of going to the quarantine.
     */
    public function test_a_reply_with_our_own_subject_tag_and_no_thread_key_is_quarantined_with_the_ticket_suggested(): void
    {
        $owner = User::factory()->create(['email' => 'maria@example.com']);
        $ticket = Ticket::query()->create(['reference' => '123456', 'subject' => 'Problem', 'status' => Ticket::STATUS_OPEN,
            'priority' => 'medium', 'department_id' => $this->support->id, 'client_id' => $owner->client()->id]);

        $this->receive(['From' => 'maria@example.com', 'Subject' => 'Re: [Ticket #123456] Problem', $this->pass('example.com')], 'Still broken');

        $this->assertSame(1, Ticket::query()->count(), 'a second ticket was opened for a reply to ticket 123456');
        $quarantine = TicketQuarantine::query()->sole();
        $this->assertSame(['no_thread_key', $ticket->id], [$quarantine->reason, $quarantine->suggested_ticket_id]);
    }

    /** S6-TICKETS-MAIL-1: a verified login's mail still opens its account's ticket; one that changes its address loses that. */
    public function test_a_verified_login_still_gets_its_account_ticket_until_its_address_changes(): void
    {
        $maria = User::factory()->create(['email' => 'maria@example.com']);
        $this->assertNotNull($maria->email_verified_at);
        $this->receive(['From' => 'maria@example.com', 'Subject' => 'First', $this->pass('example.com')]);
        $first = Ticket::query()->where('subject', 'First')->sole();
        $this->assertSame([$maria->client()->id, false], [$first->client_id, $first->isGuest()]);

        // Its login moved to an address it never proved (Client\Account clears email_verified_at on a change).
        $maria->forceFill(['email' => 'ceo@victimco.example', 'email_verified_at' => null])->save();
        $this->receive(['From' => 'ceo@victimco.example', 'Subject' => 'Second', $this->pass('victimco.example')]);
        $second = Ticket::query()->where('subject', 'Second')->sole();
        $this->assertTrue($second->isGuest());
        $this->assertSame('ceo@victimco.example', $second->guest_email);
        $this->assertFalse($maria->fresh()->can('view', $second));
    }

    /** S6-TICKETS-MAIL-1: a customer staff create in the admin is verified (staff typed the address), so its mail is its account's. */
    public function test_a_customer_staff_create_in_the_admin_is_verified(): void
    {
        $staff = \App\Models\Staff::factory()->admin()->create();
        $this->livewireAs($staff)->test(\App\Admin\Resources\ClientResource\Pages\CreateClient::class)
            ->fillForm(['first_name' => 'Nikos', 'last_name' => 'Example', 'email' => 'nikos@example.com', 'password' => 'secret-pass-1'])
            ->call('create')->assertHasNoFormErrors();
        $nikos = User::query()->where('email', 'nikos@example.com')->sole();
        $this->assertNotNull($nikos->email_verified_at);

        $this->receive(['From' => 'nikos@example.com', 'Subject' => 'Hello', $this->pass('example.com')]);
        $this->assertSame($nikos->client()->id, Ticket::query()->sole()->client_id);
    }

    /** S6-TICKETS-MAIL-3: the three tags, each to the quarantine with its ticket suggested; our tag off a new ticket's subject. */
    public function test_every_known_subject_tag_names_its_ticket_and_ours_is_taken_off_a_new_subject(): void
    {
        $owner = User::factory()->create(['email' => 'maria@example.com']);
        $ticket = Ticket::query()->create(['reference' => '123456', 'subject' => 'Problem', 'status' => Ticket::STATUS_OPEN,
            'priority' => 'medium', 'department_id' => $this->support->id, 'client_id' => $owner->client()->id]);
        foreach (['[Ticket ID: 123456]', '[#123456]', '[Ticket #123456]', '[ticket  # 123456 ]'] as $tag) {
            $this->receive(['From' => 'maria@example.com', 'Subject' => "Re: {$tag} Problem", $this->pass('example.com')]);
        }
        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame([$ticket->id], TicketQuarantine::query()->where('reason', 'no_thread_key')->pluck('suggested_ticket_id')->unique()->all());
        $this->assertSame(4, TicketQuarantine::query()->count());

        // A number that is no ticket of ours: a new ticket, its subject without the tag.
        $this->receive(['From' => 'maria@example.com', 'Subject' => 'Re: [Ticket #999999] Another thing', $this->pass('example.com')]);
        $this->assertSame('Another thing', Ticket::query()->latest('id')->firstOrFail()->subject);
    }

    /**
     * S6-TICKETS-MAIL-2: an unread backlog that arrived long before the department's `mail_since` is not asked for, so
     * the poll's 50 are mail of now: the new mail is a ticket on the first run, and the backlog stays unread, unquarantined.
     */
    public function test_an_old_unread_backlog_does_not_hold_back_new_mail(): void
    {
        $this->support->forceFill(['mail_since' => now()])->save();
        for ($i = 1; $i <= 120; $i++) {
            $raw = Eml::make(['From' => "old{$i}@example.com", 'Subject' => "Old {$i}", 'Message-ID' => "<old{$i}@example.com>",
                'Date' => now()->subYear()->toRfc2822String(), $this->pass('example.com')]);
            $this->mailbox->unseen[] = $raw;
            $this->mailbox->arrived[sha1($raw)] = now()->subYear();
        }
        // Arrived yesterday, sent before mail_since: inside the two days' margin, so still read and told apart exactly.
        $edge = Eml::make(['From' => 'edge@example.com', 'Subject' => 'Edge', 'Message-ID' => '<edge@example.com>',
            'Date' => now()->subHours(20)->toRfc2822String(), $this->pass('example.com')]);
        $this->mailbox->unseen[] = $edge;
        $this->mailbox->arrived[sha1($edge)] = now()->subHours(20);

        $this->receive(['From' => 'maria@example.com', 'Subject' => 'New today', 'Date' => now()->addMinute()->toRfc2822String(), $this->pass('example.com')]);

        $this->assertSame(['New today'], Ticket::query()->pluck('subject')->all());
        $this->assertSame(['before_mail_since'], TicketQuarantine::query()->pluck('reason')->all());
        $this->assertSame('Edge', TicketQuarantine::query()->sole()->subject);
        $this->assertCount(120, $this->mailbox->unseen, 'the backlog stays unread in the mailbox');

        // A department with no date reads everything, as before.
        $this->support->forceFill(['mail_since' => null])->saveQuietly();
        $this->artisan('app:fetch-emails')->assertSuccessful();
        $this->assertCount(70, $this->mailbox->unseen);
    }
}
