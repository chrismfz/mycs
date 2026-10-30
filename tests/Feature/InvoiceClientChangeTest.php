<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * An open invoice staff move to another customer (its edit, the API) is addressed and taxed as the new one's (sweep 9γ,
 * owner 2026-10-30: keep it simple), as MoveToClient does: its snapshot (name, tax) taken again, its lines' tax decided
 * again. One with payments, or renewing the first customer's services, cannot move (InvoiceObserver).
 */
class InvoiceClientChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'EUR', 'settings.invoice_snapshot' => true, 'settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        TaxRate::query()->create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
    }

    private function customer(string $country, string $first): User
    {
        $user = User::factory()->create(['first_name' => $first]);
        $user->client()->primaryContact->update(['country' => $country, 'first_name' => $first]);

        return $user;
    }

    public function test_staff_moving_an_invoice_to_another_customer_readdress_and_retax_it(): void
    {
        $greek = $this->customer('GR', 'Eleni');
        $abroad = $this->customer('US', 'John');
        $invoice = Invoice::query()->create(['client_id' => $greek->client()->id, 'currency_code' => 'EUR', 'due_at' => now(), 'status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->create(['description' => 'Consulting', 'quantity' => 1, 'price' => 100]);
        $invoice = $invoice->fresh();
        $this->assertSame([24.0, 124.0], [(float) $invoice->items()->sole()->tax_rate, (float) $invoice->total], 'precondition: Greek VAT');
        $this->assertStringContainsString('Eleni', (string) $invoice->snapshot?->name);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->livewireAs(Staff::factory()->admin()->create())->test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
            ->fillForm(['client_id' => $abroad->client()->id])->call('save')->assertHasNoFormErrors();

        $invoice = Invoice::query()->findOrFail($invoice->id);
        $this->assertSame($abroad->client()->id, (int) $invoice->client_id);
        $this->assertSame(0.0, (float) $invoice->items()->sole()->tax_rate, 'outside the EU: no Greek VAT');
        $this->assertSame(100.0, (float) $invoice->total);
        $this->assertStringContainsString('John', (string) $invoice->snapshot?->name, 'addressed to the new customer');
    }

    /** Code review F2 of #554: moved and cancelled in one save, its credit note is the new total, never the old one. */
    public function test_moved_and_cancelled_in_one_save_its_credit_note_is_the_new_total(): void
    {
        $greek = $this->customer('GR', 'Eleni');
        $abroad = $this->customer('US', 'John');
        $invoice = Invoice::query()->create(['client_id' => $greek->client()->id, 'currency_code' => 'EUR', 'due_at' => now(), 'status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->create(['description' => 'Consulting', 'quantity' => 1, 'price' => 100]);
        $invoice = Invoice::query()->with('items')->findOrFail($invoice->id);

        $invoice->update(['client_id' => $abroad->client()->id, 'status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Wrong customer']);

        $invoice = Invoice::query()->findOrFail($invoice->id);
        $this->assertSame([100.0, 0.0], [(float) $invoice->items()->sum('line_total'), (float) $invoice->total], 'its note takes the new 100 to zero');
    }
}
