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
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PR B (sweep Π2.11): staff change an invoice's lines under the payment locks, and an open invoice with payments on it
 * keeps its amounts, as Blesta does: a line taken off or lowered left a payment over what is owed on an invoice still
 * unpaid. Descriptions still change; without payments, everything does.
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

    public function test_the_form_keeps_the_amounts_of_an_invoice_with_a_payment(): void
    {
        $invoice = $this->invoice(paid: 50);
        $component = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $key = 'record-' . $this->line($invoice, 'Domain')->id;

        $component->set("data.items.{$key}.price", '10.00')->call('save')->assertNotified(__('Invoice not saved'));

        $this->assertSame('40.00', $this->line($invoice, 'Domain')->price);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    public function test_the_form_does_not_take_a_line_off_an_invoice_with_a_payment(): void
    {
        $invoice = $this->invoice(paid: 50);
        $component = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $items = $component->get('data.items');
        unset($items['record-' . $this->line($invoice, 'Domain')->id]);

        $component->set('data.items', $items)->call('save')->assertNotified(__('Invoice not saved'));

        $this->assertSame(2, $invoice->items()->count());
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

    public function test_the_api_keeps_the_amounts_of_an_invoice_with_a_payment(): void
    {
        $invoice = $this->invoice(paid: 50);
        $line = $this->line($invoice, 'Domain');
        $token = $this->token(['admin.invoice_items.update', 'admin.invoice_items.delete', 'admin.invoice_items.create']);

        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['price' => 10])->assertStatus(422);
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoice-items.destroy', $line))->assertStatus(422);
        $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), [
            'invoice_id' => $invoice->id, 'description' => 'Extra', 'price' => 5, 'quantity' => 1,
        ])->assertStatus(422);

        $this->assertSame('40.00', $line->fresh()->price);
        $this->assertSame(2, $invoice->items()->count());

        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['description' => 'Domain renewal'])->assertSuccessful();
        $this->assertSame('Domain renewal', $line->fresh()->description);
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

    public function test_the_form_does_not_add_a_line_or_toggle_tax_or_quantity_with_a_payment(): void
    {
        $invoice = $this->invoice(paid: 50);
        $key = 'record-' . $this->line($invoice, 'Domain')->id;
        foreach ([
            fn ($c) => $c->set('data.items', $c->get('data.items') + ['new-row' => ['price' => '5.00', 'quantity' => 1, 'taxed' => false, 'description' => 'Extra']]),
            fn ($c) => $c->set("data.items.{$key}.taxed", true),
            fn ($c) => $c->set("data.items.{$key}.quantity", 2),
        ] as $change) {
            $change($this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]))
                ->call('save')->assertNotified(__('Invoice not saved'));
        }

        $this->assertSame(2, $invoice->items()->count());
        $line = $this->line($invoice, 'Domain');
        $this->assertSame(['40.00', 1, false], [$line->price, (int) $line->quantity, (bool) $line->taxed]);
    }

    public function test_a_processing_payment_keeps_the_amounts_too(): void
    {
        $invoice = $this->invoice();
        $invoice->transactions()->create(['amount' => 100, 'status' => InvoiceTransactionStatus::Processing->value, 'transaction_id' => 'sepa-1']);
        $key = 'record-' . $this->line($invoice, 'Domain')->id;

        $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->set("data.items.{$key}.price", '10.00')->call('save')->assertNotified(__('Invoice not saved'));

        $this->assertSame('40.00', $this->line($invoice, 'Domain')->price);
    }

    public function test_a_save_that_changes_no_line_is_not_refused(): void
    {
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

    public function test_the_api_keeps_quantity_and_tax_of_an_invoice_with_a_payment(): void
    {
        $invoice = $this->invoice(paid: 50);
        $line = $this->line($invoice, 'Domain');
        $token = $this->token(['admin.invoice_items.update']);

        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['quantity' => 2])->assertStatus(422);
        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['taxed' => true])->assertStatus(422);
        // The same amounts sent again change nothing: not refused.
        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['price' => '40.0', 'quantity' => 1, 'taxed' => false])->assertSuccessful();

        $this->assertSame(['40.00', 1, false], [$line->fresh()->price, (int) $line->fresh()->quantity, (bool) $line->fresh()->taxed]);
    }
}
