<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Enums\InvoiceTransactionStatus;
use App\Models\ApiKey;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\StaffLineEdit;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PR B (sweep Π2.11): staff change an invoice's lines under the payment locks. Since the owner's decision of 2026-11-10
 * (sweep 7 TIGHT-INVOICES-PAYMENTS-2, docs/staff-freedom.md) an open invoice with payments on it changes as any other:
 * raised, it owes more; lowered below what was paid, it is paid over and staff are told («Close as paid»). Rewritten
 * from the tests that pinned the refusal.
 */
class StaffLineEditTest extends TestCase
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

    /** An invoice of two lines (60 + 40), $paid of it paid by transfer. */
    private function invoice(float $paid = 0): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 60, 'taxed' => false]);
        $invoice->items()->create(['description' => 'Domain', 'quantity' => 1, 'price' => 40, 'taxed' => false]);
        if ($paid > 0) {
            (new RecordPaymentService)->handle($invoice->fresh(), null, $paid, transactionId: 'wire-' . $invoice->id);
        }

        return $invoice->fresh();
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function token(array $permissions): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 'b', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $token), 'permissions' => $permissions]);

        return $token;
    }

    private function line(Invoice $invoice, string $description): InvoiceItem
    {
        return $invoice->items()->where('description', $description)->sole();
    }

    /** Lowered, still above what was paid: it just owes less. No warning, nothing closed. */
    public function test_the_form_changes_the_amounts_of_an_invoice_with_a_payment(): void
    {
        $invoice = $this->invoice(paid: 50);
        $component = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $key = 'record-' . $this->line($invoice, 'Domain')->id;

        $component->assertFormFieldIsEnabled("items.{$key}.price")
            ->set("data.items.{$key}.price", '10.00')->call('save')->assertNotNotified(__('Check its balance')); // one look: reading the notifications empties them

        $this->assertSame('10.00', $this->line($invoice, 'Domain')->price);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(20.0, $invoice->fresh()->remaining, '70 billed, 50 paid');
    }

    /** A line taken off below what was paid: the invoice stays open, paid over, and staff are told; the payment is whole. */
    public function test_the_form_takes_a_line_off_an_invoice_with_a_payment_and_warns_it_is_paid_over(): void
    {
        $invoice = $this->invoice(paid: 50);
        $component = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $items = $component->get('data.items');
        unset($items['record-' . $this->line($invoice, 'Hosting')->id]);

        $component->set('data.items', $items)->call('save')->assertNotified(__('Check its balance'));

        $fresh = $invoice->fresh();
        $this->assertSame(1, $fresh->items()->count());
        $this->assertSame(Invoice::STATUS_UNPAID, $fresh->status, 'an edit never closes it on its own');
        $this->assertSame(-10.0, $fresh->remaining, '40 billed, 50 paid');
        $this->assertSame('50.00', (string) $fresh->transactions()->sole()->amount);
        $this->assertSame('0.00', (string) $fresh->transactions()->sole()->credited_amount, 'nothing became credit yet');
        $this->assertStringContainsString('10.00 USD', (string) StaffLineEdit::balanceNotice($invoice->id));

        // «Close as paid» is the way on: the 10 over becomes the customer's credit.
        $this->assertTrue((new RecordPaymentService)->settle($invoice->id));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(0.0, $invoice->fresh()->remaining);
        $this->assertSame('10.00', number_format((float) DB::table('credits')->where('client_id', $this->user->client()->id)->value('amount'), 2, '.', ''));
    }

    public function test_the_form_still_changes_a_description_of_an_invoice_with_a_payment(): void
    {
        $invoice = $this->invoice(paid: 50);
        $component = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $key = 'record-' . $this->line($invoice, 'Domain')->id;

        $component->set("data.items.{$key}.description", 'Domain example.test')->call('save')->assertHasNoFormErrors();

        $this->assertSame(1, $invoice->items()->where('description', 'Domain example.test')->count());
    }

    public function test_the_form_changes_the_amounts_of_an_invoice_without_payments(): void
    {
        $invoice = $this->invoice();
        $component = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $key = 'record-' . $this->line($invoice, 'Domain')->id;

        $component->set("data.items.{$key}.price", '10.00')->call('save')->assertHasNoFormErrors();

        $this->assertSame('10.00', $this->line($invoice, 'Domain')->price);
    }

    /** The API too: raised it owes more (no warning), a line added, then lowered below the payment with the warning. */
    public function test_the_api_changes_the_amounts_of_an_invoice_with_a_payment(): void
    {
        $invoice = $this->invoice(paid: 50);
        $line = $this->line($invoice, 'Domain');
        $token = $this->token(['admin.invoice_items.update', 'admin.invoice_items.delete', 'admin.invoice_items.create']);

        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['price' => 45])->assertSuccessful()->assertJsonMissingPath('warning');
        $this->assertSame(55.0, $invoice->fresh()->remaining, 'raised: it owes more');
        $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), [
            'invoice_id' => $invoice->id, 'description' => 'Extra', 'price' => 5, 'quantity' => 1, 'taxed' => false,
        ])->assertSuccessful()->assertJsonMissingPath('warning');
        $this->assertSame(3, $invoice->items()->count());
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoice-items.destroy', $this->line($invoice, 'Hosting')))->assertNoContent();
        $this->assertSame(0.0, $invoice->fresh()->remaining, '45 + 5 billed, 50 paid');
        $warned = $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['price' => 10])->assertSuccessful();
        $this->assertStringContainsString('35.00 USD', (string) $warned->json('warning'));

        $fresh = $invoice->fresh();
        $this->assertSame('10.00', $line->fresh()->price);
        $this->assertSame(-35.0, $fresh->remaining, '15 billed, 50 paid: over, and said');
        $this->assertSame(Invoice::STATUS_UNPAID, $fresh->status);
        $this->assertSame('50.00', (string) $fresh->transactions()->sole()->amount, 'the payment is untouched');
    }

    public function test_the_api_changes_the_amounts_of_an_invoice_without_payments(): void
    {
        $invoice = $this->invoice();
        $line = $this->line($invoice, 'Domain');
        $token = $this->token(['admin.invoice_items.update', 'admin.invoice_items.delete']);

        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['price' => 10])->assertSuccessful();
        $this->assertSame('10.00', $line->fresh()->price);
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoice-items.destroy', $line))->assertNoContent();
        $this->assertSame(1, $invoice->items()->count());
    }

    public function test_the_form_adds_a_line_and_changes_tax_and_quantity_with_a_payment(): void
    {
        $invoice = $this->invoice(paid: 50);
        $key = 'record-' . $this->line($invoice, 'Domain')->id;
        foreach ([
            fn ($c) => $c->set('data.items', $c->get('data.items') + ['new-row' => ['price' => '5.00', 'quantity' => 1, 'taxed' => false, 'description' => 'Extra']]),
            fn ($c) => $c->set("data.items.{$key}.taxed", true),
            fn ($c) => $c->set("data.items.{$key}.quantity", 2),
        ] as $change) {
            $change($this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]))
                ->call('save')->assertNotNotified(__('Invoice not saved'));
        }

        $this->assertSame(3, $invoice->items()->count());
        $line = $this->line($invoice, 'Domain');
        $this->assertSame(['40.00', 2, true], [$line->price, (int) $line->quantity, (bool) $line->taxed]);
        // 60 + 5 + 2 × 40 (and its tax, if the account has any) billed, 50 paid: it owes the rest.
        $this->assertSame(RecordPaymentService::toCents($invoice->fresh()->total) - 5000, RecordPaymentService::toCents($invoice->fresh()->remaining));
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    public function test_a_processing_payment_does_not_keep_the_amounts(): void
    {
        $invoice = $this->invoice();
        $invoice->transactions()->create(['amount' => 100, 'status' => InvoiceTransactionStatus::Processing->value, 'transaction_id' => 'sepa-1']);
        $key = 'record-' . $this->line($invoice, 'Domain')->id;

        $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->set("data.items.{$key}.price", '10.00')->call('save')->assertNotNotified(__('Invoice not saved'));

        $this->assertSame('10.00', $this->line($invoice, 'Domain')->price);
        $this->assertSame(70.0, $invoice->fresh()->remaining, 'money on its way is not counted until it lands');
    }

    /**
     * Frozen clock: it reads today when it builds the data and again when it checks, and a run across midnight
     * (UTC) saw two different days (2026-11-05).
     */
    public function test_a_save_that_changes_no_line_is_not_refused(): void
    {
        $this->freezeTime();
        $invoice = $this->invoice(paid: 50);

        $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->set('data.due_at', now()->addWeeks(2)->toDateString())->call('save')->assertHasNoFormErrors();

        $this->assertSame(now()->addWeeks(2)->toDateString(), $invoice->fresh()->due_at->toDateString());
    }

    public function test_a_draft_without_payments_changes_its_amounts(): void
    {
        $invoice = $this->invoice();
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_DRAFT]);
        $key = 'record-' . $this->line($invoice, 'Domain')->id;

        $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->set("data.items.{$key}.price", '10.00')->call('save')->assertHasNoFormErrors();

        $this->assertSame('10.00', $this->line($invoice, 'Domain')->price);
    }

    public function test_the_api_changes_quantity_and_tax_of_an_invoice_with_a_payment(): void
    {
        $invoice = $this->invoice(paid: 50);
        $line = $this->line($invoice, 'Domain');
        $token = $this->token(['admin.invoice_items.update']);

        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['quantity' => 2])->assertSuccessful();
        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['taxed' => true])->assertSuccessful();

        $this->assertSame(['40.00', 2, true], [$line->fresh()->price, (int) $line->fresh()->quantity, (bool) $line->fresh()->taxed]);
        $this->assertSame(RecordPaymentService::toCents($invoice->fresh()->total) - 5000, RecordPaymentService::toCents($invoice->fresh()->remaining));
    }
}
