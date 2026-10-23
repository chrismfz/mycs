<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketMailImportResource;
use App\Admin\Resources\TicketMailImportResource\Pages\ListTicketMailImports;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMailId;
use App\Models\TicketMailImport;
use App\Support\Tickets\InboundOutcome;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\Tests\Eml;
use RuntimeException;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/** Independent review of #406 (Ticket Mail Import Log). */
class TicketMailImportLogReviewTest extends TestCase
{
    use RefreshDatabase;

    private FakeTicketMailbox $mailbox;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $this->mailbox);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
    }

    /** @param  array<int|string, string>  $headers */
    private function receive(array $headers, string $text = 'Hello there'): void
    {
        $this->mailbox->unseen[] = Eml::make($headers, $text);
        $this->artisan('app:fetch-emails')->assertSuccessful();
    }

    /**
     * The quarantine has its own permission («it holds mail of anyone»). Staff who may see tickets but not the quarantine
     * must not read the quarantined mail's sender, subject and reason through the log.
     */
    public function test_staff_without_the_quarantine_permission_do_not_see_quarantined_mail_in_the_log(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->receive(['Message-ID' => '<q1@example.com>', 'From' => 'MAILER-DAEMON@mail.example.com',
            'Subject' => 'Secret quarantined subject', 'Auto-Submitted' => 'auto-replied']);
        $quarantined = TicketMailImport::query()->sole();
        $this->assertSame(InboundOutcome::QUARANTINED, $quarantined->outcome);

        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'Tickets', 'permissions' => ['admin.tickets.viewAny']])->id]), 'staff');

        $this->get(TicketMailImportResource::getUrl('index'))->assertOk()->assertDontSee('Secret quarantined subject');
        Livewire::test(ListTicketMailImports::class)->assertCanNotSeeTableRecords([$quarantined]);
    }

    /**
     * A ticket opened, then the «we received it» mail throws: the poller counts a failure and leaves the mail unread; next
     * run it is a duplicate (its Message-ID is in the ticket), so the log never shows the ticket that was opened.
     */
    public function test_a_ticket_opened_before_a_later_error_is_still_in_the_log(): void
    {
        // Any error after open() committed (here the «we received it» mail's thread id cannot be written).
        TicketMailId::creating(function () {
            throw new RuntimeException('DB hiccup');
        });
        $pass = 'Authentication-Results: mymail.myip.test; dkim=pass header.d=example.com; dmarc=pass header.from=example.com';
        $mail = Eml::make(['Message-ID' => '<o1@example.com>', 'From' => 'Maria <maria@example.com>', 'Subject' => 'Site down', $pass], 'Hi');

        $this->mailbox->unseen[] = $mail;
        $this->artisan('app:fetch-emails')->assertSuccessful();
        $this->mailbox->unseen[] = $mail;
        $this->artisan('app:fetch-emails')->assertSuccessful();

        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame([InboundOutcome::OPENED], TicketMailImport::query()->pluck('outcome')->all());
    }
}
