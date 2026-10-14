<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Enums\AdjustmentNoteType;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Models\Staff;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/** Code review of PR 2b (RecordPaymentService::settle and the paths that call it). */
class InvoiceSettleCodeReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Cache::flush();
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    private function newInvoice(string $status = Invoice::STATUS_UNPAID): Invoice
    {
        return Invoice::factory()->create(['user_id' => $this->user->id, 'status' => $status, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
    }

    private function balance(): string
    {
        return number_format((float) (Credit::query()->where('user_id', $this->user->id)->value('amount') ?? 0), 2, '.', '');
    }

    /**
     * A credit purchase already paid (its 50.00 credit given, applied_at set), reopened and its credit line lowered to
     * 30.00: closing it gives the 20.00 «over» as credit on top of the 50.00 it already gave. 50.00 paid, 70.00 credit.
     */
    public function test_closing_a_credit_purchase_whose_credit_line_was_lowered_does_not_give_the_difference_twice(): void
    {
        $invoice = $this->newInvoice();
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => 50]);
        (new RecordPaymentService)->handle($invoice->fresh(), null, 50.0, transactionId: 'wire-c1');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('50.00', $this->balance(), 'the credit it bought');

        $invoice->fresh()->update(['status' => Invoice::STATUS_UNPAID]); // staff reopen it to fix the line
        $invoice->items()->sole()->update(['price' => 30]);

        (new RecordPaymentService)->settle($invoice->fresh());

        $this->assertSame('50.00', $this->balance(), 'the customer paid 50.00: never more than 50.00 of credit');
    }

    /**
     * A credit purchase not yet paid in full (credit 50.00 + a 30.00 line, 50.00 paid), then a 40.00 discount note:
     * settle() checks «credit to give <= money received» (InvoiceObserver::updating) BEFORE it moves the 10.00 over to
     * credit, so 50.00 of credit is bought with 40.00 that stays on the invoice, plus 10.00 back: 60.00 for 50.00.
     * The same note written before the payment is refused (the payment path credits first, then checks).
     */
    public function test_closing_a_discounted_credit_purchase_gives_credit_only_for_the_money_that_stays_on_it(): void
    {
        $invoice = $this->newInvoice();
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => 50]);
        $invoice->items()->create(['description' => 'Setup', 'quantity' => 1, 'price' => 30]);
        (new RecordPaymentService)->handle($invoice->fresh(), null, 50.0, transactionId: 'wire-c2');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -40, 'description' => 'Discount']);

        try {
            (new RecordPaymentService)->settle($invoice->fresh());
        } catch (\Throwable) {
            // refusing is an acceptable outcome
        }

        $this->assertLessThanOrEqual(5000, RecordPaymentService::toCents($this->balance()), 'credit given for 50.00 paid: ' . $this->balance());
    }

    /**
     * Staff lowered a paid-then-reopened invoice below its payment and have not closed it yet (2b's deliberate waiting
     * state). Any later transaction event on it (a late payment, a processing payment completing, a late fee report)
     * runs InvoiceTransactionCreatedListener, which sets it paid because remaining <= 0, without settle(): the 20.00
     * over is never credited, the invoice is paid over its total, and «Close as paid» is gone (status paid).
     */
    public function test_a_payment_landing_on_an_invoice_paid_over_its_total_credits_the_part_over(): void
    {
        $invoice = $this->newInvoice();
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);
        (new RecordPaymentService)->handle($invoice->fresh(), null, 100.0, transactionId: 'wire-1');
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->sole()->update(['price' => 80]);

        (new RecordPaymentService)->handle($invoice->fresh(), null, 5.0, transactionId: 'wire-late');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('25.00', $this->balance(), 'the late 5.00 and the 20.00 over');
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }

    /**
     * Staff publish a zero draft from the edit form (status draft -> pending, Save): the observer settles it after the
     * commit, but the form still holds «pending». The next Save (any field) writes pending back over paid.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_draft_closed_on_save_is_not_reopened_by_the_next_save_of_the_edit_form(): void
    {
        $draft = $this->newInvoice(Invoice::STATUS_DRAFT);
        $draft->items()->create(['description' => 'Free transfer', 'quantity' => 1, 'price' => 0]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        $page = Livewire::actingAs($staff)->test(EditInvoice::class, ['record' => $draft->getKey()])
            ->fillForm(['status' => Invoice::STATUS_UNPAID])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(Invoice::STATUS_PAID, $draft->fresh()->status, 'closed on its own once finalized');

        $page->fillForm(['due_at' => now()->addWeeks(2)->toDateString()])->call('save');

        $this->assertSame(Invoice::STATUS_PAID, $draft->fresh()->status, 'a later Save must not reopen it');
    }
}
