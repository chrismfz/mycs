<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketDepartmentResource\Pages\ListTicketDepartments;
use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Admin\Resources\TicketResource\Pages\ListTickets;
use App\Livewire\Tickets\Create as TicketCreate;
use App\Models\ApiKey;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Rules\TicketReferenceMask;
use App\Support\Tickets\TicketReference;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tickets, PR A1 (docs/tickets.md §5): departments as a table, five statuses, guest tickets (no account, never shown to
 * an account by its email), the number customers see.
 */
class TicketsFoundationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A department the migration made from the old setting (Support, Sales): made here when it is not there. A test of
     * the same process that truncates the tables (DatabaseTruncation: PaymentMutationAtomicityTest,
     * CreditPurchaseMutationReviewTest) leaves only what the seeder puts back, and a migration's rows are not that.
     */
    private function department(string $name): TicketDepartment
    {
        return TicketDepartment::query()->firstOrCreate(['name' => $name]);
    }

    private function guestTicket(array $attributes = []): Ticket
    {
        return Ticket::query()->create(array_merge(['subject' => 'Hello', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium',
            'guest_name' => 'Maria Guest', 'guest_email' => 'maria@guest.example', 'opened_via' => 'form'], $attributes));
    }

    private function staff(array $permissions = ['*']): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function asStaff(array $permissions = ['*']): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = $this->staff($permissions);
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    public function test_who_writes_moves_the_ticket(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $user->client()->id, 'status' => Ticket::STATUS_OPEN]);
        $staff = Staff::factory()->admin()->create();

        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'message' => 'More']);
        $this->assertSame(Ticket::STATUS_OPEN, $ticket->fresh()->status, 'still waiting on our first reply');

        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => $staff->id, 'message' => 'A note', 'is_internal' => true]);
        $this->assertSame(Ticket::STATUS_OPEN, $ticket->fresh()->status, 'an internal note moves nothing');

        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => $staff->id, 'message' => 'Done']);
        $this->assertSame(Ticket::STATUS_ANSWERED, $ticket->fresh()->status);

        $ticket->update(['status' => Ticket::STATUS_CLOSED]);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'message' => 'Again']);
        $this->assertSame(Ticket::STATUS_CUSTOMER_REPLY, $ticket->fresh()->status, 'an answer reopens it, with us');
    }

    public function test_a_staff_reply_on_a_guest_ticket_tells_no_account(): void
    {
        Mail::fake();
        $ticket = $this->guestTicket();

        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'Hi']);

        $this->assertSame(Ticket::STATUS_ANSWERED, $ticket->fresh()->status);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_the_number_follows_the_companys_mask(): void
    {
        config(['settings.ticket_reference_mask' => '%n%n%n%n%n%n']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->guestTicket()->reference);

        config(['settings.ticket_reference_mask' => '%y-%m-%d-%n%n%n%n%n%n']);
        $this->assertMatchesRegularExpression('/^' . now()->format('Y-m-d') . '-\d{6}$/', $this->guestTicket()->reference);

        // A mask that cannot number tickets falls back to the default, never a broken number.
        config(['settings.ticket_reference_mask' => '%n']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->guestTicket()->reference);

        $this->assertFalse(TicketReference::validMask(''), 'empty: the setting is required, the rule skips empty values');
        foreach (['%n%n%n%n%n%n' => true, 'T-%A%A%n%n%n' => true, '%n%n%n%n' => false, '<script>%n%n%n%n' => false] as $mask => $ok) {
            $this->assertSame($ok, Validator::make(['m' => $mask], ['m' => [new TicketReferenceMask]])->passes(), $mask);
        }
        $this->assertNotSame(TicketReference::generate('%n%n%n%n%n%n%n%n'), TicketReference::generate('%n%n%n%n%n%n%n%n'));
    }

    /** §3 Δ: a guest's ticket reaches no account, also one whose login has the guest's email. */
    public function test_no_customer_sees_a_guest_ticket_not_even_with_the_same_email(): void
    {
        config(['settings.default_currency' => 'USD']);
        $ticket = $this->guestTicket();
        $sameEmail = User::factory()->create(['email' => 'maria@guest.example']);

        $this->actingAs($sameEmail)->withSession($this->loginUser($sameEmail))->get(route('tickets.show', $ticket))->assertNotFound();
        $this->get(route('tickets'))->assertOk()->assertDontSee('Hello');
        $this->assertFalse($sameEmail->can('view', $ticket));
    }

    public function test_staff_see_a_guest_ticket_and_link_it_to_an_account(): void
    {
        $this->asStaff();
        $ticket = $this->guestTicket();
        $client = User::factory()->create()->client();

        Livewire::test(ListTickets::class)->assertCanSeeTableRecords([$ticket])->assertSee('Maria Guest');
        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertSee('maria@guest.example')
            ->callAction('linkToClient', ['client_id' => $client->id])
            ->assertHasNoActionErrors();

        $this->assertSame($client->id, $ticket->fresh()->client_id);
        $this->assertSame('maria@guest.example', $ticket->fresh()->guest_email, 'kept as history');
    }

    public function test_a_customer_is_offered_only_departments_not_hidden(): void
    {
        $user = User::factory()->create();
        $hidden = TicketDepartment::query()->create(['name' => 'Abuse', 'hidden' => true]);
        $support = $this->department('Support');
        $this->actingAs($user);

        $this->livewireAs($user)->test(TicketCreate::class)
            ->set('department', $hidden->id)->set('subject', 'x')->set('message', 'y')->set('priority', 'low')
            ->call('create')->assertHasErrors('department');
        $this->livewireAs($user)->test(TicketCreate::class)
            ->set('department', $support->id)->set('subject', 'x')->set('message', 'y')->set('priority', 'low')
            ->call('create')->assertHasNoErrors();

        $ticket = Ticket::query()->sole();
        $this->assertSame([$support->id, 'portal', $user->client()->id], [$ticket->department_id, $ticket->opened_via, $ticket->client_id]);
    }

    public function test_the_api_takes_a_department_by_id_or_by_its_old_name(): void
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 't', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $token), 'permissions' => ['admin.tickets.create']]);
        $client = User::factory()->create()->client();
        $sales = $this->department('Sales');

        $this->withToken($token)->postJson(route('api.v1.admin.tickets.store'), ['subject' => 'A', 'client_id' => $client->id, 'department' => 'Sales'])
            ->assertSuccessful()->assertJsonPath('data.attributes.department_id', $sales->id);
        $this->withToken($token)->postJson(route('api.v1.admin.tickets.store'), ['subject' => 'B', 'client_id' => $client->id, 'department' => 'Nope'])
            ->assertUnprocessable();
        $this->assertSame(['api'], Ticket::query()->pluck('opened_via')->unique()->values()->all());
    }

    public function test_a_department_with_tickets_is_hidden_not_deleted(): void
    {
        $staff = $this->asStaff();
        $support = $this->department('Support');
        $empty = TicketDepartment::query()->create(['name' => 'Spare']);
        $this->guestTicket(['department_id' => $support->id]);

        $this->assertFalse($staff->can('delete', $support));
        $this->assertTrue($staff->can('delete', $empty));
        Livewire::test(ListTicketDepartments::class)->assertCanSeeTableRecords([$support, $empty]);
    }

    public function test_the_client_area_shows_the_number_not_the_id(): void
    {
        config(['settings.default_currency' => 'USD', 'settings.ticket_reference_mask' => 'T-%n%n%n%n%n']);
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $user->client()->id, 'status' => Ticket::STATUS_ANSWERED, 'subject' => 'Numbered']);

        $this->actingAs($user)->withSession($this->loginUser($user))->get(route('tickets'))->assertOk()
            ->assertSee('#' . $ticket->reference)->assertSee(__('ticket.statuses.answered'));
    }
}
