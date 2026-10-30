<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Payment\AutoCharge;
use App\Services\Tax\EuVat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Adversarial review of #554 (an invoice that changes customer is readdressed and retaxed by InvoiceObserver::updated,
 * InvoiceReaddress). test_bug_* fail on the PR as it stands; test_holds_* are guarantees that held.
 */
class InvoiceClientChangeAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'EUR', 'settings.invoice_snapshot' => true, 'settings.tax_enabled' => true,
            'settings.tax_type' => 'exclusive', 'settings.eu_vat_enabled' => true, 'settings.eu_vat_home_country' => 'GR']);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        TaxRate::query()->create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
        TaxRate::query()->create(['name' => 'MwSt', 'rate' => 19, 'country' => 'DE']);
    }

    private function customer(string $country, string $first): Client
    {
        $user = User::factory()->create(['first_name' => $first]);
        $user->client()->primaryContact->update(['country' => $country, 'first_name' => $first]);

        return $user->client()->fresh();
    }

    private function invoice(Client $client, float $price = 100, string $status = Invoice::STATUS_UNPAID): Invoice
    {
        $invoice = Invoice::query()->create(['client_id' => $client->id, 'currency_code' => 'EUR', 'due_at' => now(), 'status' => $status]);
        $invoice->items()->create(['description' => 'Consulting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function move(Invoice $invoice, Client $to): Invoice
    {
        $invoice = Invoice::query()->findOrFail($invoice->id);
        $invoice->update(['client_id' => $to->id]);

        return Invoice::query()->findOrFail($invoice->id);
    }

    public function test_holds_a_move_there_and_back_gives_the_starting_totals(): void
    {
        $greek = $this->customer('GR', 'Eleni');
        $invoice = $this->invoice($greek);
        $start = [(float) $invoice->total, (float) $invoice->taxTotal, (string) $invoice->snapshot?->name];

        $invoice = $this->move($invoice, $this->customer('US', 'John'));
        $this->assertSame(100.0, (float) $invoice->total);
        $invoice = $this->move($invoice, $greek);

        $this->assertSame($start, [(float) $invoice->total, (float) $invoice->taxTotal, (string) $invoice->snapshot?->name]);
        $this->assertSame(124.0, (float) $invoice->total);
    }

    public function test_holds_inclusive_mode_keeps_the_net_and_only_the_tax_follows(): void
    {
        config(['settings.tax_type' => 'inclusive']);
        $greek = $this->customer('GR', 'Eleni');
        $invoice = $this->invoice($greek, 80.65); // lines store net (Δ1)
        $this->assertSame(100.01, (float) $invoice->total);

        $invoice = $this->move($invoice, $this->customer('US', 'John'));
        $this->assertSame(80.65, (float) $invoice->total);
        $this->assertSame(100.01, (float) $this->move($invoice, $greek)->total);
    }

    public function test_holds_a_line_staff_marked_not_taxed_stays_untaxed(): void
    {
        $invoice = $this->invoice($this->customer('US', 'John'));
        $invoice->items()->create(['description' => 'Fee', 'quantity' => 1, 'price' => 10, 'taxed' => false]);

        $invoice = $this->move($invoice, $this->customer('GR', 'Eleni'));

        $lines = $invoice->items()->orderBy('id')->get();
        $this->assertSame([24.0, 0.0], [(float) $lines[0]->tax_rate, (float) $lines[1]->tax_rate]);
        $this->assertSame(EuVat::REASON_NOT_TAXABLE, $lines[1]->tax_reason);
        $this->assertSame(134.0, (float) $invoice->total);
    }

    public function test_holds_a_credit_purchase_stays_untaxed_and_buys_for_the_new_customer(): void
    {
        $invoice = Invoice::query()->create(['client_id' => $this->customer('US', 'John')->id, 'currency_code' => 'EUR', 'due_at' => now(), 'status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->create(['description' => 'Credit', 'quantity' => 1, 'price' => 50, 'reference_type' => Credit::class, 'taxed' => false]);

        $invoice = $this->move($invoice, $this->customer('GR', 'Eleni'));

        $line = $invoice->items()->sole();
        $this->assertSame([0.0, null, 50.0], [(float) $line->tax_rate, $line->tax_reason, (float) $invoice->total]);
    }

    public function test_holds_an_eu_company_is_reverse_charged_only_with_a_valid_vies_number(): void
    {
        $invoice = $this->invoice($this->customer('GR', 'Eleni'));
        $company = $this->customer('DE', 'Hans');
        $company->primaryContact->update(['type' => 'business', 'vat_number' => 'DE123456789']);
        $company->primaryContact->forceFill(['vat_status' => EuVat::STATUS_PENDING])->save();

        $moved = $this->move($invoice, $company);
        $this->assertNotSame(EuVat::REASON_REVERSE_CHARGE, $moved->items()->sole()->tax_reason, 'pending: not reverse charged');
        $this->assertGreaterThan(0.0, (float) $moved->items()->sole()->tax_rate);

        $other = $this->invoice($this->customer('GR', 'Maria'));
        $valid = $this->customer('DE', 'Klaus');
        $valid->primaryContact->update(['type' => 'business', 'vat_number' => 'DE123456789']);
        $valid->primaryContact->forceFill(['vat_status' => EuVat::STATUS_VALID, 'vat_checked_number' => EuVat::parse('DE123456789', 'DE')?->full()])->save();
        $moved = $this->move($other, $valid);
        $this->assertSame([0.0, EuVat::REASON_REVERSE_CHARGE], [(float) $moved->items()->sole()->tax_rate, $moved->items()->sole()->tax_reason]);
        $this->assertSame(100.0, (float) $moved->total);
    }

    public function test_holds_a_draft_moved_is_issued_to_the_new_customer(): void
    {
        $draft = $this->invoice($this->customer('GR', 'Eleni'), status: Invoice::STATUS_DRAFT);
        $this->assertNull($draft->snapshot, 'precondition: a draft has no snapshot');

        $draft = $this->move($draft, $this->customer('US', 'John'));
        $this->assertNull($draft->snapshot);
        $draft->update(['status' => Invoice::STATUS_UNPAID]);

        $draft = Invoice::query()->findOrFail($draft->id);
        $this->assertStringContainsString('John', (string) $draft->snapshot?->name);
        $this->assertSame(100.0, (float) $draft->total);
    }

    public function test_holds_the_ekdosi_request_is_forgotten(): void
    {
        $invoice = $this->invoice($this->customer('GR', 'Eleni'));
        Invoice::query()->whereKey($invoice->id)->update(['ekdosi_requested_at' => now()]);

        $this->move($invoice, $this->customer('US', 'John'));

        $this->assertNull(Invoice::query()->whereKey($invoice->id)->value('ekdosi_requested_at'));
    }

    public function test_holds_the_customer_page_is_the_new_customers(): void
    {
        $greek = $this->customer('GR', 'Eleni');
        $abroad = $this->customer('US', 'John');
        $invoice = $this->move($this->invoice($greek), $abroad);

        $john = $abroad->owner();
        $this->actingAs($john)->withSession($this->loginUser($john))
            ->get(route('invoices.show', $invoice))->assertOk()->assertSee('John');
        $eleni = $greek->owner();
        $this->actingAs($eleni)->withSession($this->loginUser($eleni))
            ->get(route('invoices.show', $invoice))->assertNotFound();
    }

    /** AutoCharge reads the invoice's current customer: never the first customer's saved card. */
    public function test_holds_autocharge_never_picks_the_first_customers_card(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway', 'enabled' => true]);
        $greek = $this->customer('GR', 'Eleni');
        $card = BillingAgreement::query()->create(['client_id' => $greek->id, 'gateway_id' => $gateway->id, 'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $greek->refresh()->forceFill(['auto_charge' => true])->save();
        $invoice = $this->invoice($greek);

        $abroad = $this->customer('US', 'John');
        $moved = $this->move($invoice, $abroad);

        $this->assertNull(AutoCharge::agreementFor(Invoice::query()->with('client')->findOrFail($moved->id)));
        $this->assertNotSame($card->id, AutoCharge::agreementFor(Invoice::query()->with('client')->findOrFail($moved->id))?->id);
    }

    /**
     * ADV-554-1: a cancelled invoice (its cancellation credit note issued for its total) moved to a customer of another
     * tax is retaxed, but its note is not: it is left at -24.00, a cancelled invoice that owes the customer money.
     */
    public function test_bug_a_cancelled_invoice_moved_is_retaxed_under_its_credit_note(): void
    {
        $invoice = $this->invoice($this->customer('GR', 'Eleni'));
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        $invoice = Invoice::query()->findOrFail($invoice->id);
        $this->assertSame(0.0, (float) $invoice->total, 'precondition: its cancellation note takes it to zero');

        try {
            $invoice = $this->move($invoice, $this->customer('US', 'John'));
        } catch (DisplayException) {
            $invoice = Invoice::query()->findOrFail($invoice->id); // refused: also a fix
        }

        $this->assertSame(0.0, (float) $invoice->total, 'a cancelled invoice still bills nothing');
    }

    /**
     * ADV-554-1 (same root): a paid invoice with no payments (a free one, closed as paid) could change customer before; now
     * its retax trips «A paid invoice keeps its lines» and the move fails with a message about something staff never did.
     */
    public function test_bug_a_paid_invoice_without_payments_cannot_move_any_more(): void
    {
        $invoice = $this->invoice($this->customer('GR', 'Eleni'), 0);
        (new RecordPaymentService)->settle($invoice->id);
        $this->assertSame(Invoice::STATUS_PAID, Invoice::query()->whereKey($invoice->id)->value('status'), 'precondition');

        $moved = $this->move($invoice, $abroad = $this->customer('US', 'John'));

        $this->assertSame($abroad->id, (int) $moved->client_id);
    }

    /**
     * ADV-554-2: the first customer's card was charged (taken, its webhook not here yet). MoveToClient refuses this move
     * (InvoiceLineMove::refusal, SavedMethodCharge::onItsWay); staff's edit and the API do not, and the first customer's
     * money then lands on the second customer's invoice.
     */
    public function test_bug_a_card_charge_on_its_way_does_not_stop_staffs_move(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway', 'enabled' => true]);
        $greek = $this->customer('GR', 'Eleni');
        $card = BillingAgreement::query()->create(['client_id' => $greek->id, 'gateway_id' => $gateway->id, 'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $invoice = $this->invoice($greek);
        ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'client_id' => $greek->id,
            'billing_agreement_id' => $card->id, 'amount' => 124, 'currency_code' => 'EUR', 'source' => ChargeAttempt::SOURCE_CLIENT,
            'status' => ChargeAttempt::STATUS_SUCCEEDED, 'started_at' => now(), 'sent_at' => now(), 'finished_at' => now()]);

        try {
            $this->move($invoice, $this->customer('US', 'John'));
        } catch (DisplayException) {
            // refused, as MoveToClient refuses it
        }

        $this->assertSame($greek->id, (int) Invoice::query()->whereKey($invoice->id)->value('client_id'), 'stays with the customer whose card paid it');
    }

    /**
     * ADV-554-3: MoveToClient gives a moved invoice the new account's preferred method (R3-2 of #539: never the old card's
     * gateway); staff's edit and the API keep the first customer's, named on the new customer's email and payment page.
     */
    public function test_bug_staffs_move_keeps_the_first_customers_preferred_gateway(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway', 'enabled' => true]);
        $greek = $this->customer('GR', 'Eleni');
        BillingAgreement::query()->create(['client_id' => $greek->id, 'gateway_id' => $gateway->id, 'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $invoice = $this->invoice($greek->refresh());
        $this->assertSame($gateway->id, (int) $invoice->preferred_gateway_id, 'precondition: the saved card\'s gateway');

        $abroad = $this->customer('US', 'John');
        $moved = $this->move($invoice, $abroad);

        $this->assertSame(Invoice::preferredGatewayFor($abroad->id), $moved->preferred_gateway_id);
    }
}
