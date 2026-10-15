<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\ApiKey;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Adversarial review of PR B (sweep Π2.11, StaffLineEdit): staff line edits under the payment locks; an open invoice
 * with payments (succeeded or processing) keeps its amounts, descriptions still change, without payments all changes.
 * Invariants: an unpaid invoice never ends with payments above what it bills because of a staff line edit; the rule
 * cannot be bypassed; description-only edits are not refused; the advice in the refusal can be followed.
 * A1–A3 were FAILING (confirmed defects) and are fixed in the PR; the rest stay as regression coverage.
 */
class StaffLineEditAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Cache::flush();
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    // ---------------------------------------------------------------- helpers

    /** An invoice of two untaxed lines (Hosting 60 + Domain 40), $paid of it paid by transfer with status $status. */
    private function invoice(float $paid = 0, InvoiceTransactionStatus $status = InvoiceTransactionStatus::Succeeded, bool $credit = false): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 60, 'taxed' => false]);
        $invoice->items()->create(['description' => 'Domain', 'quantity' => 1, 'price' => 40, 'taxed' => false]);
        if ($paid > 0) {
            (new RecordPaymentService)->handle($invoice->fresh(), null, $paid, transactionId: 'wire-' . $invoice->id, status: $status, isCreditTransaction: $credit);
        }

        return $invoice->fresh();
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function token(): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 'adv', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $token), 'permissions' => ['admin.invoice_items.update', 'admin.invoice_items.delete', 'admin.invoice_items.create']]);

        return $token;
    }

    private function line(Invoice $invoice, string $description): InvoiceItem
    {
        return $invoice->items()->where('description', $description)->sole();
    }

    private function form(Invoice $invoice): mixed
    {
        return $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
    }

    /** What the invoice bills, in cents, from its stored line totals. */
    private function billedCents(Invoice $invoice): int
    {
        return $invoice->items()->get()->sum(fn (InvoiceItem $line) => $line->totalCents());
    }

    /** Net of refunds and credit, what its succeeded payments hold, in cents. */
    private function heldCents(Invoice $invoice): int
    {
        return $invoice->transactions()->where('status', InvoiceTransactionStatus::Succeeded->value)->get()
            ->sum(fn ($t) => RecordPaymentService::toCents($t->amount) - RecordPaymentService::toCents($t->refunded_amount) - RecordPaymentService::toCents($t->credited_amount));
    }

    private function assertNotOverpaid(Invoice $invoice): void
    {
        $invoice = $invoice->fresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertGreaterThanOrEqual($this->heldCents($invoice), $this->billedCents($invoice),
            'An unpaid invoice holds more than it bills after a staff line edit.');
    }

    // ---------------------------------------------------------------- confirmed defects

    /**
     * A1 (high). The guard normalises the form's quantity to 4 decimals ("0.99996" → "1", the same as stored), so it sees
     * no change; the form validates quantity as numeric only, and LineTax::apply truncates it with (int) to 0: the line
     * now bills 0.00. An invoice with a 90 payment then bills 60, unpaid, holding 30 more than it bills.
     * Was FAILING (confirmed defect); fixed in PR B.
     */
    public function test_a_fractional_quantity_just_under_one_does_not_zero_a_line_of_an_invoice_with_a_payment(): void
    {
        $invoice = $this->invoice(paid: 90);
        $key = 'record-' . $this->line($invoice, 'Domain')->id;

        $this->form($invoice)->set("data.items.{$key}.quantity", '0.99996')->call('save');

        $this->assertSame('40.00', $this->line($invoice, 'Domain')->fresh()->line_total);
        $this->assertNotOverpaid($invoice);
    }

    /**
     * A2 (medium). The refusal tells staff to «refund the payment first (back, or to account credit)». A refunded payment
     * stays a succeeded transaction (refunded_amount), and hasPayments() counts it: after a full refund the invoice
     * holds nothing, yet its lines stay locked, and the transaction cannot be deleted either (it has refunds). The
     * advice cannot be followed; the invoice's amounts are locked for good. Was FAILING (confirmed defect); fixed in PR B.
     */
    public function test_after_a_full_refund_the_form_changes_the_amounts_as_the_refusal_says(): void
    {
        $invoice = $this->invoice(paid: 50);
        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 50.0, notify: false);
        $this->assertSame(0, $this->heldCents($invoice->fresh()));
        $key = 'record-' . $this->line($invoice, 'Domain')->id;

        $this->form($invoice)->set("data.items.{$key}.price", '10.00')->call('save')->assertNotNotified(__('Invoice not saved'));

        $this->assertSame('10.00', $this->line($invoice, 'Domain')->fresh()->price);
    }

    /**
     * A3 (medium). Same as A2 on the API, after a full refund to account credit: the line still cannot be lowered or
     * taken off. Was FAILING (confirmed defect); fixed in PR B.
     */
    public function test_after_a_full_refund_to_credit_the_api_changes_the_amounts(): void
    {
        config(['settings.credits_enabled' => true]);
        $invoice = $this->invoice(paid: 50);
        ExtensionHelper::refundToCredit($invoice->transactions()->sole(), 50.0, notify: false);
        $this->assertSame(0, $this->heldCents($invoice->fresh()));
        $line = $this->line($invoice, 'Domain');

        $this->withToken($this->token())->putJson(route('api.v1.admin.invoice-items.update', $line), ['price' => 10])->assertSuccessful();
        $this->assertSame('10.00', $line->fresh()->price);
    }

    // ---------------------------------------------------------------- regression coverage

    /** A processing (SEPA) payment locks the amounts too. Regression coverage. */
    public function test_a_processing_payment_keeps_the_amounts(): void
    {
        $invoice = $this->invoice(paid: 50, status: InvoiceTransactionStatus::Processing);
        $key = 'record-' . $this->line($invoice, 'Domain')->id;

        $this->form($invoice)->set("data.items.{$key}.price", '0')->call('save')->assertNotified(__('Invoice not saved'));
        $this->assertSame('40.00', $this->line($invoice, 'Domain')->fresh()->price);

        $line = $this->line($invoice, 'Domain');
        $token = $this->token();
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoice-items.destroy', $line))->assertStatus(422);
        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['quantity' => 2])->assertStatus(422);
        $this->assertSame(2, $invoice->items()->count());
    }

    /** A payment made with account credit locks the amounts too. Regression coverage. */
    public function test_a_credit_payment_keeps_the_amounts(): void
    {
        $invoice = $this->invoice(paid: 50, credit: true);
        $this->assertTrue($invoice->hasPayments());
        $key = 'record-' . $this->line($invoice, 'Domain')->id;

        $this->form($invoice)->set("data.items.{$key}.price", '5')->call('save')->assertNotified(__('Invoice not saved'));
        $this->assertSame('40.00', $this->line($invoice, 'Domain')->fresh()->price);
    }

    /** A failed payment holds nothing: the amounts change. Regression coverage. */
    public function test_a_failed_payment_does_not_lock_the_amounts(): void
    {
        $invoice = $this->invoice(paid: 50, status: InvoiceTransactionStatus::Failed);
        $key = 'record-' . $this->line($invoice, 'Domain')->id;

        $this->form($invoice)->set("data.items.{$key}.price", '10.00')->call('save')->assertHasNoFormErrors();
        $this->assertSame('10.00', $this->line($invoice, 'Domain')->fresh()->price);
    }

    /** Toggling taxed changes the amount: refused with a payment, on the form and the API. Regression coverage. */
    public function test_toggling_taxed_is_refused_with_a_payment(): void
    {
        $invoice = $this->invoice(paid: 50);
        $line = $this->line($invoice, 'Domain');

        $this->form($invoice)->set('data.items.record-' . $line->id . '.taxed', true)->call('save')->assertNotified(__('Invoice not saved'));
        $this->withToken($this->token())->putJson(route('api.v1.admin.invoice-items.update', $line), ['taxed' => true])->assertStatus(422);

        $this->assertFalse($line->fresh()->taxed);
    }

    /** Raising a quantity, a non-numeric or negative price: all refused, nothing written. Regression coverage. */
    public function test_quantity_and_odd_prices_are_refused_with_a_payment(): void
    {
        $invoice = $this->invoice(paid: 90);
        $key = 'record-' . $this->line($invoice, 'Domain')->id;

        foreach ([['quantity', '2'], ['quantity', '0'], ['price', 'abc'], ['price', '-40'], ['price', '39.99'], ['quantity', '1.5']] as [$field, $value]) {
            $this->form($invoice)->set("data.items.{$key}.{$field}", $value)->call('save');
            $line = $this->line($invoice, 'Domain')->fresh();
            $this->assertSame('40.00', $line->price, "{$field}={$value}");
            $this->assertSame('40.00', $line->line_total, "{$field}={$value}");
            $this->assertSame(1, (int) $line->quantity, "{$field}={$value}");
        }
        $this->assertNotOverpaid($invoice);
    }

    /** Equivalent formats ("1.0", "40", "40.000") with a description change are a description-only edit. Regression coverage. */
    public function test_equivalent_formats_with_a_description_change_are_saved(): void
    {
        $invoice = $this->invoice(paid: 50);
        $key = 'record-' . $this->line($invoice, 'Domain')->id;

        $this->form($invoice)
            ->set("data.items.{$key}.quantity", '1.0')
            ->set("data.items.{$key}.price", '40')
            ->set("data.items.{$key}.description", 'Domain example.test')
            ->call('save')->assertNotNotified(__('Invoice not saved'))->assertHasNoFormErrors();

        $line = $invoice->items()->where('description', 'Domain example.test')->sole();
        $this->assertSame('40.00', $line->line_total);
        $this->assertNotOverpaid($invoice);
    }

    /** Re-ordering the rows and changing a description is not an amount change. Regression coverage. */
    public function test_reordered_rows_with_a_description_change_are_saved(): void
    {
        $invoice = $this->invoice(paid: 50);
        $component = $this->form($invoice);
        $items = array_reverse($component->get('data.items'), true);
        $items['record-' . $this->line($invoice, 'Hosting')->id]['description'] = 'Hosting plan';

        $component->set('data.items', $items)->call('save')->assertNotNotified(__('Invoice not saved'));

        $this->assertSame(1, $invoice->items()->where('description', 'Hosting plan')->count());
        $this->assertSame(10000, $this->billedCents($invoice->fresh()));
    }

    /** A new row is refused; a row added and removed again before the save is no change. Regression coverage. */
    public function test_an_added_row_is_refused_and_an_added_then_removed_row_is_not(): void
    {
        $invoice = $this->invoice(paid: 50);
        $component = $this->form($invoice);
        $original = $component->get('data.items');
        $items = $original;
        $items[(string) Str::uuid()] = ['description' => 'Extra', 'price' => '5.00', 'quantity' => 1, 'taxed' => false];

        $component->set('data.items', $items)->call('save')->assertNotified(__('Invoice not saved'));
        $this->assertSame(2, $invoice->items()->count());

        $component = $this->form($invoice);
        $component->set('data.items', $items)->set('data.items', $original)->call('save')->assertNotNotified(__('Invoice not saved'));
        $this->assertSame(2, $invoice->items()->count());
    }

    /** A forged row key (an id not on the invoice) with the same amounts is a removed row plus a new one: refused. Regression coverage. */
    public function test_a_forged_row_key_is_refused(): void
    {
        $invoice = $this->invoice(paid: 50);
        $component = $this->form($invoice);
        $items = $component->get('data.items');
        $key = 'record-' . $this->line($invoice, 'Domain')->id;
        $row = $items[$key];
        unset($items[$key]);
        $items['record-999999'] = $row;

        $component->set('data.items', $items)->call('save')->assertNotified(__('Invoice not saved'));
        $this->assertSame(2, $invoice->items()->count());
        $this->assertSame(10000, $this->billedCents($invoice->fresh()));
    }

    /**
     * The guard reads the stored status, not the form's: a paid invoice set back to unpaid while lowering a line in the
     * same save. The lines are saved before the invoice, still paid, so InvoiceItemObserver refuses. Regression coverage.
     */
    public function test_a_paid_invoice_set_back_to_unpaid_and_lowered_in_one_save_keeps_its_lines(): void
    {
        $invoice = $this->invoice(paid: 100);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $key = 'record-' . $this->line($invoice, 'Domain')->id;

        $this->form($invoice)->set('data.status', Invoice::STATUS_UNPAID)->set("data.items.{$key}.price", '0')->call('save');

        $this->assertSame('40.00', $this->line($invoice, 'Domain')->fresh()->price);
        $invoice = $invoice->fresh();
        $this->assertGreaterThanOrEqual($this->heldCents($invoice), $this->billedCents($invoice));
    }

    /** The API with the same amounts in other formats and a new description is a description-only edit. Regression coverage. */
    public function test_the_api_accepts_the_same_amounts_with_a_new_description(): void
    {
        $invoice = $this->invoice(paid: 50);
        $line = $this->line($invoice, 'Domain');

        $this->withToken($this->token())->putJson(route('api.v1.admin.invoice-items.update', $line), [
            'description' => 'Domain renewal', 'price' => '40.00', 'quantity' => 1, 'taxed' => false, 'reference_type' => null, 'reference_id' => null,
        ])->assertSuccessful();

        $this->assertSame('Domain renewal', $line->fresh()->description);
        $this->assertSame('40.00', $line->fresh()->line_total);
    }

    /** A sub-cent price change on the API rounds to the same price: no amount change, no refusal, nothing lowered. Regression coverage. */
    public function test_the_api_sub_cent_price_does_not_lower_the_line(): void
    {
        $invoice = $this->invoice(paid: 90);
        $line = $this->line($invoice, 'Domain');

        $this->withToken($this->token())->putJson(route('api.v1.admin.invoice-items.update', $line), ['price' => '39.999']);

        $this->assertNotOverpaid($invoice);
    }

    /** Without payments the form and the API still add, change and remove lines. Regression coverage. */
    public function test_without_payments_everything_changes(): void
    {
        $invoice = $this->invoice();
        $component = $this->form($invoice);
        $items = $component->get('data.items');
        unset($items['record-' . $this->line($invoice, 'Hosting')->id]);
        $items[(string) Str::uuid()] = ['description' => 'Extra', 'price' => '5.00', 'quantity' => 2, 'taxed' => false];

        $component->set('data.items', $items)->call('save')->assertNotNotified(__('Invoice not saved'));
        $this->assertSame(['Domain', 'Extra'], $invoice->items()->orderBy('description')->pluck('description')->all());

        $this->withToken($this->token())->postJson(route('api.v1.admin.invoice-items.store'), [
            'invoice_id' => $invoice->id, 'description' => 'More', 'price' => 1, 'quantity' => 1,
        ])->assertSuccessful();
        $this->assertSame(3, $invoice->items()->count());
    }
}
