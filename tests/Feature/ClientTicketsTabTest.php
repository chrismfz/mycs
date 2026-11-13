<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\ShowTickets;
use App\Admin\Resources\TicketResource;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A client's Tickets tab (owner 2026-11-13): the number and subject as the link to the ticket, when and how it was
 * opened, its last reply (whose, how many), who has it; the open ones at first, the latest reply on top.
 */
class ClientTicketsTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tab_shows_each_ticket_and_links_to_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->freezeTime();
        $client = User::factory()->create()->client();
        $other = User::factory()->create()->client();
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.view', 'admin.tickets.viewAny', 'admin.tickets.create', 'admin.tickets.update'])->create();
        $say = fn (Ticket $ticket, array $attributes, $at) => $ticket->messages()->create($attributes)->forceFill(['created_at' => $at])->saveQuietly();
        $make = fn (array $attributes) => Ticket::query()->create($attributes + ['client_id' => $client->id, 'priority' => 'medium', 'status' => Ticket::STATUS_OPEN]);

        $waiting = $make(['subject' => 'No mail', 'status' => Ticket::STATUS_CUSTOMER_REPLY, 'opened_via' => 'email', 'assigned_to' => $staff->id]);
        $say($waiting, ['user_id' => $client->ownerGrant->user_id, 'message' => 'Help'], now()->subHours(5));
        $say($waiting, ['staff_id' => $staff->id, 'message' => 'Looking'], now()->subHours(4));
        $say($waiting, ['staff_id' => $staff->id, 'message' => 'Note', 'is_internal' => true], now()->subHours(3));
        $say($waiting, ['user_id' => $client->ownerGrant->user_id, 'message' => 'Still'], now()->subHours(2));
        $answered = $make(['subject' => 'DNS', 'status' => Ticket::STATUS_ANSWERED, 'opened_via' => 'portal']);
        $say($answered, ['staff_id' => $staff->id, 'message' => 'Done'], now()->subDay());
        $closed = $make(['subject' => 'Old', 'status' => Ticket::STATUS_CLOSED]);
        $say($closed, ['staff_id' => $staff->id, 'message' => 'Bye'], now()->subDays(70));
        $notTheirs = Ticket::query()->create(['client_id' => $other->id, 'subject' => 'Theirs', 'priority' => 'low', 'status' => Ticket::STATUS_OPEN]);
        // A reply moves its status: each one's as the test wants it, once its messages are written.
        foreach ([[$waiting, Ticket::STATUS_CUSTOMER_REPLY], [$answered, Ticket::STATUS_ANSWERED], [$closed, Ticket::STATUS_CLOSED]] as [$ticket, $status]) {
            Ticket::query()->whereKey($ticket->id)->update(['status' => $status]);
        }

        $page = $this->livewireAs($staff)->test(ShowTickets::class, ['record' => $client->id]);
        // The open ones at first, the latest reply on top; the closed one with «all».
        $page->assertCanSeeTableRecords([$waiting, $answered], inOrder: true)
            ->assertCanNotSeeTableRecords([$closed, $notTheirs])
            ->assertTableColumnStateSet('subject', '#' . $waiting->reference . ' – No mail', $waiting)
            // The last public reply, not the internal note after the staff's: the client's, 3 messages without the note.
            ->assertTableColumnFormattedStateSet('last_reply_at', '2h 0m', $waiting)
            ->assertSee(__('client') . ' · 3 messages')
            ->assertSee(__('staff') . ' · 1 message')
            ->assertSee(__('by email'))
            ->assertSee($staff->name)
            ->assertActionHasUrl(TestAction::make('newTicket'), TicketResource::getUrl('create', ['client_id' => $client->id]));
        $this->assertSame(TicketResource::getUrl('edit', ['record' => $waiting]), $page->instance()->getTable()->getRecordUrl($waiting));

        $page->removeTableFilter('open')->assertCanSeeTableRecords([$waiting, $answered, $closed], inOrder: true);
        $page->searchTable($answered->reference)->assertCanSeeTableRecords([$answered])->assertCanNotSeeTableRecords([$waiting, $closed]);
    }
}
