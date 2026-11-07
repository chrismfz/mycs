<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketResource\Pages\CreateTicket;
use App\Models\Client;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Independent review of the tickets-mail sweep fixes (TICKETS-MAIL-1 to 6).
 *
 * REV-TM-1 (TICKETS-MAIL-2 left half-done): the portal, the client reply and the staff reply are now held to
 * TicketMessage::MAX_LENGTH and the ticket subject to 255, but the staff «New ticket» page (TicketResource form,
 * CreateTicket) is not: its subject TextInput and «Initial Message» MarkdownEditor have no limit, and
 * CreateTicket::handleRecordCreation writes the ticket and its first message outside a transaction. On MariaDB
 * (TEXT 65,535 bytes, varchar(255), strict mode) a long first message gives a 500 and leaves a ticket with no message,
 * the very orphan the portal fix closes.
 */
class RevTicketsMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['*']])->id]);
    }

    public function test_rev_tm_1_the_staff_new_ticket_page_refuses_a_message_too_long_for_its_column(): void
    {
        $client = Client::factory()->create();

        $this->livewireAs($this->staff())->test(CreateTicket::class)
            ->fillForm([
                'subject' => 'Long log',
                'status' => 'open',
                'priority' => 'medium',
                'client_id' => $client->id,
                'message' => str_repeat('α', TicketMessage::MAX_LENGTH + 1),
            ])
            ->call('create')
            ->assertHasFormErrors(['message']);

        $this->assertSame(0, Ticket::query()->count(), 'no ticket written for a message its column cannot hold');
    }

    public function test_rev_tm_1_the_staff_new_ticket_page_refuses_a_subject_over_255(): void
    {
        $client = Client::factory()->create();

        $this->livewireAs($this->staff())->test(CreateTicket::class)
            ->fillForm([
                'subject' => str_repeat('s', 256),
                'status' => 'open',
                'priority' => 'medium',
                'client_id' => $client->id,
                'message' => 'Hello',
            ])
            ->call('create')
            ->assertHasFormErrors(['subject']);
    }
}
