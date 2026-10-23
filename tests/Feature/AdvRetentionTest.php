<?php

namespace Tests\Feature;

use App\Admin\Pages\SystemCleanup;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use App\Models\Invoice;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Models\TicketQuarantine;
use App\Models\VatCheck;
use App\Services\Money\MoneyHistory;
use App\Support\Retention;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of the log retention and System Cleanup: rows the registry treats as logs that other code reads
 * as state or as records to keep, and the attachments action's idea of «last activity».
 */
class AdvRetentionTest extends TestCase
{
    use RefreshDatabase;

    /** ADV-1: a pending registrar row is the guard against a second charge at the registrar (DomainRenewalService::unconfirmed). */
    public function test_a_pending_registrar_charge_is_not_pruned_as_a_log(): void
    {
        $domain = Domain::factory()->create();
        $log = DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_PENDING,
            'request' => ['line' => 1]]);
        DB::table('domain_registrar_logs')->where('id', $log->id)->update(['created_at' => now()->subDays(200)]);

        Retention::pruneAll(); // defaults: 180 days

        $this->assertNotNull(DomainRegistrarLog::query()->find($log->id), 'an unconfirmed charge is state, not a log line');
    }

    /** ADV-2: pending quarantine stays until staff decide (FetchTicketMail prunes only handled rows). */
    public function test_pending_quarantined_mail_is_not_pruned(): void
    {
        Storage::fake(TicketQuarantine::DISK);
        Storage::disk(TicketQuarantine::DISK)->put('quarantine/raw.eml', 'raw');
        $row = TicketQuarantine::query()->create(['reason' => 'spam', 'raw_path' => 'quarantine/raw.eml', 'status' => TicketQuarantine::PENDING]);
        DB::table('ticket_quarantine')->where('id', $row->id)->update(['created_at' => now()->subDays(100)]);

        Retention::pruneAll(); // defaults: 90 days

        $this->assertNotNull(TicketQuarantine::query()->find($row->id), 'a customer mail no one has decided on yet');
        Storage::disk(TicketQuarantine::DISK)->assertExists('quarantine/raw.eml');
    }

    /** ADV-3: docs/money-trail.md §6 keeps the money audit for ever; MoneyHistory reads invoice status changes from it. */
    public function test_an_invoice_status_audit_is_not_pruned(): void
    {
        $invoice = Invoice::factory()->create();
        $id = DB::table('audits')->insertGetId(['event' => 'updated', 'auditable_type' => $invoice->getMorphClass(), 'auditable_id' => $invoice->id,
            'old_values' => json_encode(['status' => 'pending']), 'new_values' => json_encode(['status' => 'paid']),
            'created_at' => now()->subDays(800), 'updated_at' => now()->subDays(800)]);

        Retention::pruneAll(); // defaults: 730 days

        $this->assertNotNull(DB::table('audits')->find($id));
        $this->assertArrayHasKey('a' . $id, (new MoneyHistory)->invoiceMovements($invoice));
    }

    /** ADV-4: a VIES check (consultation number) is the proof a reverse-charged sale's number was valid. */
    public function test_a_vies_proof_is_not_pruned_within_the_tax_record_period(): void
    {
        $contact = Contact::factory()->create();
        $check = VatCheck::query()->create(['contact_id' => $contact->id, 'vat_number' => 'DE123456789', 'result' => VatCheck::RESULT_VALID,
            'request_identifier' => 'WAPIAAAAX', 'checked_at' => now()->subDays(800)]);
        DB::table('vat_checks')->where('id', $check->id)->update(['created_at' => now()->subDays(800)]);

        Retention::pruneAll(); // defaults: 730 days

        $this->assertNotNull(VatCheck::query()->find($check->id), 'the proof of a reverse charge 2+ years ago');
    }

    /** ADV-5: an internal note does not touch the ticket, so the ticket's updated_at is not its last activity. */
    public function test_a_fresh_internal_note_file_on_an_old_closed_ticket_is_kept(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $ticket = Ticket::factory()->create(['status' => Ticket::STATUS_CLOSED]);
        DB::table('tickets')->where('id', $ticket->id)->update(['updated_at' => now()->subDays(400)]);
        $note = TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'note', 'is_internal' => true,
            'staff_id' => Staff::factory()->create()->id]);
        $path = 'tickets/uploads/' . uniqid() . '.txt';
        Storage::disk('local')->put($path, 'file');
        $file = TicketMessageAttachment::query()->create(['ticket_message_id' => $note->id, 'path' => $path, 'filename' => 'f.txt', 'filesize' => 4, 'mime_type' => 'text/plain']);
        $this->assertTrue($ticket->fresh()->updated_at->lt(now()->subDays(365)), 'the note left the ticket as it was');

        Livewire::actingAs(Staff::factory()->withPermissions(['admin.system_cleanup.run'])->create(), 'staff')->test(SystemCleanup::class)
            ->callAction('deleteAttachments', ['before' => now()->subDays(365)->toDateString()]);

        try {
            $this->assertNotNull(TicketMessageAttachment::query()->find($file->id), 'a file attached today is not «no activity since a year»');
            Storage::disk('local')->assertExists($path);
        } finally {
            Storage::disk('local')->delete($path);
        }
    }
}
