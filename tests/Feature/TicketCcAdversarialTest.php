<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketCc;
use App\Models\TicketDepartment;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use App\Models\TicketQuarantine;
use App\Support\Tickets\QuarantineActions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\Tests\Eml;
use Symfony\Component\Mime\Email;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/** Adversarial review of #267 (CC on a ticket, docs/tickets.md §6λ). */
class TicketCcAdversarialTest extends TestCase
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
        $this->support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'inbox@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->create(['first_name' => 'Nikos', 'last_name' => 'Staff', 'email' => 'nikos@myip.test',
            'role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
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
        $headers += ['Message-ID' => '<adv' . (++$n) . '.' . bin2hex(random_bytes(4)) . '@example.com>'];
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

    /** A copied address's key, after one staff reply. */
    private function ccKey(Ticket $ticket, string $email): string
    {
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => $this->staff->id, 'message' => 'Fixed it']);

        return TicketMailId::query()->where('recipient', $email)->latest('id')->firstOrFail()->message_id;
    }

    /**
     * The router's «our address» is every department's email and IMAP login, plus-tags and case folded
     * (TicketBlockedSender::base); the CC action only looks at departments' `email`, lowercased.
     */
    public function test_an_address_of_ours_cannot_be_copied_by_its_imap_login_or_a_plus_tag(): void
    {
        $ticket = $this->guestTicket();

        $this->setCc($ticket, ['inbox@myip.test']);
        $this->assertSame([], $ticket->ccs()->pluck('email')->all(), 'our IMAP login is ours too');
        $this->setCc($ticket, ['support+cc@myip.test']);
        $this->assertSame([], $ticket->ccs()->pluck('email')->all(), 'a plus-tagged department address is ours too');
    }

    /** Staff are told of a copied address's reply as if the guest (or the account) wrote it. */
    public function test_staff_told_of_a_copied_addresss_reply_are_told_who_wrote_it(): void
    {
        $this->support->notifyStaff()->sync([$this->staff->id]);
        $ticket = $this->guestTicket();
        TicketCc::query()->create(['ticket_id' => $ticket->id, 'email' => 'dev@example.com']);
        $key = $this->ccKey($ticket, 'dev@example.com');
        $before = $this->sent()->count();

        $this->receive(['From' => 'dev@example.com', $this->pass, 'In-Reply-To' => "<{$key}>", 'Subject' => 'Re: Site down'], 'Not the guest writing');

        $notice = $this->sent()->slice($before)->first(fn (Email $mail) => $mail->getTo()[0]->getAddress() === 'nikos@myip.test');
        $this->assertNotNull($notice, 'staff were told');
        $this->assertStringContainsString('dev@example.com', (string) $notice->getHtmlBody(), 'the notice names the copied address that wrote it');
    }

    /** A copied address's mail held in the quarantine (here: DMARC failed) and attached by staff loses who wrote it. */
    public function test_a_copied_addresss_quarantined_reply_attached_by_staff_keeps_its_address(): void
    {
        $ticket = $this->guestTicket();
        TicketCc::query()->create(['ticket_id' => $ticket->id, 'email' => 'dev@example.com']);
        $key = $this->ccKey($ticket, 'dev@example.com');

        $this->receive(['From' => 'dev@example.com', 'Authentication-Results: mymail.myip.test; dmarc=fail header.from=example.com', 'In-Reply-To' => "<{$key}>"], 'Held');
        $quarantine = TicketQuarantine::query()->latest('id')->firstOrFail();
        $this->assertSame('not_authenticated', $quarantine->reason);

        $message = app(QuarantineActions::class)->attach($quarantine, $ticket, $this->staff);

        $this->assertSame('dev@example.com', $message->sender_email, 'shown as the CC, not as the guest Maria');
    }

    /** The importer keeps at most 20 copied addresses a ticket; staff can copy any number, each a mail of ours per reply. */
    public function test_staff_cannot_copy_an_unbounded_number_of_addresses(): void
    {
        $ticket = $this->guestTicket();

        $this->setCc($ticket, array_map(fn ($i) => "list{$i}@example.com", range(1, 200)));

        $this->assertLessThanOrEqual(20, $ticket->ccs()->count(), 'one staff reply would mail 200 strangers');
    }
}
