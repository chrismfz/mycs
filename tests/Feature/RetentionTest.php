<?php

namespace Tests\Feature;

use App\Admin\Pages\SystemCleanup;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Models\TicketQuarantine;
use App\Models\User;
use App\Support\Retention;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * Logs kept for their retention (Settings → Cronjob → Data Retention) and pruned by the daily cron and by hand in System
 * Cleanup, as WHMCS's: logs only, never money, invoices or tickets.
 */
class RetentionTest extends TestCase
{
    use RefreshDatabase;

    private function log(string $table, string $when, array $row = []): int
    {
        return DB::table($table)->insertGetId($row + ['created_at' => $when, 'updated_at' => $when]);
    }

    public function test_each_log_is_pruned_by_its_setting_and_zero_keeps_it(): void
    {
        config(['settings.retention_audits_days' => 10, 'settings.retention_debug_logs_days' => 0]);
        $old = $this->log('audits', now()->subDays(11)->toDateTimeString(), ['event' => 'updated', 'auditable_type' => 'x', 'auditable_id' => 1]);
        $new = $this->log('audits', now()->subDays(9)->toDateTimeString(), ['event' => 'updated', 'auditable_type' => 'x', 'auditable_id' => 1]);
        $debug = $this->log('debug_logs', now()->subYears(3)->toDateTimeString(), ['type' => 'http', 'context' => '{}']);

        $deleted = Retention::pruneAll();

        $this->assertSame(1, $deleted['audits']);
        $this->assertNull(DB::table('audits')->find($old));
        $this->assertNotNull(DB::table('audits')->find($new));
        $this->assertNotNull(DB::table('debug_logs')->find($debug), '0 keeps it for ever');
        $this->assertArrayNotHasKey('debug_logs', $deleted);
    }

    public function test_no_money_invoice_or_ticket_table_is_ever_a_log(): void
    {
        $tables = array_column(Retention::logs(), 'table');

        foreach (['invoices', 'invoice_items', 'invoice_transactions', 'money_events', 'payment_gateway_events', 'charge_attempts', 'credits', 'tickets', 'ticket_messages', 'orders', 'services', 'domains'] as $kept) {
            $this->assertNotContains($kept, $tables);
        }
    }

    public function test_the_cron_prunes_email_logs_and_the_other_logs(): void
    {
        config(['settings.cronjob_delete_email_logs' => 30, 'settings.retention_failed_jobs_days' => 7]);
        $mail = $this->log('email_logs', now()->subDays(31)->toDateTimeString(), ['subject' => 's', 'to' => 'a@example.test', 'body' => 'b', 'status' => 'sent', 'trigger' => 'auto']);
        $failed = DB::table('failed_jobs')->insertGetId(['uuid' => 'u1', 'connection' => 'sync', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(8)]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertNull(DB::table('email_logs')->find($mail));
        $this->assertNull(DB::table('failed_jobs')->find($failed));
        $this->assertDatabaseHas('cron_stats', ['key' => 'logs_pruned']);
    }

    public function test_a_quarantined_mail_takes_its_raw_file_with_it(): void
    {
        Storage::fake(TicketQuarantine::DISK);
        Storage::disk(TicketQuarantine::DISK)->put('quarantine/raw.eml', 'raw');
        $row = TicketQuarantine::query()->create(['reason' => 'spam', 'raw_path' => 'quarantine/raw.eml', 'status' => 'pending']);
        DB::table('ticket_quarantine')->where('id', $row->id)->update(['created_at' => now()->subDays(100)]);

        Retention::pruneBefore('ticket_quarantine', now()->subDays(90));

        $this->assertNull(TicketQuarantine::query()->find($row->id));
        Storage::disk(TicketQuarantine::DISK)->assertMissing('quarantine/raw.eml');
    }

    public function test_a_ticket_message_body_is_not_copied_into_the_audit_log(): void
    {
        config(['audit.console' => true]);
        $ticket = Ticket::factory()->create();

        $message = TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'A long and private body', 'is_internal' => false]);

        $audit = Audit::query()->where('auditable_type', TicketMessage::class)->where('auditable_id', $message->id)->first();
        $this->assertStringNotContainsString('private body', (string) json_encode($audit?->new_values));
    }

    public function test_system_cleanup_needs_its_permission_and_deletes_before_a_date(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $old = $this->log('notifications', now()->subDays(40)->toDateTimeString(), ['user_id' => User::factory()->create()->id, 'title' => 't', 'body' => 'b', 'show_in_app' => 1, 'show_as_push' => 0]);
        $new = $this->log('notifications', now()->subDays(5)->toDateTimeString(), ['user_id' => User::factory()->create()->id, 'title' => 't', 'body' => 'b', 'show_in_app' => 1, 'show_as_push' => 0]);

        $this->actingAs(Staff::factory()->withPermissions(['admin.settings.update'])->create(), 'staff')
            ->get(SystemCleanup::getUrl())->assertForbidden();

        $admin = Staff::factory()->withPermissions(['admin.system_cleanup.run'])->create();
        $this->actingAs($admin, 'staff')->get(SystemCleanup::getUrl())->assertOk()->assertSee(__('Audit log'));
        Livewire::actingAs($admin, 'staff')->test(SystemCleanup::class)
            ->callAction('deleteBefore', ['before' => now()->subDays(30)->toDateString()], ['key' => 'notifications']);

        $this->assertNull(DB::table('notifications')->find($old));
        $this->assertNotNull(DB::table('notifications')->find($new));
    }

    public function test_old_closed_tickets_lose_their_files_not_their_messages(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $make = function (string $status, int $daysAgo) {
            $ticket = Ticket::factory()->create(['status' => $status]);
            $message = TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'm', 'is_internal' => false]);
            $path = 'tickets/uploads/' . uniqid() . '.txt';
            Storage::disk('local')->put($path, 'file');
            $attachment = TicketMessageAttachment::query()->create(['ticket_message_id' => $message->id, 'path' => $path, 'filename' => 'f.txt', 'filesize' => 4, 'mime_type' => 'text/plain']);
            // Its last activity, after the message touched it.
            DB::table('tickets')->where('id', $ticket->id)->update(['status' => $status, 'updated_at' => now()->subDays($daysAgo)]);

            return [$message, $attachment];
        };
        [$oldMessage, $oldFile] = $make(Ticket::STATUS_CLOSED, 400);
        [, $openFile] = $make(Ticket::STATUS_OPEN, 400);
        [, $recentFile] = $make(Ticket::STATUS_CLOSED, 10);

        Livewire::actingAs(Staff::factory()->withPermissions(['admin.system_cleanup.run'])->create(), 'staff')->test(SystemCleanup::class)
            ->callAction('deleteAttachments', ['before' => now()->subDays(365)->toDateString()])->assertHasNoFormErrors()->assertNotified();

        $this->assertNull(TicketMessageAttachment::query()->find($oldFile->id));
        Storage::disk('local')->assertMissing($oldFile->path);
        $this->assertNotNull(TicketMessage::query()->find($oldMessage->id), 'the message stays');
        $this->assertNotNull(TicketMessageAttachment::query()->find($openFile->id));
        $this->assertNotNull(TicketMessageAttachment::query()->find($recentFile->id));
        Storage::disk('local')->delete([$openFile->path, $recentFile->path]);
    }
}
