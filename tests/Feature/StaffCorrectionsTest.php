<?php

namespace Tests\Feature;

use App\Admin\Actions\VoidAdjustmentNote;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\AdjustmentNote;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\Role;
use App\Models\User;
use App\Models\Staff;
use App\Services\Invoice\RecordPaymentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * MT-1c (docs/money-trail.md): staff corrections as in WHMCS. An invoice is paid only by recording its payment (Mark
 * Paid), never by its status alone (audit B1); a payment whose credit was spent can still be deleted, leaving the
 * credit negative once staff confirm it; adjustment notes are append-only (audit INV-9).
 */
class StaffCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_enabled' => true]);
        // The admin form validates the currency against Currency, whose queries are cached (CacheableBuilder).
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Cache::flush();
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    private function invoice(float $price = 100, string $status = Invoice::STATUS_UNPAID, ?string $reference = null): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => $status === Invoice::STATUS_PAID ? Invoice::STATUS_UNPAID : $status, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(array_filter(['description' => 'Hosting', 'quantity' => 1, 'price' => $price, 'reference_type' => $reference], fn ($value) => $value !== null));

        // A paid fixture (a period paid in the past): stored paid, its lines added while it was open (MT-1c: a paid
        // invoice keeps its lines, and is never created paid).
        if ($status === Invoice::STATUS_PAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        }

        return $invoice->fresh();
    }

    private function balance(): string
    {
        return number_format((float) (Credit::query()->where('user_id', $this->user->id)->value('amount') ?? 0), 2, '.', '');
    }

    private function owner(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
    }

    private function apiToken(array $permissions): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['name' => 'test', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token), 'permissions' => $permissions]);

        return $token;
    }

    // ------------------------------------------------------------------ no paid without payment (B1)

    public function test_an_invoice_with_a_balance_is_never_marked_paid_by_its_status(): void
    {
        $invoice = $this->invoice();

        try {
            $invoice->update(['status' => Invoice::STATUS_PAID]);
            $this->fail('An invoice with 100.00 to pay was marked paid without a payment.');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('Mark Paid', $e->getMessage());
        }

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    public function test_an_invoice_with_nothing_to_pay_is_still_marked_paid_by_its_status(): void
    {
        $zero = $this->invoice(0);
        $zero->update(['status' => Invoice::STATUS_PAID]);
        $this->assertSame(Invoice::STATUS_PAID, $zero->fresh()->status);

        // Paid, then set back to unpaid by staff (Mark Unpaid): its payment covers it, so it can be paid again.
        $paid = $this->invoice();
        ExtensionHelper::addPayment($paid->id, 'Stripe', 100.00, null, 'pi_paid', currency: 'USD');
        $paid->fresh()->update(['status' => Invoice::STATUS_UNPAID]);
        $paid->fresh()->update(['status' => Invoice::STATUS_PAID]);
        $this->assertSame(Invoice::STATUS_PAID, $paid->fresh()->status);
    }

    /** Audit B1: an API key with invoices.update «buys» credit for any customer by marking a credit invoice paid. */
    public function test_the_api_cannot_mark_a_credit_purchase_paid_without_a_payment(): void
    {
        $invoice = $this->invoice(500, reference: Credit::class);
        $token = $this->apiToken(['admin.invoices.update']);

        $this->withToken($token)->putJson(route('api.v1.admin.invoices.update', $invoice), ['status' => 'paid'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'An invoice becomes paid when its payment is recorded: use Mark Paid instead.');

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame('0.00', $this->balance());
        $this->assertSame(0, MoneyEvent::query()->count());
    }

    public function test_the_api_creates_invoices_unpaid(): void
    {
        $token = $this->apiToken(['admin.invoices.create']);

        $this->withToken($token)->postJson(route('api.v1.admin.invoices.store'), ['user_id' => $this->user->id, 'currency_code' => 'USD', 'status' => 'paid'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
        $this->assertSame(0, Invoice::query()->count());
    }

    /**
     * The admin form: «Paid» is no option while something is left to pay (EditInvoice::beforeSave and the model refuse
     * it too). In its own process: after some earlier test in the suite, Livewire's update route is gone.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_admin_form_does_not_mark_an_invoice_paid_without_a_payment(): void
    {
        $invoice = $this->invoice();

        $this->livewireAs($this->owner())->test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->set('data.status', Invoice::STATUS_PAID)
            ->call('save')
            ->assertHasFormErrors(['status']); // a disabled option

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(0, InvoiceTransaction::query()->count());
    }

    // ------------------------------------------------------------------ Mark Paid

    public function test_mark_paid_records_the_payment_with_its_date_and_the_invoice_follows(): void
    {
        $invoice = $this->invoice();
        $paidAt = Carbon::parse('2026-10-01 12:00:00');

        $transaction = (new RecordPaymentService)->recordByStaff($invoice, null, '100.00', 'TRANSFER-1', paidAt: $paidAt);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('2026-10-01', $transaction->fresh()->created_at->toDateString());
        $event = MoneyEvent::query()->sole();
        $this->assertSame([MoneyEventKind::Payment, 'manual', null], [$event->kind, $event->source, $event->external_reference]);
    }

    public function test_mark_paid_with_part_of_the_balance_leaves_the_invoice_pending(): void
    {
        $invoice = $this->invoice();

        (new RecordPaymentService)->recordByStaff($invoice, null, '40.00');

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(60.0, $invoice->fresh()->remaining);
    }

    public function test_mark_paid_on_a_cancelled_invoice_reopens_it_only_when_staff_confirm(): void
    {
        $invoice = $this->invoice();
        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Overdue']);

        try {
            (new RecordPaymentService)->recordByStaff($invoice, null, '100.00');
            $this->fail('A cancelled invoice was paid without confirming its reopening.');
        } catch (DisplayException) {
        }
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame(0, InvoiceTransaction::query()->count());

        $transaction = (new RecordPaymentService)->recordByStaff($invoice, null, '100.00', reopen: true);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('0.00', $transaction->fresh()->credited_amount); // on the invoice, not credit
        $this->assertSame(AdjustmentNoteStatus::Voided, $invoice->adjustmentNotes()->where('is_cancellation', true)->sole()->status);
        $this->assertSame('0.00', $this->balance());
    }

    public function test_mark_paid_with_a_recorded_transaction_id_changes_nothing_not_even_a_reopening(): void
    {
        $first = $this->invoice();
        ExtensionHelper::addPayment($first->id, 'Stripe', 100.00, null, 'pi_same', currency: 'USD');
        $second = $this->invoice();
        $second->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Overdue']);

        try {
            (new RecordPaymentService)->recordByStaff($second, null, '100.00', 'pi_same', reopen: true);
            $this->fail('A replayed transaction id was recorded again.');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('already recorded', $e->getMessage());
        }

        $this->assertSame(Invoice::STATUS_CANCELLED, $second->fresh()->status);
        $this->assertSame(1, InvoiceTransaction::query()->count());
    }

    /** Code review: Mark Paid is «what is left»; more (a double submit, two staff at once) is refused, read locked. */
    public function test_mark_paid_refuses_more_than_what_is_left(): void
    {
        $invoice = $this->invoice();
        (new RecordPaymentService)->recordByStaff($invoice, null, '60.00', upToRemaining: true);

        try {
            (new RecordPaymentService)->recordByStaff($invoice, null, '60.00', upToRemaining: true);
            $this->fail('Mark Paid recorded more than what was left.');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('Add Payment', $e->getMessage());
        }

        $this->assertSame(1, InvoiceTransaction::query()->count());
        $this->assertSame('0.00', $this->balance());
    }

    /** Code review: a typed id matching another invoice's processing payment would land there; nothing is recorded. */
    public function test_mark_paid_with_another_invoices_transaction_id_records_nothing(): void
    {
        $other = $this->invoice();
        $gateway = Gateway::query()->where('extension', 'Stripe')->sole();
        (new RecordPaymentService)->handle($other, $gateway, '100.00', transactionId: 'pi_processing', status: InvoiceTransactionStatus::Processing);
        $invoice = $this->invoice();
        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Overdue']);

        try {
            (new RecordPaymentService)->recordByStaff($invoice, null, '100.00', 'pi_processing', reopen: true);
            $this->fail('A payment was recorded on another invoice.');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('#' . $other->id, $e->getMessage());
        }

        $this->assertSame(InvoiceTransactionStatus::Processing, $other->transactions()->sole()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $other->fresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_admin_mark_paid_action_records_the_payment(): void
    {
        $invoice = $this->invoice();

        // On the edit page: the view page opens it while invoices are not immutable.
        $this->livewireAs($this->owner())->test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('markPaid', data: ['amount' => '100.00', 'paid_at' => '2026-10-02', 'gateway_id' => null, 'transaction_id' => 'TRANSFER-2'])
            ->assertHasNoActionErrors();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(['TRANSFER-2', '2026-10-02'], [InvoiceTransaction::query()->sole()->transaction_id, InvoiceTransaction::query()->sole()->created_at->toDateString()]);
    }

    // ------------------------------------------------------------------ deleting a payment whose credit was spent

    public function test_a_payment_whose_credit_was_spent_is_deleted_only_when_staff_confirm_and_the_credit_goes_negative(): void
    {
        $overpaid = $this->invoice();
        ExtensionHelper::addPayment($overpaid->id, 'Stripe', 150.00, null, 'pi_over', currency: 'USD'); // 50.00 credit
        $other = $this->invoice(50);
        (new RecordPaymentService)->payWithCredit($other);
        $this->assertSame('0.00', $this->balance());
        $transaction = $overpaid->transactions()->sole();

        try {
            (new RecordPaymentService)->deleteTransaction($transaction);
            $this->fail('A payment whose credit was spent was deleted without confirmation.');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('negative', $e->getMessage());
        }
        $this->assertNotNull($transaction->fresh());

        (new RecordPaymentService)->deleteTransaction($transaction, allowNegativeCredit: true);

        $this->assertNull($transaction->fresh());
        $this->assertSame('-50.00', $this->balance());
        $this->assertSame(0, Artisan::call('mycs:credits-check'), 'The journal does not match the negative balance.');
    }

    // ------------------------------------------------------------------ adjustment notes: append-only

    public function test_an_adjustment_note_is_never_edited_or_deleted_only_voided(): void
    {
        $invoice = $this->invoice();
        $note = $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -10, 'description' => 'Goodwill']);

        foreach ([fn () => $note->update(['amount' => -20]), fn () => $note->update(['description' => 'Other']), fn () => $note->delete()] as $change) {
            try {
                $change();
                $this->fail('An adjustment note was changed.');
            } catch (DisplayException) {
            }
            $note->refresh();
        }
        $this->assertSame(['-10.00', 'Goodwill'], [$note->amount, $note->description]);

        $note->update(['status' => AdjustmentNoteStatus::Voided]);
        $this->assertSame(AdjustmentNoteStatus::Voided, $note->fresh()->status);
        $this->assertSame(100.0, $invoice->fresh()->total);
    }

    public function test_a_refund_note_follows_its_refund_and_is_never_voided(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_refund', currency: 'USD');
        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 30.00);
        $note = AdjustmentNote::query()->sole();

        $this->assertTrue($note->is_refund);
        $this->assertFalse($note->isVoidableByStaff());
        $this->expectException(DisplayException::class);
        $note->update(['status' => AdjustmentNoteStatus::Voided]);
    }

    /** Code review: a refund note voided before MT-1c (by mistake) can be set back to active, never voided again. */
    public function test_a_refund_note_voided_before_can_only_be_unvoided(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_old_void', currency: 'USD');
        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 30.00);
        $note = AdjustmentNote::query()->sole();
        DB::table('adjustment_notes')->where('id', $note->id)->update(['status' => AdjustmentNoteStatus::Voided->value]);
        $this->actingAs($this->owner());

        $this->assertTrue(VoidAdjustmentNote::allowed($note->fresh()));
        $note->fresh()->update(['status' => AdjustmentNoteStatus::Active]);
        $this->assertFalse(VoidAdjustmentNote::allowed($note->fresh()));
    }

    public function test_the_migration_marks_the_refund_notes_written_before_it(): void
    {
        $invoice = $this->invoice();
        DB::table('adjustment_notes')->insert([
            ['invoice_id' => $invoice->id, 'type' => 'credit', 'amount' => -5, 'description' => 'Refund for transaction #7 (pi_old)', 'status' => 'active', 'is_cancellation' => false, 'is_refund' => false],
            ['invoice_id' => $invoice->id, 'type' => 'credit', 'amount' => -5, 'description' => 'Goodwill', 'status' => 'active', 'is_cancellation' => false, 'is_refund' => false],
        ]);
        $migration = require database_path('migrations/2026_10_17_000000_add_refund_marker_to_adjustment_notes.php');

        $migration->down();
        $migration->up();

        $this->assertSame(['Refund for transaction #7 (pi_old)'], AdjustmentNote::query()->where('is_refund', true)->pluck('description')->all());
    }
}
