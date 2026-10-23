<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketResource;
use App\Admin\Resources\TicketResource\Pages\ListTickets;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** «Last Reply» in staff's ticket list (WHMCS's, owner 2026-10-23): how long since the last reply, never a note; sorted by. */
class TicketLastReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 18:00:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ticket(string $subject, array $messages): Ticket
    {
        $ticket = Ticket::query()->create(['subject' => $subject, 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium',
            'guest_name' => 'Maria Guest', 'guest_email' => 'maria@guest.example', 'opened_via' => 'form']);
        foreach ($messages as [$at, $internal]) {
            $message = $ticket->messages()->create(['message' => 'Text', 'is_internal' => $internal]);
            DB::table('ticket_messages')->where('id', $message->id)->update(['created_at' => $at]);
        }

        return $ticket;
    }

    public function test_how_long_since_the_last_reply_never_a_note_and_sorted_by_it(): void
    {
        $waiting = $this->ticket('Waiting long', [['2026-10-20 10:00:00', false]]);
        $recent = $this->ticket('Answered lately', [['2026-10-23 09:17:00', false], ['2026-10-23 17:59:00', true]]);

        Livewire::test(ListTickets::class)
            ->assertSee('3d 8h')->assertSee('8h 43m')
            ->sortTable('last_reply_at')->assertCanSeeTableRecords([$waiting, $recent], inOrder: true);
        $this->assertSame(['12m', '—'], [TicketResource::sinceShort('2026-10-23 17:48:00'), TicketResource::sinceShort(null)]);
        $this->assertSame(TicketMessage::class, $recent->messages()->getRelated()::class);
    }
}
