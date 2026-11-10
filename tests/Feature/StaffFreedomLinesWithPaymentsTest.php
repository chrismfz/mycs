<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\StaffLineEdit;
use App\Services\Money\MoneyCheck;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Staff freedom on invoices, item 2 (owner 2026-11-10, sweep 7 TIGHT-INVOICES-PAYMENTS-2, docs/staff-freedom.md): the
 * lines of an UNPAID invoice that already has a payment change. On an invoice the WHMCS importer brought (a «Hosting»
 * line with no period, never applied) with a part payment on it.
 */
class StaffFreedomLinesWithPaymentsTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

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
        // Last month's, paid in WHMCS (a service always comes with its earlier invoices); then the one these tests are
        // about: unpaid, 10.00 for the service's next month, 4.00 of it paid by bank transfer.
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsInvoice(502, 'Unpaid', [31 => 10.0], due: '2026-10-10', paid: 4.0);
        $this->importWhmcs();
        $this->invoice = $this->imported(502);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function token(): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => $this->staff->id, 'name' => 'f', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'permissions' => ['admin.invoice_items.update', 'admin.invoice_items.delete', 'admin.invoice_items.create']]);

        return $token;
    }

    private function cents(mixed $amount): int
    {
        return RecordPaymentService::toCents($amount);
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->where('client_id', $this->invoice->client_id)->value('amount') ?? 0), 2, '.', '');
    }

    public function test_staff_raise_a_line_of_a_part_paid_imported_invoice_and_it_just_owes_more(): void
    {
        $this->assertSame(600, $this->cents($this->invoice->remaining), 'precondition: 10.00 billed, 4.00 paid');
        $page = $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $this->invoice->getKey()]);
        $line = array_key_first($page->get('data.items'));

        $page->assertFormFieldIsEnabled("items.{$line}.price")
            ->set("data.items.{$line}.price", '15.00')->call('save')->assertNotNotified(__('Check its balance'));

        $fresh = $this->invoice->fresh();
        $this->assertSame([1500, 1100, Invoice::STATUS_UNPAID], [$this->cents($fresh->total), $this->cents($fresh->remaining), $fresh->status]);
        $this->assertSame('4.00', (string) $fresh->transactions()->sole()->amount, 'the payment is as it was');
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString(), 'nothing renewed by an edit');
        $this->assertSame([], (new MoneyCheck)->run(['paid_balance', 'open_settled', 'line_totals']));
    }

    public function test_staff_lower_a_line_below_the_payment_are_told_and_close_it_with_the_part_over_as_credit(): void
    {
        $line = $this->invoice->items()->sole();

        $response = $this->withToken($this->token())->putJson(route('api.v1.admin.invoice-items.update', $line), ['price' => '3.00'])->assertSuccessful();

        $this->assertStringContainsString('1.00 EUR', (string) $response->json('warning'), 'the API says it is paid over');
        $this->assertStringContainsString('Close as paid', (string) $response->json('warning'));
        $fresh = $this->invoice->fresh();
        $this->assertSame([300, -100, Invoice::STATUS_UNPAID], [$this->cents($fresh->total), $this->cents($fresh->remaining), $fresh->status], 'open, 1.00 over: not closed on its own');
        $this->assertSame('0.00', $this->credit(), 'and nothing became credit on its own');
        $this->assertSame(['open_settled'], array_column((new MoneyCheck)->run(['paid_balance', 'open_settled']), 'rule'), 'the daily check names it until staff close it');
        $this->assertSame(StaffLineEdit::balanceNotice($this->invoice->id), $response->json('warning'));

        // What the warning says to do.
        $this->assertTrue((new RecordPaymentService)->settle($this->invoice->id));

        $fresh = $this->invoice->fresh();
        $this->assertSame([Invoice::STATUS_PAID, 0], [$fresh->status, $this->cents($fresh->remaining)]);
        $this->assertSame('1.00', $this->credit(), 'the part over is the customer\'s, once');
        $this->assertSame('1.00', (string) $fresh->transactions()->sole()->credited_amount);
        // Its period: the imported line had none; paid now, it gives the service its next month, once.
        $this->assertSame('2026-11-10', Service::query()->findOrFail(31)->expires_at->toDateString());
        $this->assertNotNull($fresh->items()->sole()->applied_at);
        $this->assertSame([], (new MoneyCheck)->run(['paid_balance', 'open_settled', 'credit_journal']));
    }

    public function test_a_line_is_added_and_one_taken_off_over_the_api_with_a_payment_on_it(): void
    {
        $token = $this->token();

        $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), [
            'invoice_id' => $this->invoice->id, 'description' => 'Setup', 'price' => '5.00', 'quantity' => 1, 'taxed' => false,
        ])->assertSuccessful()->assertJsonMissingPath('warning');
        $this->assertSame([1500, 1100], [$this->cents($this->invoice->fresh()->total), $this->cents($this->invoice->fresh()->remaining)]);

        $hosting = $this->invoice->items()->where('reference_type', Service::class)->sole();
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoice-items.destroy', $hosting))->assertNoContent();

        $fresh = $this->invoice->fresh();
        $this->assertSame([500, 100, Invoice::STATUS_UNPAID], [$this->cents($fresh->total), $this->cents($fresh->remaining), $fresh->status]);
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString());
    }

    /** Kept: an invoice Ekdosi issued its document for keeps its amounts, with or without a payment (issued is final). */
    public function test_kept_a_fiscal_document_from_ekdosi_still_freezes_the_lines(): void
    {
        EkdosiDocument::query()->create(['invoice_id' => $this->invoice->id, 'mark' => '400001234500001', 'code' => 'ΤΠΥ9', 'state' => EkdosiDocument::ACTIVE]);
        $line = $this->invoice->items()->sole();
        $token = $this->token();

        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['price' => '3.00'])->assertStatus(422);
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoice-items.destroy', $line))->assertStatus(422);
        $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), [
            'invoice_id' => $this->invoice->id, 'description' => 'Setup', 'price' => '5.00', 'quantity' => 1,
        ])->assertStatus(422);
        $page = $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $this->invoice->getKey()]);
        $page->assertFormFieldIsDisabled('items.' . array_key_first($page->get('data.items')) . '.price');

        $this->assertSame([1000, 600], [$this->cents($this->invoice->fresh()->total), $this->cents($this->invoice->fresh()->remaining)]);
        // A description still changes.
        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['description' => 'Hosting, renamed'])->assertSuccessful();
    }

    /** Kept: the change runs under the payment locks (customer, then invoice), so a payment landing now waits for it. */
    public function test_kept_the_line_edit_still_takes_the_payment_locks(): void
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        StaffLineEdit::run($this->invoice->id, true, fn (Invoice $locked) => $locked->items()->sole()->update(['price' => '12.00']));

        $tables = collect($statements)->map(fn (string $sql) => preg_match('/^select .*? from [`"]?(clients|invoices|invoice_items)[`"]?/i', $sql, $m) ? $m[1] : null)->filter()->values();
        // Its owner read plain, then the customer, then the invoice, and only then its lines (docs/money-trail.md §2).
        $this->assertSame(['invoices', 'clients', 'invoices', 'invoice_items'], $tables->take(4)->all());
        $this->assertSame(1200, $this->cents($this->invoice->fresh()->total));
    }
}
