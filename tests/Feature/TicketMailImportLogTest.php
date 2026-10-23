<?php

namespace Tests\Feature;

use App\Admin\Logs;
use App\Admin\Resources\TicketMailImportResource;
use App\Admin\Resources\TicketMailImportResource\Pages\ListTicketMailImports;
use App\Admin\Resources\TicketResource;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketDepartment;
use App\Models\TicketMailImport;
use App\Models\TicketQuarantine;
use App\Support\Tickets\InboundOutcome;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\Tests\Eml;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/**
 * The Ticket Mail Import Log (owner 2026-10-23, WHMCS's): every mail the poller read and what came of it, the ticket or
 * the quarantine it went to; a mail read again is not there twice.
 */
class TicketMailImportLogTest extends TestCase
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

    public function test_every_mail_read_is_logged_with_what_came_of_it(): void
    {
        TicketBlockedSender::query()->create(['value' => 'spam@example.com']);
        $this->receive(['Message-ID' => '<a1@example.com>', 'From' => 'Maria <maria@example.com>', 'Subject' => 'My site is down']);
        $this->receive(['Message-ID' => '<a2@example.com>', 'From' => 'MAILER-DAEMON@mail.example.com', 'Subject' => 'Undelivered Mail Returned to Sender',
            'Auto-Submitted' => 'auto-replied']);
        $this->receive(['Message-ID' => '<a3@example.com>', 'From' => 'spam@example.com', 'Subject' => 'Buy now']);
        // The first mail again (a mailbox that kept it unseen): not logged twice.
        $this->receive(['Message-ID' => '<a1@example.com>', 'From' => 'Maria <maria@example.com>', 'Subject' => 'My site is down']);

        $logs = TicketMailImport::query()->orderBy('id')->get();
        $this->assertSame([InboundOutcome::OPENED, InboundOutcome::QUARANTINED, InboundOutcome::BLOCKED], $logs->pluck('outcome')->all());
        $this->assertSame(['maria@example.com', 'Maria', 'My site is down', $this->support->id, Ticket::query()->sole()->id],
            [$logs[0]->from_email, $logs[0]->from_name, $logs[0]->subject, $logs[0]->department_id, $logs[0]->ticket_id]);
        $this->assertSame(['automated', TicketQuarantine::query()->sole()->id], [$logs[1]->reason, $logs[1]->quarantine_id]);
        $this->assertNull($logs[2]->ticket_id);
    }

    public function test_the_log_page_says_it_as_whmcs_and_links_the_ticket(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->receive(['Message-ID' => '<b1@example.com>', 'From' => 'Maria <maria@example.com>', 'Subject' => 'My site is down']);
        $this->receive(['Message-ID' => '<b2@example.com>', 'From' => 'MAILER-DAEMON@mail.example.com', 'Subject' => 'Undelivered',
            'Auto-Submitted' => 'auto-replied']);
        [$opened, $quarantined] = TicketMailImport::query()->orderBy('id')->get()->all();
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');

        $this->get(TicketMailImportResource::getUrl('index'))->assertOk()
            ->assertSee(TicketResource::getUrl('edit', ['record' => $opened->ticket_id]), false);
        Livewire::test(ListTicketMailImports::class)
            ->assertTableColumnStateSet('outcome', 'Imported as a new ticket', $opened)
            ->assertTableColumnStateSet('outcome', 'Quarantined: Automated mail (auto-reply, bounce, list)', $quarantined)
            ->filterTable('outcome', InboundOutcome::QUARANTINED)
            ->assertCanSeeTableRecords([$quarantined])->assertCanNotSeeTableRecords([$opened]);
        $this->assertContains(__('Ticket Mail Import Log'), array_map(fn ($item) => $item->getLabel(), Logs::navigation()));
    }

    public function test_only_staff_who_see_tickets_see_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'Audit', 'permissions' => ['admin.audit.viewAny']])->id]), 'staff');

        $this->get(TicketMailImportResource::getUrl('index'))->assertForbidden();
    }
}
