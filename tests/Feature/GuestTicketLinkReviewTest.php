<?php

namespace Tests\Feature;

use App\Livewire\Tickets\Guest;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Support\Tickets\GuestLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/** Code review of PR #252 (Γ, the guest's ticket link): failing tests for what it found. */
class GuestTicketLinkReviewTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.default_currency' => 'USD']);
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null], ['value' => '0']);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
    }

    private function guestTicket(array $attributes = []): Ticket
    {
        $ticket = Ticket::query()->create($attributes + ['subject' => 'Site down', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium', 'department_id' => $this->support->id,
            'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'email', 'sender_vouched' => true]);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'Help me', 'via' => TicketMessage::VIA_EMAIL]);

        return $ticket;
    }

    private function token(Ticket $ticket): string
    {
        return basename(parse_url(GuestLinks::issue($ticket), PHP_URL_PATH));
    }

    /** The page is 404 while tickets are disabled; its files are not. */
    public function test_files_by_the_link_are_not_served_while_tickets_are_disabled(): void
    {
        $ticket = $this->guestTicket();
        $message = TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'See file']);
        File::ensureDirectoryExists(storage_path('app/tickets/uploads'));
        file_put_contents(storage_path('app/tickets/uploads/rev-c.txt'), 'x');
        $file = $message->attachments()->create(['path' => 'tickets/uploads/rev-c.txt', 'filename' => 'a.txt', 'mime_type' => 'text/plain', 'filesize' => 1]);
        $token = $this->token($ticket);
        Setting::query()->updateOrCreate(['key' => 'tickets_disabled', 'settingable_type' => null], ['value' => '1']);
        config(['settings.tickets_disabled' => true]);

        $this->get(route('tickets.guest', $token))->assertNotFound();
        try {
            $this->get(route('tickets.guest.attachment', ['token' => $token, 'attachment' => $file->uuid]))->assertNotFound();
        } finally {
            @unlink($file->localPath);
        }
    }

    /** e() before Markdown escapes twice: a code span shows «&lt;» to the guest (the client area does not). */
    public function test_a_message_with_code_is_not_escaped_twice(): void
    {
        $ticket = $this->guestTicket();
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'Run `a < b && c`']);

        $this->get(route('tickets.guest', $this->token($ticket)))->assertOk()
            ->assertDontSee('&amp;lt;', false)->assertDontSee('&amp;amp;', false);
    }

    /** As the contact form (#251): an answer of format/control characters only is no answer. */
    public function test_an_answer_of_invisible_characters_only_is_refused(): void
    {
        $ticket = $this->guestTicket();

        Livewire::test(Guest::class, ['token' => $this->token($ticket)])->set('message', "\u{200B}\u{2066}\u{0007}")->call('reply');

        $this->assertSame(1, $ticket->messages()->count());
    }

    /**
     * A From our server did not vouch for: the mail carries nothing the sender wrote (#251), but the link in it opens a
     * page with the sender's subject, name and words: a forger's text on our site, mailed to someone else's inbox.
     */
    public function test_a_link_to_an_unvouched_senders_ticket_does_not_show_what_the_sender_wrote(): void
    {
        $ticket = $this->guestTicket(['sender_vouched' => false, 'subject' => 'CHEAP-PILLS', 'guest_name' => 'Spammer']);

        $this->get(route('tickets.guest', $this->token($ticket)))->assertOk()
            ->assertDontSee('CHEAP-PILLS')->assertDontSee('Spammer');
    }
}
