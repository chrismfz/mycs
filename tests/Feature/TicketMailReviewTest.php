<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketDepartmentResource\Pages\EditTicketDepartment;
use App\Models\Role;
use App\Models\Staff;
use App\Models\TicketDepartment;
use App\Models\TicketPollRun;
use App\Models\TicketQuarantine;
use App\Services\Ops\Health;
use App\Support\Tickets\InboundOutcome;
use App\Support\Tickets\InboundRouter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\InboundEmail;
use MyCS\TicketMail\Parser;
use MyCS\TicketMail\Tests\Eml;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/** Independent review of PR #244 (A2): failing tests for what the review found. */
class TicketMailReviewTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        $this->support = TicketDepartment::query()->where('name', 'Support')->firstOrFail();
        $this->support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'hunter2-secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
    }

    /**
     * BUG: a text part in a charset mbstring does not know (unknown-8bit, x-user-defined, a typo) with 8-bit bytes comes
     * out of zbateson as invalid UTF-8; Parser::body()'s /u preg_replace then returns null and the whole text becomes ''.
     * The ticket says «(no text)», body_original is '' too, and the mail is marked seen: its words are gone.
     */
    public function test_a_body_in_an_unknown_charset_is_not_lost(): void
    {
        $raw = "From: maria@example.com\r\nTo: support@myip.test\r\nSubject: Help\r\nMessage-ID: <cs1@example.com>\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=unknown-8bit\r\n\r\nMy server is down \xe3\xe5\xe9\xe1, please help\r\n";

        $email = Parser::parse($raw);

        $this->assertStringContainsString('My server is down', $email->body);
    }

    /**
     * BUG (MariaDB; SQLite stores anything): a Date outside TIMESTAMP's 1970–2038 (spam often says 2099, or 1969) makes the
     * quarantine insert throw in strict mode. The mail is never stored, stays unseen, is retried every 5 minutes, and each
     * try leaves its raw copy on disk (written before the insert). 50 of them wedge the mailbox (next test).
     */
    public function test_a_quarantined_mail_with_an_absurd_date_is_kept_once(): void
    {
        Storage::fake(TicketQuarantine::DISK);
        $raw = Eml::make(['From' => 'noreply@spam.example', 'Message-ID' => '<d2099@spam.example>', 'Date' => 'Thu, 01 Jan 2099 10:00:00 +0000']);

        try {
            $outcome = app(InboundRouter::class)->route($this->support, Parser::parse($raw), $raw);
        } catch (\Throwable $e) {
            $this->fail('the insert threw: ' . $e->getMessage() . ' — files left on disk: ' . count(Storage::disk(TicketQuarantine::DISK)->allFiles('tickets/quarantine')));
        }

        $this->assertSame(InboundOutcome::QUARANTINED, $outcome->kind);
    }

    /**
     * RISK: the poll takes the 50 OLDEST unseen mails, and a mail that fails stays unseen with no attempt count. 50 mails
     * that fail every time (an absurd Date on MariaDB, an OOM-sized one, a bug) hide every newer mail for good. Here one
     * failing kind stands for any of them.
     */
    public function test_mails_that_always_fail_do_not_hide_newer_ones_for_ever(): void
    {
        $mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $mailbox);
        $this->app->instance(InboundRouter::class, new class extends InboundRouter
        {
            public function route(TicketDepartment $department, ?InboundEmail $email, string $raw): InboundOutcome
            {
                if (str_contains((string) $email?->subject, 'poison')) {
                    throw new \RuntimeException('always fails');
                }

                return parent::route($department, $email, $raw);
            }
        });
        for ($i = 0; $i < 50; $i++) {
            $mailbox->unseen[] = Eml::make(['From' => "spam{$i}@spam.example", 'Subject' => 'poison', 'Message-ID' => "<p{$i}@spam.example>"]);
        }
        $mailbox->unseen[] = Eml::make(['From' => 'maria@example.com', 'Subject' => 'Real customer', 'Message-ID' => '<real@example.com>']);

        for ($run = 0; $run < 5; $run++) {
            $this->artisan('app:fetch-emails')->assertSuccessful();
        }

        $this->assertDatabaseHas('tickets', ['subject' => 'Real customer']);
    }

    /**
     * RISK: the password is kept when left empty, also when the host (or login) changes. Staff with
     * ticket_departments.update, who may never see it, point the host at their own server and press «Test connection»:
     * the stored password is sent there (LOGIN). A new host or login should require the password again.
     */
    public function test_a_new_imap_host_needs_the_password_again(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]), 'staff');
        $this->app->instance(Mailbox::class, new FakeTicketMailbox);

        Livewire::test(EditTicketDepartment::class, ['record' => $this->support->getRouteKey()])
            ->fillForm(['imap_host' => 'imap.attacker.example', 'imap_password' => ''])->call('save')
            ->assertHasFormErrors(['imap_password']);
    }

    /**
     * RISK: the health check looks only at «connected». A run that logged in but found no folder (a renamed folder), or
     * where every mail failed, is OK although nothing is read.
     */
    public function test_the_health_check_tells_a_mailbox_read_with_errors(): void
    {
        TicketPollRun::query()->create(['department_id' => $this->support->id, 'started_at' => now(), 'finished_at' => now(),
            'connected' => true, 'fetched' => 0, 'errors' => ['No folder «Support».']]);

        $this->assertNotSame(Health::OK, Health::report()['checks']['ticket_mail']['status']);
    }

    /** Fine: the edited old migration runs on a fresh install by table name (no model). */
    public function test_the_old_migration_still_names_its_table(): void
    {
        $this->assertFalse(\Schema::hasTable('ticket_mail_logs'));
        $this->assertSame(0, DB::table('ticket_quarantine')->count());
    }
}
