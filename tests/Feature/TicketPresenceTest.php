<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Admin\Resources\TicketResource\Pages\ListTickets;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Tickets\TicketPresence;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Tests\TestCase;

/** Who of the staff views a ticket and who writes in it, never sticking as WHMCS's «X is replying» (docs/tickets.md §6ν). */
class TicketPresenceTest extends TestCase
{
    use RefreshDatabase;

    private Staff $maria;

    private Staff $nikos;

    private User $customer;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test', 'settings.default_currency' => 'USD']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->maria = Staff::factory()->admin()->create(['first_name' => 'Maria', 'last_name' => 'Staff']);
        $this->nikos = Staff::factory()->admin()->create(['first_name' => 'Nikos', 'last_name' => 'Staff']);
        $this->customer = User::factory()->create();
        $this->ticket = Ticket::query()->create(['client_id' => $this->customer->client()->id, 'subject' => 'Site down', 'status' => Ticket::STATUS_OPEN, 'priority' => 'low']);
        $this->customerWrites('Help');
    }

    private function customerWrites(string $text): TicketMessage
    {
        // Without events: no mail, no status moved; only the message matters here.
        return TicketMessage::withoutEvents(fn () => $this->ticket->messages()->create(['user_id' => $this->customer->id, 'message' => $text]));
    }

    private function page(Staff $staff): Testable
    {
        return $this->livewireAs($staff)->test(EditTicket::class, ['record' => $this->ticket->getRouteKey()]);
    }

    /** @return list<array{staff_id: int, name: string, state: string, typed_at: mixed}> */
    private function others(Staff $staff): array
    {
        return TicketPresence::onTicket($this->ticket, $staff->id);
    }

    public function test_an_open_page_says_viewing_to_others_never_to_its_member(): void
    {
        $this->page($this->maria);

        $this->assertSame([['staff_id' => $this->maria->id, 'name' => 'Maria Staff', 'state' => 'viewing', 'typed_at' => null]], $this->others($this->nikos));
        $this->assertSame([], $this->others($this->maria));
        $this->page($this->nikos)->assertSee(__(':name is viewing this ticket', ['name' => 'Maria Staff']));
        $this->page($this->maria)->assertDontSee(__(':name is viewing this ticket', ['name' => 'Maria Staff']));
    }

    public function test_writing_needs_text_and_recent_typing_and_old_text_is_an_unsent_reply_with_its_age(): void
    {
        $maria = $this->page($this->maria);
        // Typing that left the box empty is not writing.
        $maria->call('presenceBeat', true, true);
        $this->assertSame('viewing', $this->others($this->nikos)[0]['state']);

        $maria->set('data.message', 'We are looking')->call('presenceBeat', true, true);
        $this->assertSame('writing', $this->others($this->nikos)[0]['state']);
        $this->page($this->nikos)->assertSee(__(':name is writing a reply', ['name' => 'Maria Staff']));
        // Nikos's page took over the test's signed-in member.
        $this->actingAs($this->maria);

        // Still beating, no typing for three minutes: an unsent reply, with when it was last typed.
        $this->travel(3)->minutes();
        $maria->call('presenceBeat', true, false);
        $this->assertSame('draft', $this->others($this->nikos)[0]['state']);
        $this->page($this->nikos)->assertSee(__(':name has an unsent reply, last typed :time', ['name' => 'Maria Staff', 'time' => now()->subMinutes(3)->diffForHumans()]));
    }

    public function test_text_that_appears_without_typing_counts_as_written_now(): void
    {
        // A predefined reply or an MCP draft lands in the box: the member is at it.
        $this->page($this->maria)->set('data.message', 'Template text')->call('presenceBeat', true, false);

        $this->assertSame('writing', $this->others($this->nikos)[0]['state']);
    }

    public function test_it_expires_on_its_own_when_the_page_stops_beating(): void
    {
        $this->page($this->maria)->set('data.message', 'Half a reply')->call('presenceBeat', true, true);
        $this->assertSame('writing', $this->others($this->nikos)[0]['state']);

        // A reply waits longer for a beat (a tab behind another wakes once a minute), then it goes too.
        $this->travel(TicketPresence::ALIVE + 1)->seconds();
        $this->assertSame('writing', $this->others($this->nikos)[0]['state']);
        $this->travel(TicketPresence::DRAFT_ALIVE - TicketPresence::ALIVE)->seconds();

        $this->assertSame([], $this->others($this->nikos));
        $this->page($this->nikos)->assertDontSee('Maria Staff');
    }

    public function test_viewing_expires_after_a_few_missed_beats(): void
    {
        $this->page($this->maria);
        $this->travel(TicketPresence::ALIVE + 1)->seconds();

        $this->assertSame([], $this->others($this->nikos));
    }

    public function test_an_attachment_alone_is_a_reply_being_written_and_holds_the_send(): void
    {
        $maria = $this->page($this->maria);
        $maria->set('data.attachments', ['one' => 'tickets/uploads/screenshot.png'])->call('presenceBeat', true, false);
        $this->customerWrites('Sent you the logs');
        $maria->call('presenceBeat', true, false);

        $this->assertSame('writing', $this->others($this->nikos)[0]['state']);
        $maria->call('send')->assertNotified(__('A new reply came in while you were writing.'));
        $this->assertSame(0, TicketMessage::query()->where('staff_id', $this->maria->id)->count());
    }

    public function test_a_draft_holds_what_was_seen_across_beats_and_each_new_reply_stops_once_more(): void
    {
        $maria = $this->page($this->maria);
        $maria->set('data.message', 'Answer');
        $maria->call('presenceBeat', true, true);
        $this->customerWrites('First extra');
        $maria->call('presenceBeat', true, false)->call('presenceBeat', true, false)
            ->assertSee(__('New in this ticket since you started writing:'));

        $maria->call('send')->assertNotified(__('A new reply came in while you were writing.'));
        $this->customerWrites('Second extra');
        $maria->call('send');
        $this->assertSame(0, TicketMessage::query()->where('staff_id', $this->maria->id)->count());

        $maria->call('send')->assertHasNoErrors();
        $this->assertSame(1, TicketMessage::query()->where('staff_id', $this->maria->id)->count());
    }

    public function test_a_members_name_is_escaped_in_the_list(): void
    {
        $this->maria->update(['first_name' => '<b>Bold</b>']);
        $this->page($this->maria);

        $this->livewireAs($this->nikos)->test(ListTickets::class)->set('activeTab', 'all')
            ->assertDontSee('<b>Bold</b>', false)
            ->assertSee('&lt;b&gt;Bold&lt;/b&gt;', false);
    }

    public function test_a_tab_behind_another_or_idle_says_nothing_unless_it_holds_a_reply(): void
    {
        $maria = $this->page($this->maria);
        $maria->call('presenceBeat', false, false);
        $this->assertSame([], $this->others($this->nikos));

        // A reply left in a tab behind another still matters: it shows, with its age.
        $maria->set('data.message', 'Checking the server')->call('presenceBeat', false, false);
        $this->assertSame('writing', $this->others($this->nikos)[0]['state']);
    }

    public function test_two_tabs_of_one_member_are_two_pages_and_the_strongest_shows(): void
    {
        $this->page($this->maria)->set('data.message', 'Draft in tab one')->call('presenceBeat', true, true);
        $this->page($this->maria)->call('presenceBeat', true, false);

        $this->assertSame(2, DB::table('ticket_presences')->where('staff_id', $this->maria->id)->count());
        $others = $this->others($this->nikos);
        $this->assertCount(1, $others);
        $this->assertSame('writing', $others[0]['state']);
    }

    public function test_sending_the_reply_ends_writing_at_once(): void
    {
        $maria = $this->page($this->maria);
        $maria->set('data.message', 'Fixed, please check')->call('presenceBeat', true, true);

        $maria->call('send')->assertHasNoErrors();

        $this->assertSame(1, TicketMessage::query()->where('staff_id', $this->maria->id)->count());
        $this->assertSame('viewing', $this->others($this->nikos)[0]['state']);
    }

    public function test_leaving_removes_the_members_own_page_only(): void
    {
        $maria = $this->page($this->maria);
        $page = $maria->id();
        $this->assertSame(1, DB::table('ticket_presences')->count());

        // Another member's beacon with Maria's page id removes nothing.
        $this->actingAs($this->nikos)->post(route('filament.admin.ticket-presence.leave'), ['page' => $page])->assertNoContent();
        $this->assertSame(1, DB::table('ticket_presences')->count());

        $this->actingAs($this->maria)->post(route('filament.admin.ticket-presence.leave'), ['page' => $page])->assertNoContent();
        $this->assertSame(0, DB::table('ticket_presences')->count());
    }

    public function test_the_leave_route_is_staffs_only(): void
    {
        $page = $this->page($this->maria)->id();
        auth('staff')->logout();

        $this->post(route('filament.admin.ticket-presence.leave'), ['page' => $page])->assertRedirect();
        $this->actingAs($this->customer)->post(route('filament.admin.ticket-presence.leave'), ['page' => $page])->assertRedirect();
        $this->assertSame(1, DB::table('ticket_presences')->count());
    }

    public function test_a_page_cannot_claim_another_members_row(): void
    {
        $page = $this->page($this->maria)->id();

        TicketPresence::beat($this->ticket, $this->nikos, $page, true, true, true);

        $row = DB::table('ticket_presences')->where('page', $page)->first();
        $this->assertSame($this->maria->id, (int) $row->staff_id);
        $this->assertSame(0, (int) $row->has_draft);
    }

    public function test_rows_of_pages_gone_without_a_word_are_pruned(): void
    {
        $this->page($this->maria);
        $this->travel(TicketPresence::PRUNE_AFTER + 1)->seconds();

        $this->page($this->nikos);

        $this->assertSame([$this->nikos->id], DB::table('ticket_presences')->pluck('staff_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_a_reply_that_came_in_while_writing_stops_the_first_send_and_the_second_sends(): void
    {
        $maria = $this->page($this->maria);
        $maria->set('data.message', 'Restart the service');
        $maria->call('presenceBeat', true, true);
        $this->customerWrites('Never mind, it works now');

        $maria->call('presenceBeat', true, false)
            ->assertSee(__('New in this ticket since you started writing:'));

        $maria->call('send')->assertNotified(__('A new reply came in while you were writing.'));
        $this->assertSame(0, TicketMessage::query()->where('staff_id', $this->maria->id)->count());
        $this->assertSame('Restart the service', $maria->get('data.message'));

        $maria->call('send')->assertHasNoErrors();
        $this->assertSame(1, TicketMessage::query()->where('staff_id', $this->maria->id)->count());
    }

    public function test_a_colleagues_reply_or_note_stops_the_send_too(): void
    {
        $maria = $this->page($this->maria);
        $maria->set('data.message', 'My answer');
        $maria->call('presenceBeat', true, true);
        TicketMessage::withoutEvents(fn () => $this->ticket->messages()->create(['staff_id' => $this->nikos->id, 'message' => 'Called them', 'is_internal' => true]));

        $maria->call('send')->assertNotified(__('A new reply came in while you were writing.'));

        $this->assertSame(0, TicketMessage::query()->where('staff_id', $this->maria->id)->count());
    }

    public function test_what_came_in_before_writing_started_does_not_stop_the_send(): void
    {
        $maria = $this->page($this->maria);
        $this->customerWrites('One more detail');
        // The box was empty at the beat: the new message was on the page before they wrote.
        $maria->call('presenceBeat', true, false);

        $maria->set('data.message', 'Thanks, done')->call('send')->assertHasNoErrors();

        $this->assertSame(1, TicketMessage::query()->where('staff_id', $this->maria->id)->count());
    }

    public function test_the_members_own_message_from_another_tab_does_not_stop_the_send(): void
    {
        $maria = $this->page($this->maria);
        $maria->set('data.message', 'Second thought');
        $maria->call('presenceBeat', true, true);
        TicketMessage::withoutEvents(fn () => $this->ticket->messages()->create(['staff_id' => $this->maria->id, 'message' => 'From the other tab']));

        $maria->call('send')->assertHasNoErrors();

        $this->assertSame(2, TicketMessage::query()->where('staff_id', $this->maria->id)->count());
    }

    public function test_a_note_is_not_stopped_by_a_new_reply(): void
    {
        $maria = $this->page($this->maria);
        $maria->set('data.message', 'Note to self');
        $maria->call('presenceBeat', true, true);
        $this->customerWrites('Hello?');

        $maria->call('note')->assertHasNoErrors();

        $this->assertTrue(TicketMessage::query()->where('staff_id', $this->maria->id)->sole()->is_internal);
    }

    public function test_the_list_shows_who_else_views_or_writes_under_the_subject(): void
    {
        $other = Ticket::query()->create(['client_id' => $this->customer->client()->id, 'subject' => 'Billing question', 'status' => Ticket::STATUS_OPEN, 'priority' => 'low']);
        $this->page($this->maria)->set('data.message', 'Working on it')->call('presenceBeat', true, true);
        $this->livewireAs($this->nikos)->test(EditTicket::class, ['record' => $other->getRouteKey()]);

        $this->livewireAs($this->nikos)->test(ListTickets::class)->set('activeTab', 'all')
            ->assertSee(__(':name is writing', ['name' => 'Maria Staff']))
            ->assertDontSee(__(':name is viewing', ['name' => 'Nikos Staff']));
        $this->livewireAs($this->maria)->test(ListTickets::class)->set('activeTab', 'all')
            ->assertSee(__(':name is viewing', ['name' => 'Nikos Staff']))
            ->assertDontSee(__(':name is writing', ['name' => 'Maria Staff']));
    }

    public function test_the_customer_sees_nothing_of_it(): void
    {
        $this->page($this->maria)->set('data.message', 'Working on it')->call('presenceBeat', true, true);

        $this->livewireAs($this->customer)->test(TicketShow::class, ['ticket' => $this->ticket])
            ->assertSee('Help')
            ->assertDontSee('Maria Staff')
            ->assertDontSee('data-ticket-presence', false);
    }
}
