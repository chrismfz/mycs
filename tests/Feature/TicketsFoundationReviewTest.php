<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\ApiKey;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Tickets\TicketReference;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Code review of PR A1 (#239): what the reviewers found, kept as tests.
 */
class TicketsFoundationReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_keeps_a_department_written_with_spaces_or_another_case(): void
    {
        $migration = require database_path('migrations/2026_12_05_000001_tickets_departments_guests_statuses.php');
        // A2's and A3's tables point at the departments and tickets: down first, up again after (as a rollback would).
        $mailboxes = require database_path('migrations/2026_12_06_000001_tickets_mailboxes_quarantine.php');
        $outbound = require database_path('migrations/2026_12_07_000001_tickets_outbound_mail.php');
        $imports = (require database_path('migrations/2027_01_01_000000_create_ticket_mail_imports.php')); // it points at the quarantine
        $imports->down();
        $outbound->down();
        $mailboxes->down();
        $migration->down();
        $client = User::factory()->create()->client();
        $ids = [];
        foreach ([' Support ', 'support', 'Support'] as $department) {
            $ids[] = DB::table('tickets')->insertGetId(['client_id' => $client->id, 'subject' => 'Old', 'status' => 'open', 'priority' => 'medium',
                'department' => $department, 'created_at' => now(), 'updated_at' => now()]);
        }

        $migration->up();
        $mailboxes->up();
        $outbound->up();
        $imports->up();

        $support = TicketDepartment::query()->whereRaw('LOWER(name) = ?', ['support'])->sole()->id; // one, not one per spelling
        $this->assertSame([$support, $support, $support], array_map(fn (int $id) => Ticket::query()->find($id)->department_id, $ids));

        // The client's tickets still go with it (the column made nullable keeps its cascade).
        DB::table('clients')->where('id', $client->id)->delete();
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_two_tickets_drawing_the_same_number_at_once_both_open(): void
    {
        $client = User::factory()->create()->client();
        $raced = false;
        new Ticket; // booted: its own hook draws the number first
        // Another process takes the number between the check and the insert, once.
        Ticket::creating(function (Ticket $ticket) use ($client, &$raced) {
            if (!$raced) {
                $raced = true;
                DB::table('tickets')->insert(['client_id' => $client->id, 'subject' => 'Other', 'status' => 'open', 'priority' => 'medium',
                    'reference' => $ticket->reference, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $ticket = Ticket::query()->create(['client_id' => $client->id, 'subject' => 'Mine', 'status' => 'open', 'priority' => 'medium']);

        $this->assertSame(2, Ticket::query()->distinct()->count('reference'));
        $this->assertSame('Mine', Ticket::query()->where('reference', $ticket->reference)->value('subject'));
    }

    public function test_a_given_number_that_is_taken_is_an_error_not_another_number(): void
    {
        $client = User::factory()->create()->client();
        Ticket::query()->create(['client_id' => $client->id, 'subject' => 'A', 'status' => 'open', 'priority' => 'medium', 'reference' => '123456']);

        $this->expectException(UniqueConstraintViolationException::class);
        Ticket::query()->create(['client_id' => $client->id, 'subject' => 'B', 'status' => 'open', 'priority' => 'medium', 'reference' => '123456']);
    }

    public function test_a_mask_must_give_enough_numbers(): void
    {
        $this->assertFalse(TicketReference::validMask('%n%n%n%n'), '10.000 numbers run out');
        $this->assertTrue(TicketReference::validMask('%n%n%n%n%n'));
        $this->assertTrue(TicketReference::validMask('%A%a%A%a'));
        $this->assertFalse(TicketReference::validMask('%A%A%A'));
    }

    public function test_an_internal_note_does_not_keep_a_ticket_from_closing(): void
    {
        config(['settings.cronjob_close_ticket' => 7]);
        $user = User::factory()->create();
        $staff = Staff::factory()->admin()->create();
        $ticket = Ticket::factory()->create(['client_id' => $user->client()->id, 'status' => Ticket::STATUS_OPEN]);
        $this->travel(-30)->days();
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => $staff->id, 'message' => 'Answer']);
        $this->travelBack();
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => $staff->id, 'message' => 'note', 'is_internal' => true]);
        $this->assertSame(Ticket::STATUS_ANSWERED, $ticket->fresh()->status);

        Artisan::call('app:cron-job');

        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->fresh()->status, 'silent to the customer for 30 days');
    }

    public function test_the_api_still_takes_and_gives_what_it_did(): void
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 't', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $token), 'permissions' => ['admin.tickets.create']]);
        $client = User::factory()->create()->client();
        // Not the migration's row: a truncating test of the same process removes it (TicketsFoundationTest::department).
        TicketDepartment::query()->firstOrCreate(['name' => 'Sales']);

        $this->withToken($token)->postJson(route('api.v1.admin.tickets.store'), ['subject' => 'A', 'client_id' => $client->id,
            'department' => 'Sales', 'status' => 'replied', 'priority' => 'normal'])
            ->assertSuccessful()
            ->assertJsonPath('data.attributes.department', 'Sales')
            ->assertJsonPath('data.attributes.status', Ticket::STATUS_ANSWERED)
            ->assertJsonPath('data.attributes.priority', 'medium');
        $this->withToken($token)->postJson(route('api.v1.admin.tickets.store'), ['subject' => 'B', 'client_id' => $client->id, 'department' => 'Nope'])
            ->assertUnprocessable()->assertJsonValidationErrors('department');
    }

    /** §3 Δ: a WHMCS ticket with no account comes over as a guest's, never as the account of the same email. */
    public function test_the_importer_keeps_a_whmcs_guest_ticket_a_guests(): void
    {
        $customer = User::factory()->create(['email' => 'same@guest.example']);
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, tid TEXT, did INT, userid INT, name TEXT, email TEXT, title TEXT, status TEXT,
            urgency TEXT, date TEXT, lastreply TEXT)');
        $whmcs->exec('CREATE TABLE tblticketreplies (id INTEGER PRIMARY KEY, tid INT, userid INT, name TEXT, email TEXT, admin TEXT, message TEXT, date TEXT)');
        $whmcs->exec('CREATE TABLE tblticketdepartments (id INTEGER PRIMARY KEY, name TEXT, "order" INT, hidden INT, clientsonly INT, email TEXT)');
        $whmcs->exec("INSERT INTO tblticketdepartments VALUES (1, 'Support', 1, 0, 0, '')");
        $whmcs->exec("INSERT INTO tbltickets VALUES (7, '481516', 1, 0, 'Guest', 'same@guest.example', 'Hi', 'Open', 'Medium', '2026-09-01 10:00:00', '2026-09-01 10:00:00')");
        $whmcs->exec("INSERT INTO tbltickets VALUES (8, '234200', 1, {$customer->client()->id}, '', '', 'Mine', 'Answered', 'Low', '2026-09-01 10:00:00', '2026-09-01 10:00:00')");
        $whmcs->exec("INSERT INTO tblticketreplies VALUES (1, 7, 0, 'Guest', 'same@guest.example', '', 'more', '2026-09-01 11:00:00')");
        $users = User::query()->count();

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'importTickets'))->invoke($command);

        $guest = Ticket::query()->findOrFail(7);
        $this->assertSame([null, 'Guest', 'same@guest.example', '481516'], [$guest->client_id, $guest->guest_name, $guest->guest_email, $guest->reference]);
        // Its opening and its reply (the old importer left the opening out): neither is a login's.
        $this->assertSame([null, null], $guest->messages()->orderBy('id')->pluck('user_id')->all(), 'the guest\'s messages are no login\'s');
        $this->assertFalse($customer->can('view', $guest));
        $this->assertSame($users, User::query()->count(), 'no login made for a guest');
        $this->assertSame($customer->client()->id, Ticket::query()->findOrFail(8)->client_id);
    }
}
