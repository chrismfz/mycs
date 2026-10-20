<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketDepartmentResource\Pages\EditTicketDepartment;
use App\Admin\Resources\TicketQuarantineResource\Pages\ListTicketQuarantine;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Models\TicketQuarantine;
use App\Models\User;
use App\Services\Ops\Health;
use App\Support\Tickets\InboundRouter;
use App\Support\Tickets\QuarantineActions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Livewire\Livewire;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\Parser;
use MyCS\TicketMail\Tests\Eml;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/** PR A2 for staff: the quarantine's actions, the department's mailbox, files from the portal (docs/tickets.md §3). */
class TicketMailAdminTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
        $this->support = TicketDepartment::query()->where('name', 'Support')->firstOrFail();
        $this->support->update(['email' => 'support@myip.test', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true]);
    }

    private function asStaff(array $permissions = ['*']): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    private function quarantine(array $headers, string $text = 'Words', array $files = []): TicketQuarantine
    {
        $raw = Eml::make($headers + ['Message-ID' => '<q' . uniqid() . '@example.com>'], $text, $files);
        $outcome = app(InboundRouter::class)->route($this->support, Parser::parse($raw), $raw);
        $this->assertNotNull($outcome->quarantine, 'quarantined');

        return $outcome->quarantine;
    }

    private function guestTicket(): Ticket
    {
        return Ticket::query()->create(['subject' => 'Help', 'status' => Ticket::STATUS_ANSWERED, 'priority' => 'medium',
            'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'department_id' => $this->support->id]);
    }

    public function test_staff_attach_a_suggested_mail_once(): void
    {
        $staff = $this->asStaff();
        $ticket = $this->guestTicket();
        $quarantine = $this->quarantine(['From' => 'maria@example.com', 'Subject' => "Re: [Ticket ID: {$ticket->reference}]",
            'Authentication-Results: mymail.myip.test; dmarc=pass header.from=example.com'], 'Old reply', [['name' => 'a.pdf', 'content' => Eml::pdf()]]);
        $this->assertSame($ticket->id, $quarantine->suggested_ticket_id, 'its owner, vouched for');

        Livewire::test(ListTicketQuarantine::class)->assertCanSeeTableRecords([$quarantine])
            ->callTableAction('attach', $quarantine, ['ticket_id' => $ticket->id])->assertHasNoTableActionErrors();

        $message = $ticket->messages()->latest('id')->first();
        $this->assertSame(['Old reply', null, TicketMessage::VIA_EMAIL], [$message->message, $message->user_id, $message->via]);
        $this->assertSame('a.pdf', $message->attachments()->sole()->filename);
        $this->assertSame([TicketQuarantine::ATTACHED, $ticket->id, $staff->id], [$quarantine->fresh()->status, $quarantine->fresh()->ticket_id, $quarantine->fresh()->handled_by]);
        $this->assertContains($ticket->fresh()->status, Ticket::AWAITING_STAFF, 'back with us');

        $this->expectException(InvalidArgumentException::class);
        app(QuarantineActions::class)->attach($quarantine->fresh(), $ticket, $staff);
    }

    public function test_a_strangers_mail_attached_by_staff_says_who_wrote_it(): void
    {
        $staff = $this->asStaff();
        $ticket = $this->guestTicket();
        $quarantine = $this->quarantine(['From' => 'eve@evil.test', 'Subject' => "[#{$ticket->reference}] x"], 'I am Maria');

        app(QuarantineActions::class)->attach($quarantine, $ticket, $staff);

        $this->assertStringStartsWith('(From eve@evil.test)', $ticket->messages()->latest('id')->first()->message);
    }

    public function test_staff_open_a_ticket_from_it_as_a_guests_or_in_an_account(): void
    {
        $staff = $this->asStaff();
        $owner = User::factory()->create(['email' => 'maria@example.com']);
        $first = $this->quarantine(['From' => 'Maria <maria@example.com>', 'Subject' => 'Hello', 'Precedence' => 'bulk']);
        $second = $this->quarantine(['From' => 'Maria <maria@example.com>', 'Subject' => 'Again', 'Precedence' => 'bulk']);

        $guest = app(QuarantineActions::class)->open($first, $staff);
        $this->assertSame([null, 'maria@example.com', 'Hello', $this->support->id], [$guest->client_id, $guest->guest_email, $guest->subject, $guest->department_id]);

        Livewire::test(ListTicketQuarantine::class)->callTableAction('open', $second, ['client_id' => $owner->client()->id])->assertHasNoTableActionErrors();
        $inAccount = Ticket::query()->where('subject', 'Again')->sole();
        $this->assertSame([$owner->client()->id, $owner->id], [$inAccount->client_id, $inAccount->messages()->sole()->user_id]);
    }

    public function test_blocking_drops_the_sender_and_dismisses_its_mail(): void
    {
        $staff = $this->asStaff();
        $one = $this->quarantine(['From' => 'spam@evil.test', 'List-Id' => '<x>']);
        $two = $this->quarantine(['From' => 'spam@evil.test', 'List-Id' => '<x>']);

        app(QuarantineActions::class)->block($one, $staff);

        $this->assertTrue(TicketBlockedSender::blocks('SPAM@evil.test'));
        $this->assertSame([TicketQuarantine::DISMISSED, TicketQuarantine::DISMISSED], [$one->fresh()->status, $two->fresh()->status]);
    }

    public function test_the_quarantine_is_staffs_with_the_permission_only(): void
    {
        $quarantine = $this->quarantine(['From' => 'eve@evil.test', 'List-Id' => '<x>']);
        $this->asStaff(['admin.tickets.viewAny']);

        $this->get(route('filament.admin.resources.ticket-quarantine.index'))->assertForbidden();

        $reader = $this->asStaff(['admin.ticket_quarantine.viewAny']);
        Livewire::test(ListTicketQuarantine::class)->assertCanSeeTableRecords([$quarantine])->assertTableActionHidden('attach', $quarantine);
        $this->assertFalse($reader->can('update', $quarantine));

        $customer = User::factory()->create();
        $this->assertFalse($customer->can('viewAny', TicketQuarantine::class));
    }

    public function test_the_quarantine_shows_the_mail_as_text(): void
    {
        $this->asStaff();
        $quarantine = $this->quarantine(['From' => 'eve@evil.test', 'List-Id' => '<x>', 'Subject' => '<img src=x onerror=alert(1)>'], '<script>alert(2)</script>');

        $html = view('admin.ticket-quarantine.show', ['record' => $quarantine])->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_mailbox_password_is_never_shown_and_kept_when_left_empty(): void
    {
        $this->asStaff();
        $this->app->instance(Mailbox::class, new FakeTicketMailbox);
        $this->support->update(['imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test', 'imap_password' => 'hunter2-secret']);

        Livewire::test(EditTicketDepartment::class, ['record' => $this->support->getRouteKey()])
            ->assertDontSee('hunter2-secret')
            ->assertSchemaStateSet(['imap_password' => null])
            ->fillForm(['imap_password' => '', 'polling' => true])->call('save')->assertHasNoFormErrors()
            ->callAction('testMailbox')->assertNotified('Connected');

        $this->assertSame(['hunter2-secret', true], [$this->support->fresh()->imap_password, $this->support->fresh()->polling]);
    }

    public function test_reading_a_mailbox_needs_its_login(): void
    {
        $this->asStaff();

        Livewire::test(EditTicketDepartment::class, ['record' => $this->support->getRouteKey()])
            ->fillForm(['polling' => true])->call('save')->assertHasFormErrors(['imap_host', 'imap_username', 'imap_password']);
    }

    public function test_the_health_check_tells_a_mailbox_not_read(): void
    {
        $this->support->update(['polling' => true, 'imap_host' => 'h', 'imap_username' => 'u', 'imap_password' => 'p']);

        $this->assertSame(Health::FAIL, Health::report()['checks']['ticket_mail']['status']);

        $this->app->instance(Mailbox::class, new FakeTicketMailbox);
        $this->artisan('app:fetch-emails')->assertSuccessful();
        $this->assertSame(Health::OK, Health::report()['checks']['ticket_mail']['status']);
    }

    public function test_a_customer_uploads_only_allowed_files_judged_by_their_content(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::query()->create(['client_id' => $user->client()->id, 'subject' => 'Mine', 'status' => 'open', 'priority' => 'low']);
        $this->actingAs($user);

        $this->livewireAs($user)->test(TicketShow::class, ['ticket' => $ticket])->set('message', 'x')
            ->set('attachments', [UploadedFile::fake()->createWithContent('page.html', '<script>x</script>')])->call('save')->assertHasErrors('attachments.0');
        $this->livewireAs($user)->test(TicketShow::class, ['ticket' => $ticket])->set('message', 'x')
            ->set('attachments', [UploadedFile::fake()->createWithContent('fake.pdf', '<html><script>x</script></html>')])->call('save')->assertHasErrors('attachments.0');
        $this->livewireAs($user)->test(TicketShow::class, ['ticket' => $ticket])->set('message', 'x')
            ->set('attachments', [UploadedFile::fake()->createWithContent('real.pdf', Eml::pdf())])->call('save')->assertHasNoErrors();

        $file = TicketMessageAttachment::query()->sole();
        $this->assertSame(['real.pdf', 'application/pdf'], [$file->filename, $file->mime_type]);
        $response = $this->withSession($this->loginUser($user))->get(route('tickets.attachments.show', $file->uuid));
        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        @unlink($file->localPath);
    }

    public function test_the_migration_goes_down_and_up(): void
    {
        $migration = require database_path('migrations/2026_12_06_000001_tickets_mailboxes_quarantine.php');
        $migration->down();
        $this->assertTrue(\Schema::hasColumn('ticket_messages', 'ticket_mail_log_id'));
        \DB::table('settings')->insert(['key' => 'ticket_mail_password', 'value' => 'old', 'type' => 'string']);

        $migration->up();

        $this->assertFalse(\Schema::hasTable('ticket_mail_logs'));
        $this->assertFalse(\Schema::hasColumn('ticket_messages', 'ticket_mail_log_id'));
        $this->assertNull(\DB::table('settings')->where('key', 'ticket_mail_password')->value('value'), 'the old password goes');
        $this->assertTrue($this->support->fresh()->require_dmarc, 'DMARC demanded unless staff say otherwise');
        $this->assertFalse($this->support->fresh()->polling, 'nothing is read until staff turn it on');
    }
}
