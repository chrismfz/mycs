<?php

namespace Tests\Unit;

use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketStatusUpdateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic unit test example.
     */
    public function test_ticket_status_gets_updated_on_new_message(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $user->client()->id, 'status' => 'open']);
        TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $user->id]);

        $this->assertEquals('open', $ticket->fresh()->status);

        // A reply by staff (docs/accounts.md, PR 1: told by its author, staff_id)
        $staff = Staff::factory()->admin()->create();
        TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'user_id' => null, 'staff_id' => $staff->id]);

        // Assert that the ticket status is updated to 'replied'
        $this->assertEquals('replied', $ticket->fresh()->status);
    }
}
