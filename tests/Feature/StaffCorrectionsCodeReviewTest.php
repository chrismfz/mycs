<?php

namespace Tests\Feature;

use App\Admin\Resources\AdjustmentNoteResource\Pages\ListAdjustmentNotes;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * Code review of PR #53 (MT-1c): failing tests for what the review found.
 */
class StaffCorrectionsCodeReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_enabled' => true]);
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Cache::flush();
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    private function invoice(float $price = 100, string $status = Invoice::STATUS_UNPAID): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => $status === Invoice::STATUS_PAID ? Invoice::STATUS_UNPAID : $status, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        // A paid fixture (a period paid in the past): stored paid, its lines added while it was open (MT-1c: a paid
        // invoice keeps its lines, and is never created paid).
        if ($status === Invoice::STATUS_PAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        }

        return $invoice->fresh();
    }

    private function staff(array $permissions): User
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return User::factory()->create(['role_id' => Role::create(['name' => 'Staff ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function balance(): string
    {
        return number_format((float) (Credit::query()->where('user_id', $this->user->id)->value('amount') ?? 0), 2, '.', '');
    }

    /**
     * Mark Paid on the edit page leaves the form's status at «pending»: the next Save of the same page writes it back,
     * so the invoice that was just paid is pending again (a stale form undoing the payment's status).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_saving_the_edit_page_after_mark_paid_keeps_the_invoice_paid(): void
    {
        $invoice = $this->invoice();

        Livewire::actingAs($this->staff(['*']))->test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('markPaid', data: ['amount' => '100.00', 'paid_at' => now()->toDateString(), 'gateway_id' => null, 'transaction_id' => 'TRANSFER-9'])
            ->assertHasNoActionErrors()
            ->call('save');

        $this->assertSame(1, InvoiceTransaction::query()->count());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'Saving the edit page after Mark Paid set the paid invoice back to unpaid.');
    }

    /**
     * Mark Paid is gated by invoices.update only, while «Add Payment» needs invoice_transactions.create. A role that may
     * edit invoices but not record payments can now record any amount, and what exceeds the invoice becomes account
     * credit (no reason, no admin.credits.update).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_mark_paid_needs_the_permission_to_record_payments(): void
    {
        $invoice = $this->invoice();
        $editor = $this->staff(['admin.invoices.viewAny', 'admin.invoices.view', 'admin.invoices.update']);

        try {
            Livewire::actingAs($editor)->test(EditInvoice::class, ['record' => $invoice->getKey()])
                ->callAction('markPaid', data: ['amount' => '1000.00', 'paid_at' => now()->toDateString(), 'gateway_id' => null, 'transaction_id' => null]);
        } catch (\Throwable) {
            // refused is fine
        }

        $this->assertSame(0, InvoiceTransaction::query()->count(), 'A role without invoice_transactions.create recorded a payment.');
        $this->assertSame('0.00', $this->balance());
    }

    /**
     * The old Edit page / EditAction were authorized by AdjustmentNotePolicy::update; the new Void action has no
     * authorization: a role that may only list adjustment notes changes invoice totals.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_voiding_an_adjustment_note_needs_the_update_permission(): void
    {
        $invoice = $this->invoice();
        $note = $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -10, 'description' => 'Goodwill']);
        $viewer = $this->staff(['admin.adjustment_notes.viewAny', 'admin.adjustment_notes.view', 'admin.invoices.viewAny', 'admin.invoices.view']);

        try {
            Livewire::actingAs($viewer)->test(ListAdjustmentNotes::class)->callTableAction('void', $note);
        } catch (\Throwable) {
            // refused is fine
        }

        $this->assertSame(AdjustmentNoteStatus::Active, $note->fresh()->status, 'A role without adjustment_notes.update voided a note.');
        $this->assertSame(90.0, $invoice->fresh()->total);
    }

    /**
     * The relation manager hides Void on a finalized invoice (immutable invoices); the Adjustment Notes list does not,
     * so staff change the total of a paid, finalized invoice from there.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_adjustment_notes_list_does_not_void_a_note_of_a_finalized_invoice(): void
    {
        $invoice = $this->invoice();
        $note = $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -10, 'description' => 'Goodwill']);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 90.00, null, 'pi_imm', currency: 'USD');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        // Stored, not only in config: each Livewire request reloads the settings from the database.
        Setting::updateOrCreate(['key' => 'immutable_invoices_enabled'], ['value' => true, 'type' => 'boolean']);
        config(['settings.immutable_invoices_enabled' => true]);

        try {
            Livewire::actingAs($this->staff(['*']))->test(ListAdjustmentNotes::class)->callTableAction('void', $note);
        } catch (\Throwable) {
            // refused is fine
        }

        $this->assertSame(AdjustmentNoteStatus::Active, $note->fresh()->status, 'A note of a finalized (paid) invoice was voided.');
    }
}
