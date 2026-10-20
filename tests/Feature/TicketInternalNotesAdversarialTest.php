<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Livewire\Tickets\Index as TicketIndex;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Models\User;
use App\Support\Tickets\GuestLinks;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/** The adversarial review of #263 (no context from the author): nothing of a note reaches a customer or a guest. */
class TicketInternalNotesAdversarialTest extends TestCase
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

    private function noteWithFile(Ticket $ticket): TicketMessageAttachment
    {
        $path = 'tickets/uploads/probe-' . uniqid() . '.txt';
        @mkdir(storage_path('app/tickets/uploads'), 0777, true);
        file_put_contents(storage_path('app/' . $path), 'secret');
        $note = TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => $this->staff->id, 'message' => 'n', 'is_internal' => true]);

        return $note->attachments()->create(['path' => $path, 'filename' => 's.txt', 'mime_type' => 'text/plain', 'filesize' => 6]);
    }

    public function test_the_customer_gets_a_public_file_but_not_a_notes(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::query()->create(['client_id' => $user->client()->id, 'subject' => 'S', 'status' => Ticket::STATUS_OPEN, 'priority' => 'low']);
        $file = $this->noteWithFile($ticket);
        $pub = TicketMessage::query()->create(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'message' => 'p']);
        $pf = $pub->attachments()->create(['path' => $file->path, 'filename' => 'p.txt', 'mime_type' => 'text/plain', 'filesize' => 6]);
        $this->withSession($this->loginUser($user))->get(route('tickets.attachments.show', $pf->uuid))->assertOk();
        $this->withSession($this->loginUser($user))->get(route('tickets.attachments.show', $file->uuid))->assertNotFound();
        @unlink($file->localPath);
    }

    public function test_a_guest_link_does_not_open_a_notes_file(): void
    {
        $guest = Ticket::query()->create(['subject' => 'S', 'status' => Ticket::STATUS_OPEN, 'priority' => 'low', 'guest_name' => 'M',
            'guest_email' => 'm@example.com', 'opened_via' => 'form', 'sender_vouched' => true]);
        $file = $this->noteWithFile($guest);
        $token = basename((string) parse_url(GuestLinks::issue($guest), PHP_URL_PATH));
        $this->get(route('tickets.guest.attachment', ['token' => $token, 'attachment' => $file->uuid]))->assertNotFound();
        @unlink($file->localPath);
    }

    public function test_a_note_moves_no_status_and_is_not_our_answer(): void
    {
        $user = User::factory()->create();
        foreach ([Ticket::STATUS_ANSWERED, 'closed', Ticket::STATUS_CUSTOMER_REPLY] as $st) {
            $ticket = Ticket::query()->create(['client_id' => $user->client()->id, 'subject' => 'S', 'status' => $st, 'priority' => 'low']);
            Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])->fillForm(['message' => 'x'])->call('note')->assertHasNoErrors();
            $this->assertSame($st, $ticket->fresh()->status);
        }
        // Only a note from staff, then the customer writes again: still waiting on our answer.
        $ticket = Ticket::query()->create(['client_id' => $user->client()->id, 'subject' => 'S', 'status' => Ticket::STATUS_OPEN, 'priority' => 'low']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'message' => 'Help']);
        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])->fillForm(['message' => 'x'])->call('note');
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'message' => 'Again']);
        $this->assertSame(Ticket::STATUS_OPEN, $ticket->fresh()->status);
    }

    public function test_a_notes_file_from_the_form_is_kept_for_staff(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::query()->create(['client_id' => $user->client()->id, 'subject' => 'S', 'status' => Ticket::STATUS_OPEN, 'priority' => 'low']);
        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])
            ->fillForm(['message' => 'with file', 'attachments' => [UploadedFile::fake()->createWithContent('a.txt', 'hello')]])
            ->call('note')->assertHasNoErrors();
        $note = TicketMessage::query()->latest('id')->first();
        $this->assertTrue($note->is_internal);
        $this->assertSame(1, $note->attachments()->count());
        $file = $note->attachments()->first();
        $this->assertFileExists($file->localPath);
        $this->withSession($this->loginUser($user))->get(route('tickets.attachments.show', $file->uuid))->assertNotFound();
        $this->livewireAs($user)->test(TicketIndex::class)->assertDontSee('with file');
        @unlink($file->localPath);
    }

    public function test_staff_who_may_not_update_the_ticket_add_no_note(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::query()->create(['client_id' => $user->client()->id, 'subject' => 'S', 'status' => Ticket::STATUS_OPEN, 'priority' => 'low']);
        $ro = Staff::factory()->create(['role_id' => Role::create(['name' => 'RO', 'permissions' => ['admin.tickets.viewAny']])->id]);
        $this->actingAs($ro, 'staff');
        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])->assertForbidden();
    }
}
