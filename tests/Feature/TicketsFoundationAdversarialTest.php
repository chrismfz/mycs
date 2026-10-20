<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Livewire\Components\QuickSearch;
use App\Livewire\Tickets\Create as TicketCreate;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of PR A1 (tickets: guests, departments, statuses, reference). Failing tests are findings.
 */
class TicketsFoundationAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
    }

    private function guestTicket(array $attributes = []): Ticket
    {
        return Ticket::query()->create(array_merge(['subject' => 'Guest secret subject', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium',
            'guest_name' => 'Maria Guest', 'guest_email' => 'maria@guest.example', 'opened_via' => 'form'], $attributes));
    }

    private function asCustomer(User $user): void
    {
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $user->client()->id]));
    }

    private function asStaff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]);
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    /** FINDING: the portal ticket page lists every message, internal notes included (show.blade.php:9). */
    public function test_an_internal_note_is_never_shown_on_the_portal_ticket_page(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::query()->create(['client_id' => $user->client()->id, 'subject' => 'Mine', 'status' => 'open', 'priority' => 'low']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->create()->id,
            'message' => 'INTERNAL-customer-is-a-fraudster', 'is_internal' => true]);

        $this->asCustomer($user);
        $this->get(route('tickets.show', $ticket))->assertOk()->assertDontSee('INTERNAL-customer-is-a-fraudster');
    }

    /** FINDING: an internal note's attachment is downloadable by the ticket's owner (TicketMessageAttachmentPolicy:16). */
    public function test_an_internal_notes_attachment_is_not_downloadable_by_the_customer(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::query()->create(['client_id' => $user->client()->id, 'subject' => 'Mine', 'status' => 'open', 'priority' => 'low']);
        $note = TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->create()->id,
            'message' => 'note', 'is_internal' => true]);
        File::ensureDirectoryExists(storage_path('app/tickets/uploads'));
        file_put_contents(storage_path('app/tickets/uploads/adv-internal.txt'), 'internal');
        $attachment = $note->attachments()->create(['path' => 'tickets/uploads/adv-internal.txt', 'filename' => 'adv-internal.txt',
            'mime_type' => 'text/plain', 'filesize' => 8]);

        $this->assertFalse($user->can('view', $attachment), 'the customer may not read an internal note\'s file');
        @unlink(storage_path('app/tickets/uploads/adv-internal.txt'));
    }

    /** LOW: the page names the ticket by its hidden id in the breadcrumb (show.blade.php:2), not its reference. */
    public function test_the_portal_page_names_the_ticket_by_its_reference_only(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::query()->create(['client_id' => $user->client()->id, 'subject' => 'Mine', 'status' => 'open',
            'priority' => 'low', 'reference' => '777001']);

        $this->asCustomer($user);
        $this->get(route('tickets.show', $ticket))->assertOk()->assertSee('777001')->assertDontSee('#' . $ticket->id . ' · Mine');
    }

    /** HELD: a guest ticket, its messages and attachments reach no customer, also in «Login as client». */
    public function test_a_guest_ticket_is_closed_to_customers_by_id_attachment_livewire_and_impersonation(): void
    {
        $ticket = $this->guestTicket();
        $message = TicketMessage::query()->create(['ticket_id' => $ticket->id, 'user_id' => null, 'staff_id' => null, 'message' => 'guest words']);
        $attachment = $message->attachments()->create(['path' => 'tickets/uploads/x.txt', 'filename' => 'x.txt', 'mime_type' => 'text/plain', 'filesize' => 1]);
        $user = User::factory()->create(['email' => 'maria@guest.example']);

        $this->asCustomer($user);
        $this->assertFalse($user->can('view', $ticket));
        $this->assertFalse($user->can('update', $ticket));
        $this->assertFalse($user->can('view', $attachment));
        $this->assertContains($this->get(route('tickets.attachments.show', $attachment->uuid))->status(), [403, 404]);

        // In «Login as client» (staff act as the owner): still not a guest's.
        $this->withSession([CurrentClient::SESSION_KEY => $user->client()->id, 'impersonating' => 1]);
        session(['impersonating' => 1]);
        $this->assertFalse($user->can('view', $ticket));

        // Livewire Show with a guessed guest ticket: refused at mount (route policy) and at the actions.
        $this->livewireAs($user)->test(TicketShow::class, ['ticket' => $ticket])->set('message', 'hi')->call('save')->assertStatus(404);
        $this->assertSame(1, $ticket->messages()->count());
    }

    /** HELD: the quick search by reference stays inside the account. */
    public function test_quick_search_by_a_guest_tickets_reference_finds_nothing(): void
    {
        $this->guestTicket(['reference' => '424242']);
        $user = User::factory()->create(['email' => 'maria@guest.example']);
        $this->asCustomer($user);

        $titles = array_column(Livewire::test(QuickSearch::class)->set('q', '#424242')->instance()->results(), 'title');
        $this->assertSame([], array_values(array_filter($titles, fn ($t) => str_contains((string) $t, '424242'))));
    }

    /** HELD: the portal Create takes no account, guest, reference or department it does not offer. */
    public function test_portal_create_cannot_mass_assign_owner_reference_or_hidden_department(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create()->client();
        $hidden = TicketDepartment::query()->create(['name' => 'Abuse', 'hidden' => true]);
        // Not the migration's row: a truncating test of the same process removes it (TicketsFoundationTest::department).
        $support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->asCustomer($user);

        $this->livewireAs($user)->test(TicketCreate::class)
            ->set('department', $hidden->id)->set('subject', 'x')->set('message', 'y')->set('priority', 'low')
            ->call('create')->assertHasErrors('department');
        $this->livewireAs($user)->test(TicketCreate::class)
            ->set('department', $support->id)->set('subject', 'x')->set('message', 'y')->set('priority', 'low')
            ->call('create');
        $ticket = Ticket::query()->sole();
        $this->assertSame($user->client()->id, $ticket->client_id);
        $this->assertNotSame($other->id, $ticket->client_id);
        $this->assertNull($ticket->guest_email);
    }

    /** HELD: «Link to client» is for a guest's ticket only, and only to an account that exists. */
    public function test_link_to_client_refuses_a_linked_ticket_and_a_missing_account(): void
    {
        $this->asStaff();
        $first = User::factory()->create()->client();
        $second = User::factory()->create()->client();
        $ticket = $this->guestTicket();

        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])
            ->callAction('linkToClient', ['client_id' => 999999]);
        $this->assertNull($ticket->fresh()->client_id, 'no account that does not exist');

        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])->callAction('linkToClient', ['client_id' => $first->id]);
        $this->assertSame($first->id, $ticket->fresh()->client_id);

        // A linked ticket no longer offers the action; a forged call does nothing.
        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])->assertActionHidden('linkToClient');
        try {
            Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])->call('mountAction', 'linkToClient')
                ->set('mountedActions.0.data.client_id', $second->id)->call('callMountedAction');
        } catch (\Throwable) {
        }
        $this->assertSame($first->id, $ticket->fresh()->client_id, 'a linked ticket is not re-linked by this action');
    }
}
