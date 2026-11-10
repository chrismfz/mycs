<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Helpers\ExtensionHelper;
use App\Models\AdjustmentNote;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\StaffLineEdit;
use Dedoc\Scramble\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * The Admin API does for an invoice's adjustment notes, its discount lines and its status what staff do in the panel,
 * no more (owner 2026-11-10, sweep 7 TIGHT-INVOICES-PAYMENTS-32, docs/staff-freedom.md). On invoices the WHMCS importer
 * brought with its own steps: #480 paid there (frozen), #502 unpaid, 10.00 for service 31.
 */
class StaffFreedomApiNotesLinesTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    private const NOTES = ['admin.adjustment_notes.view', 'admin.adjustment_notes.create', 'admin.adjustment_notes.update', 'admin.adjustment_notes.delete'];

    private const LINES = ['admin.invoice_items.create', 'admin.invoice_items.update', 'admin.invoice_items.delete'];

    private Invoice $invoice;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-10-05 10:00:00');
        config(['settings.credits_enabled' => true]);
        $client = User::factory()->create()->client();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($client->id, $product->product->id);
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsInvoice(502, 'Unpaid', [31 => 10.0], due: '2026-10-10');
        $this->importWhmcs();
        $this->invoice = $this->imported(502);
        $this->staff = Staff::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  list<string>  $permissions */
    private function token(array $permissions): string
    {
        $token = 'NOTE' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => $this->staff->id, 'name' => 'notes', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token), 'permissions' => $permissions]);

        return $token;
    }

    /** @return array{0: int, 1: int} the invoice's total and what is left to pay, in cents */
    private function money(int $invoice = 502): array
    {
        $fresh = Invoice::query()->findOrFail($invoice);

        return [RecordPaymentService::toCents($fresh->total), RecordPaymentService::toCents($fresh->remaining)];
    }

    private function note(float $amount = -3.0, int $invoice = 502): AdjustmentNote
    {
        return Invoice::query()->findOrFail($invoice)->adjustmentNotes()->create([
            'type' => $amount < 0 ? AdjustmentNoteType::Credit->value : AdjustmentNoteType::Debit->value, 'amount' => $amount, 'description' => 'Goodwill',
        ]);
    }

    // ---------------------------------------------------------------- adjustment notes

    public function test_a_note_is_written_edited_voided_and_deleted_as_in_the_panels_table(): void
    {
        $token = $this->token(self::NOTES);

        $created = $this->withToken($token)->postJson(route('api.v1.admin.invoices.adjustment-notes.store', 502), ['amount' => '-3.00', 'description' => 'Goodwill'])
            ->assertCreated()->assertJsonPath('data.attributes.type', 'credit')->assertJsonPath('data.attributes.amount', '-3.00')
            ->assertJsonPath('data.attributes.status', 'active')->assertJsonMissingPath('warning');
        $note = AdjustmentNote::query()->where('invoice_id', 502)->sole();
        $this->assertSame((string) $note->id, $created->json('data.id'));
        $this->assertNotNull($note->number, 'numbered as any note');
        $this->assertSame([700, 700], $this->money(), 'as the panel\'s note of 3.00 leaves it');

        // Its description and amount; the type follows the sign.
        $this->withToken($token)->putJson(route('api.v1.admin.invoices.adjustment-notes.update', [502, $note]), ['amount' => '2.00', 'description' => 'Late fee'])
            ->assertOk()->assertJsonPath('data.attributes.type', 'debit');
        $this->assertSame([1200, 1200, 'Late fee', $note->number], [...$this->money(), $note->fresh()->description, $note->fresh()->number]);
        $this->withToken($token)->putJson(route('api.v1.admin.invoices.adjustment-notes.update', [502, $note]), ['description' => 'Late fee, agreed'])->assertOk();
        $this->assertSame(['2.00', 'Late fee, agreed'], [(string) $note->fresh()->amount, $note->fresh()->description]);

        // Void, again (it stays voided), and back.
        $this->withToken($token)->postJson(route('api.v1.admin.invoices.adjustment-notes.void', [502, $note]))->assertOk()->assertJsonPath('data.attributes.status', 'voided');
        $this->assertSame([1000, 1000], $this->money());
        $this->withToken($token)->postJson(route('api.v1.admin.invoices.adjustment-notes.void', [502, $note]))->assertOk()->assertJsonPath('data.attributes.status', 'voided');
        $this->withToken($token)->postJson(route('api.v1.admin.invoices.adjustment-notes.unvoid', [502, $note]))->assertOk()->assertJsonPath('data.attributes.status', 'active');
        $this->assertSame([1200, 1200], $this->money());

        $this->withToken($token)->getJson(route('api.v1.admin.invoices.adjustment-notes.index', 502))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attributes.description', 'Late fee, agreed');

        $this->withToken($token)->deleteJson(route('api.v1.admin.invoices.adjustment-notes.destroy', [502, $note]))->assertNoContent();
        $this->assertNull($note->fresh());
        $this->assertSame([1000, 1000], $this->money());
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString(), 'a note renews nothing');
    }

    public function test_a_note_that_makes_a_paid_invoice_owe_needs_the_permission_to_record_payments_and_says_so(): void
    {
        (new RecordPaymentService)->recordByStaff($this->invoice, null, '10.00', 'tr_502');
        $this->assertSame(Invoice::STATUS_PAID, $this->invoice->fresh()->status);
        $url = route('api.v1.admin.invoices.adjustment-notes.store', 502);

        $this->withToken($this->token(self::NOTES))->postJson($url, ['amount' => '2.00', 'description' => 'Late fee'])->assertStatus(422)
            ->assertJsonPath('message', __('This invoice is paid: a change that makes it owe is «paid by hand» for that part, and needs the permission to record payments.'));
        $this->assertSame(0, AdjustmentNote::query()->where('invoice_id', 502)->count());

        $response = $this->withToken($this->token([...self::NOTES, 'admin.invoice_transactions.create']))->postJson($url, ['amount' => '2.00', 'description' => 'Late fee'])->assertCreated();

        $fresh = $this->invoice->fresh();
        $this->assertSame([Invoice::STATUS_PAID, 200], [$fresh->status, RecordPaymentService::toCents($fresh->remaining)], 'it stays paid and owes the note');
        $this->assertNotNull($fresh->paid_by_staff_at, 'paid by staff\'s word for that part');
        $this->assertSame(StaffLineEdit::balanceNotice(502), $response->json('warning'), 'what the panel tells staff after it');
        $this->assertNotNull($response->json('warning'));
    }

    public function test_kept_the_notes_refusals_are_the_panels(): void
    {
        $token = $this->token([...self::NOTES, 'admin.invoice_transactions.create']);
        $write = fn (int $invoice, array $body = ['amount' => '-1.00', 'description' => 'x']) => $this->withToken($token)
            ->postJson(route('api.v1.admin.invoices.adjustment-notes.store', $invoice), $body)->assertStatus(422);

        // Paid in WHMCS: frozen.
        $write(480)->assertJsonPath('message', EkdosiDocument::cutOverRefusal());
        // A fiscal document from Ekdosi: its total stays as issued.
        $note = $this->note();
        $document = EkdosiDocument::query()->create(['invoice_id' => 502, 'mark' => '400001234560502', 'code' => 'ΤΙΜ502']);
        $write(502)->assertJsonPath('message', __('This invoice has a fiscal document from Ekdosi: its total stays as issued. Cancel the document there first.'));
        $stays = __('This note follows a refund or a cancellation, or its invoice is refunded or has a fiscal document from Ekdosi: it stays as it is.');
        $this->withToken($token)->putJson(route('api.v1.admin.invoices.adjustment-notes.update', [502, $note]), ['amount' => '-9.00'])->assertStatus(422)->assertJsonPath('message', $stays);
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoices.adjustment-notes.destroy', [502, $note]))->assertStatus(422)->assertJsonPath('message', $stays);
        $this->withToken($token)->postJson(route('api.v1.admin.invoices.adjustment-notes.void', [502, $note]))->assertStatus(422);
        $document->delete();
        $this->assertSame(['-3.00', AdjustmentNoteStatus::Active], [(string) $note->fresh()->amount, $note->fresh()->status]);

        // A credit purchase takes no discount.
        DB::table('invoice_items')->insert(['invoice_id' => 502, 'description' => 'Credit', 'quantity' => 1, 'price' => 0, 'reference_type' => Credit::class, 'reference_id' => 1]);
        $write(502)->assertJsonPath('message', __('An invoice that buys account credit takes no adjustment note or discount: change the amount of its line instead (or cancel it and make a new one).'));
        DB::table('invoice_items')->where('invoice_id', 502)->where('reference_type', Credit::class)->delete();

        // A refund's own note follows its refund; a refunded invoice keeps its total.
        $note->delete();
        $payment = (new RecordPaymentService)->recordByStaff($this->invoice, null, '10.00', 'tr_502');
        ExtensionHelper::refundLocally($payment, 10.00, notify: false);
        $refundNote = AdjustmentNote::query()->where('invoice_id', 502)->where('is_refund', true)->sole();
        $this->assertSame(Invoice::STATUS_REFUNDED, $this->invoice->fresh()->status);
        $write(502)->assertJsonPath('message', __('A refunded invoice keeps its total: its adjustment notes stay as they are.'));
        $this->withToken($token)->putJson(route('api.v1.admin.invoices.adjustment-notes.update', [502, $refundNote]), ['amount' => '-1.00'])->assertStatus(422);
        $this->withToken($token)->postJson(route('api.v1.admin.invoices.adjustment-notes.void', [502, $refundNote]))->assertStatus(422);
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoices.adjustment-notes.destroy', [502, $refundNote]))->assertStatus(422);
        $this->assertSame(['-10.00', AdjustmentNoteStatus::Active], [(string) $refundNote->fresh()->amount, $refundNote->fresh()->status]);
    }

    public function test_a_notes_amount_is_a_plain_decimal_string_and_only_its_two_fields_are_read(): void
    {
        $token = $this->token(self::NOTES);
        $url = route('api.v1.admin.invoices.adjustment-notes.store', 502);
        foreach ([-3, 3.5, '1e2', '-3.123', '0x1A', '--3', '3,00', '', null, '99999999999.00'] as $amount) {
            $this->withToken($token)->postJson($url, ['amount' => $amount, 'description' => 'x'])->assertStatus(422)->assertJsonValidationErrors('amount');
        }
        $this->withToken($token)->postJson($url, ['amount' => '-3.00'])->assertStatus(422)->assertJsonValidationErrors('description');
        $this->assertSame(0, AdjustmentNote::query()->count());

        $this->withToken($token)->postJson($url, ['amount' => '-3.00', 'description' => 'x', 'invoice_id' => 480, 'number' => 'INJECTED-9', 'type' => 'debit',
            'status' => 'voided', 'is_refund' => true, 'is_cancellation' => true])->assertCreated();
        $note = AdjustmentNote::query()->sole();
        $this->assertSame([502, 'credit', AdjustmentNoteStatus::Active, false, false], [$note->invoice_id, (string) $note->type, $note->status, (bool) $note->is_refund, (bool) $note->is_cancellation]);
        $this->assertNotSame('INJECTED-9', $note->number);

        $this->withToken($token)->putJson(route('api.v1.admin.invoices.adjustment-notes.update', [502, $note]), ['invoice_id' => 480, 'number' => 'INJECTED-9', 'status' => 'voided', 'is_refund' => true])->assertOk();
        $same = $note->fresh();
        $this->assertSame([502, $note->number, AdjustmentNoteStatus::Active, false], [$same->invoice_id, $same->number, $same->status, (bool) $same->is_refund]);
    }

    // ---------------------------------------------------------------- the API's documentation

    public function test_the_generated_api_documentation_lists_the_new_endpoints(): void
    {
        $paths = array_keys(app(Generator::class)()['paths']);

        foreach (['/v1/admin/invoices/{invoice}/payments', '/v1/admin/invoices/{invoice}/payments/{transaction}', '/v1/admin/invoices/{invoice}/payments/{transaction}/refunds',
            '/v1/admin/invoices/{invoice}/payments/{transaction}/refunds/{refund}', '/v1/admin/invoices/{invoice}/adjustment-notes',
            '/v1/admin/invoices/{invoice}/adjustment-notes/{adjustmentNote}', '/v1/admin/invoices/{invoice}/adjustment-notes/{adjustmentNote}/void',
            '/v1/admin/invoices/{invoice}/adjustment-notes/{adjustmentNote}/unvoid'] as $path) {
            $this->assertContains($path, $paths);
        }
    }
}
