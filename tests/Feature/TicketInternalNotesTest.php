<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Livewire\Tickets\Guest;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Tickets\GuestLinks;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Internal notes from the admin (docs/tickets.md §6θ): staff's only, never mailed, no status moved. */
class TicketInternalNotesTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test', 'settings.default_currency' => 'USD']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $this->actingAs($this->staff, 'staff');
    }

    private function sentCount(): int
    {
        return app('mailer')->getSymfonyTransport()->messages()->count();
    }

    private function customerTicket(User $user): Ticket
    {
        $ticket = Ticket::query()->create(['client_id' => $user->client()->id, 'subject' => 'Site down', 'status' => Ticket::STATUS_OPEN, 'priority' => 'low']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'message' => 'Help']);

        return $ticket;
    }

    public function test_a_note_is_kept_for_staff_mailed_to_no_one_and_moves_no_status(): void
    {
        $ticket = $this->customerTicket(User::factory()->create());
        $before = $this->sentCount();

        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])
            ->fillForm(['message' => 'Called the customer, waits for DNS.'])->call('note')->assertHasNoErrors()
            ->assertSee('Called the customer, waits for DNS.')->assertSee(__('Internal note'));

        $note = TicketMessage::query()->latest('id')->first();
        $this->assertTrue($note->is_internal);
        $this->assertSame($this->staff->id, $note->staff_id);
        $this->assertSame(Ticket::STATUS_OPEN, $ticket->fresh()->status, 'still waiting on our reply');
        $this->assertSame($before, $this->sentCount(), 'no mail to the customer');
        $this->assertFalse(TicketMailId::query()->where('ticket_message_id', $note->id)->exists());
    }

    public function test_send_still_replies_to_the_customer(): void
    {
        $ticket = $this->customerTicket(User::factory()->create());

        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])->fillForm(['message' => 'Fixed.'])->call('send')->assertHasNoErrors();

        $this->assertFalse(TicketMessage::query()->latest('id')->first()->is_internal);
        $this->assertSame(Ticket::STATUS_ANSWERED, $ticket->fresh()->status);
    }

    public function test_an_empty_note_is_refused(): void
    {
        $ticket = $this->customerTicket(User::factory()->create());

        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])->call('note')->assertHasErrors();
        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_neither_the_customer_nor_the_guest_sees_a_note(): void
    {
        $user = User::factory()->create();
        $ticket = $this->customerTicket($user);
        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])->fillForm(['message' => 'Secret staff remark'])->call('note');

        $this->livewireAs($user)->test(TicketShow::class, ['ticket' => $ticket])->assertSee('Help')->assertDontSee('Secret staff remark');

        $guest = Ticket::query()->create(['subject' => 'Site down', 'status' => Ticket::STATUS_OPEN, 'priority' => 'low', 'guest_name' => 'Maria',
            'guest_email' => 'maria@example.com', 'opened_via' => 'form', 'sender_vouched' => true]);
        TicketMessage::query()->create(['ticket_id' => $guest->id, 'message' => 'From the form', 'via' => 'form']);
        Filament::setCurrentPanel(Filament::getPanel('admin')); // the customer's test left the portal's
        $this->actingAs($this->staff, 'staff');
        Livewire::test(EditTicket::class, ['record' => $guest->getRouteKey()])->fillForm(['message' => 'Guest secret remark'])->call('note');
        $token = basename((string) parse_url(GuestLinks::issue($guest), PHP_URL_PATH));

        Livewire::test(Guest::class, ['token' => $token])->assertSee('From the form')->assertDontSee('Guest secret remark');
    }
}
