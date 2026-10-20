<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketCc;
use App\Models\TicketDepartment;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use App\Models\TicketQuarantine;
use App\Models\User;
use App\Services\Import\WhmcsTicketImport;
use App\Support\Tickets\MergeTickets;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\Tests\Eml;
use PDO;
use Symfony\Component\Mime\Email;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/** CC on a ticket (docs/tickets.md §6λ): staff copy addresses; they get staff's replies and theirs join the ticket. */
class TicketCcTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    private TicketDepartment $support;

    private FakeTicketMailbox $mailbox;

    private string $pass = 'Authentication-Results: mymail.myip.test; dkim=pass header.d=example.com; dmarc=pass header.from=example.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test', 'settings.default_currency' => 'USD']);
        $this->mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $this->mailbox);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->create(['first_name' => 'Nikos', 'last_name' => 'Staff', 'role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $this->actingAs($this->staff, 'staff');
    }

    /** @return Collection<int, Email> */
    private function sent(): Collection
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($message) => $message->getOriginalMessage());
    }

    private function receive(array $headers, string $text): void
    {
        static $n = 0;
        $headers += ['Message-ID' => '<cc' . (++$n) . '.' . bin2hex(random_bytes(4)) . '@example.com>'];
        $this->mailbox->unseen[] = Eml::make($headers, $text);
        $this->artisan('app:fetch-emails')->assertSuccessful();
    }

    private function guestTicket(): Ticket
    {
        $ticket = Ticket::query()->create(['subject' => 'Site down', 'status' => Ticket::STATUS_OPEN, 'priority' => 'low', 'department_id' => $this->support->id,
            'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'email', 'sender_vouched' => true]);
        TicketMessage::withoutEvents(fn () => TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'Help', 'via' => 'email']));

        return $ticket;
    }

    private function setCc(Ticket $ticket, array $emails): Testable
    {
        return Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])
            ->callAction(TestAction::make('cc')->schemaComponent('ticketActions', 'infolist'), ['emails' => $emails]);
    }

    public function test_staff_copy_addresses_once_each_never_one_of_ours(): void
    {
        $ticket = $this->guestTicket();

        $this->setCc($ticket, ['Dev@Example.com', 'dev@example.com', 'boss@example.com'])->assertHasNoActionErrors();
        $this->assertSame(['boss@example.com', 'dev@example.com'], $ticket->ccs()->orderBy('email')->pluck('email')->all());

        $this->setCc($ticket, ['boss@example.com', 'SUPPORT@myip.test'])->assertNotified(__('An address of ours cannot be copied.'));
        $this->assertSame(['boss@example.com', 'dev@example.com'], $ticket->ccs()->orderBy('email')->pluck('email')->all(), 'nothing saved');
        $this->setCc($ticket, ['not an address'])->assertHasActionErrors();
        $this->setCc($ticket, ['boss@example.com']);
        $this->assertSame(['boss@example.com'], $ticket->ccs()->pluck('email')->all(), 'one removed');
        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])->assertSee('boss@example.com');
    }

    public function test_a_staff_reply_goes_to_each_copied_address_its_own_mail_without_the_guests_link(): void
    {
        $ticket = $this->guestTicket();
        TicketCc::query()->create(['ticket_id' => $ticket->id, 'email' => 'dev@example.com']);
        TicketCc::query()->create(['ticket_id' => $ticket->id, 'email' => 'MARIA@example.com']); // the owner, copied too: one mail
        $before = $this->sent()->count();

        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => $this->staff->id, 'message' => 'Fixed it']);

        $mails = $this->sent()->slice($before)->values();
        $this->assertSame(['maria@example.com', 'dev@example.com'], $mails->map(fn (Email $mail) => $mail->getTo()[0]->getAddress())->all());
        $copy = $mails[1];
        $this->assertStringContainsString('Fixed it', (string) $copy->getHtmlBody());
        $this->assertStringNotContainsString('/t/', (string) $copy->getHtmlBody(), 'the guest\'s link is the guest\'s');
        $this->assertSame('', $copy->getTo()[0]->getName(), 'not named as the guest');
        $this->assertNotSame($mails[0]->getHeaders()->get('Message-ID')?->getBodyAsString(), $copy->getHeaders()->get('Message-ID')?->getBodyAsString());
        $this->assertSame(['maria@example.com', 'dev@example.com'], TicketMailId::query()->where('kind', TicketMailId::REPLY)->orderBy('id')->pluck('recipient')->all());

        $count = $this->sent()->count();
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => $this->staff->id, 'message' => 'Note', 'is_internal' => true]);
        $this->assertSame($count, $this->sent()->count(), 'a note to no one');
    }

    public function test_a_copied_address_answers_into_the_ticket_as_itself(): void
    {
        $ticket = $this->guestTicket();
        TicketCc::query()->create(['ticket_id' => $ticket->id, 'email' => 'dev@example.com']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => $this->staff->id, 'message' => 'Fixed it']);
        $key = TicketMailId::query()->where('recipient', 'dev@example.com')->sole()->message_id;

        $this->receive(['From' => 'Dev <DEV@example.com>', $this->pass, 'In-Reply-To' => "<{$key}>", 'Subject' => 'Re: Site down'], 'The DNS is mine, done');

        $reply = $ticket->messages()->latest('id')->first();
        $this->assertSame(['The DNS is mine, done', 'dev@example.com', null], [$reply->message, $reply->sender_email, $reply->user_id]);
        $this->assertSame(Ticket::STATUS_CUSTOMER_REPLY, $ticket->fresh()->status);
        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])->assertSee('dev@example.com')->assertSee('CC');
    }

    public function test_an_address_not_copied_or_not_vouched_for_waits_in_the_quarantine(): void
    {
        $ticket = $this->guestTicket();
        TicketCc::query()->create(['ticket_id' => $ticket->id, 'email' => 'dev@example.com']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => $this->staff->id, 'message' => 'Fixed it']);
        $key = TicketMailId::query()->where('recipient', 'dev@example.com')->sole()->message_id;
        $count = $ticket->messages()->count();

        $this->receive(['From' => 'eve@example.com', $this->pass, 'In-Reply-To' => "<{$key}>"], 'Me too');
        $this->assertSame('not_owner', TicketQuarantine::query()->latest('id')->first()->reason);
        $this->receive(['From' => 'dev@example.com', 'Authentication-Results: mymail.myip.test; dmarc=fail header.from=example.com', 'In-Reply-To' => "<{$key}>"], 'Forged');
        $this->assertSame('not_authenticated', TicketQuarantine::query()->latest('id')->first()->reason);
        $this->assertSame($count, $ticket->messages()->count());
    }

    public function test_a_mails_own_cc_never_copies_anyone(): void
    {
        $this->receive(['From' => 'Maria <maria@example.com>', $this->pass, 'Subject' => 'New', 'Cc' => 'boss@example.com, dev@example.com'], 'Hello');

        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame(0, TicketCc::query()->count());
    }

    public function test_the_customer_sees_who_wrote_from_a_copied_address(): void
    {
        $owner = User::factory()->create();
        $ticket = Ticket::query()->create(['client_id' => $owner->client()->id, 'subject' => 'Q', 'status' => Ticket::STATUS_OPEN, 'priority' => 'low']);
        TicketMessage::withoutEvents(fn () => TicketMessage::query()->create(['ticket_id' => $ticket->id, 'sender_email' => 'dev@example.com', 'message' => 'From the developer', 'via' => 'email']));

        $this->livewireAs($owner)->test(TicketShow::class, ['ticket' => $ticket])->assertSee('dev@example.com')->assertSee('From the developer');
    }

    public function test_a_merge_carries_the_copied_addresses_once(): void
    {
        $kept = $this->guestTicket();
        $duplicate = $this->guestTicket();
        TicketCc::query()->create(['ticket_id' => $kept->id, 'email' => 'dev@example.com']);
        TicketCc::query()->create(['ticket_id' => $duplicate->id, 'email' => 'dev@example.com']);
        TicketCc::query()->create(['ticket_id' => $duplicate->id, 'email' => 'boss@example.com']);

        app(MergeTickets::class)->handle($duplicate, $kept, $this->staff);

        $this->assertSame(['boss@example.com', 'dev@example.com'], $kept->ccs()->orderBy('email')->pluck('email')->all());
        $this->assertSame(0, $duplicate->ccs()->count());
    }

    public function test_whmcs_cc_of_an_open_ticket_comes_as_copied_addresses_when_it_is_first_brought(): void
    {
        $whmcs = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $whmcs->exec('CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, tid TEXT, did INT, userid INT, name TEXT, email TEXT, date TEXT, title TEXT, message TEXT,
            status TEXT, urgency TEXT, admin TEXT, attachment TEXT, lastreply TEXT, merged_ticket_id INT DEFAULT 0, cc TEXT)');
        $whmcs->exec('CREATE TABLE tblticketreplies (id INTEGER PRIMARY KEY, tid INT, userid INT, name TEXT, email TEXT, date TEXT, message TEXT, admin TEXT, attachment TEXT)');
        $whmcs->exec("INSERT INTO tbltickets VALUES (5, '123456', 0, 0, 'Maria', 'maria@example.com', '2025-03-01 10:00:00', 'Help', 'Hi', 'Customer-Reply', 'Medium', '', '',
            '2025-03-01 10:00:00', 0, 'Dev@Example.com, maria@example.com,support@myip.test; not-an-address, boss@example.com')");
        // A closed one's CC stays in WHMCS: WHMCS filled it also from incoming mail's Cc (owner, 2026-10-20).
        $whmcs->exec("INSERT INTO tbltickets VALUES (6, '123457', 0, 0, 'Maria', 'maria@example.com', '2025-03-01 10:00:00', 'Old', 'Hi', 'Closed', 'Medium', '', '',
            '2025-03-01 10:00:00', 0, 'stranger@example.com')");

        $counts = (new WhmcsTicketImport($whmcs))->run();
        $ticket = Ticket::query()->where('whmcs_id', 5)->sole();
        $this->assertSame(2, $counts['ccs']);
        $this->assertSame(0, Ticket::query()->where('whmcs_id', 6)->sole()->ccs()->count(), 'a closed ticket\'s CC is not brought');
        $this->assertSame(['boss@example.com', 'dev@example.com'], $ticket->ccs()->orderBy('email')->pluck('email')->all());

        $ticket->ccs()->where('email', 'boss@example.com')->delete();
        (new WhmcsTicketImport($whmcs))->run();
        $this->assertSame(['dev@example.com'], $ticket->ccs()->pluck('email')->all(), 'a re-run never brings back one removed here');
    }
}
