<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketQuarantineResource;
use App\Admin\Resources\TicketQuarantineResource\Pages\ListTicketQuarantine;
use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Livewire\Tickets\Create as TicketCreate;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Tickets\TicketFiles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/** What the browser test of 2026-10-20 found (docs/tickets.md §6η). */
class TicketsPolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
    }

    private function asStaff(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
    }

    /** A refused file says why: the customer saw nothing and no ticket. */
    public function test_a_refused_file_is_told_on_the_new_ticket_and_the_reply(): void
    {
        $user = User::factory()->create();
        $support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $fake = UploadedFile::fake()->createWithContent('invoice.pdf', '<html><script>x</script></html>');

        $this->livewireAs($user)->test(TicketCreate::class)->set('department', $support->id)->set('subject', 'x')->set('message', 'y')
            ->set('priority', 'low')->set('attachments', [$fake])->call('create')
            ->assertHasErrors('attachments.0')->assertSee('invoice.pdf')->assertSee(__('ticket.files_max', ['count' => 5, 'size' => TicketFiles::maxUploadMegabytes(), 'types' => 'pdf']), false);

        $ticket = Ticket::query()->create(['client_id' => $user->client()->id, 'subject' => 'Mine', 'status' => 'open', 'priority' => 'low']);
        $this->livewireAs($user)->test(TicketShow::class, ['ticket' => $ticket])->set('message', 'x')->set('attachments', [$fake])->call('save')
            ->assertHasErrors('attachments.0')->assertSee('invoice.pdf');
    }

    /** The limit shown is the smallest of ours, Livewire's and PHP's (this server takes 2 MB: code review of #260). */
    public function test_the_upload_limit_shown_is_the_one_taken(): void
    {
        $php = fn (string $setting) => (int) ini_get($setting); // «2M» → 2, «8M» → 8 (M on the servers we run)
        $expected = fn (int $livewire) => min(20, $livewire, $php('upload_max_filesize') ?: PHP_INT_MAX, $php('post_max_size') ?: PHP_INT_MAX);

        $this->assertSame($expected(12), TicketFiles::maxUploadMegabytes());
        config(['livewire.temporary_file_upload.rules' => 'required|file|max:5120']);
        $this->assertSame($expected(5), TicketFiles::maxUploadMegabytes(), 'rules as a pipe string too');
    }

    /** Staff who see the quarantine but not tickets still find it in the menu. */
    public function test_the_quarantine_stays_in_the_menu_without_the_tickets_permission(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'Q', 'permissions' => ['admin.ticket_quarantine.viewAny']])->id]), 'staff');

        $labels = collect(Filament::getNavigation())->flatMap(fn ($group) => collect($group->getItems())->map(fn ($item) => $item->getLabel()))->all();

        $this->assertContains('Mail Quarantine', $labels);
    }

    public function test_a_files_error_names_it_in_greek(): void
    {
        app()->setLocale('el');

        $this->assertSame('συνημμένο', trans('validation.attributes')['attachments.*']);
    }

    /** A guest's messages name the guest; a note and where a message came from are told. */
    public function test_staff_see_who_wrote_and_how(): void
    {
        $this->asStaff();
        $ticket = Ticket::query()->create(['subject' => 'Help', 'status' => 'open', 'priority' => 'low', 'guest_name' => 'Maria Guest', 'guest_email' => 'maria@example.com',
            'opened_via' => 'form', 'sender_vouched' => false]);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'From the form', 'via' => 'form']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'Note', 'is_internal' => true]);

        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertSee('Maria Guest')->assertSee(__('Guest'))->assertSee(__('Contact form'))->assertSee(__('Internal note'));
    }

    public function test_the_quarantine_is_named_as_staff_call_it(): void
    {
        $this->asStaff();

        Livewire::test(ListTicketQuarantine::class)->assertSee('No quarantined mail')->assertDontSee('No ticket quarantines');
        $this->assertSame('Mail Quarantine', TicketQuarantineResource::getPluralModelLabel());
    }

    public function test_the_login_and_the_new_ticket_speak_greek(): void
    {
        app()->setLocale('el');

        $this->assertSame('Να με θυμάσαι', __('auth.remember_me'));
        $this->assertSame('Το μήνυμά σας', __('ticket.initial_message'));
    }
}
