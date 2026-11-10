<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Exceptions\DisplayException;
use App\Livewire\Invoices\Show;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Ekdosi\EkdosiFeed;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use App\Services\Reports\IncomeReport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Staff freedom on invoices, item 3 (owner 2026-11-10, sweep 7 TIGHT-INVOICES-PAYMENTS-4, docs/staff-freedom.md): staff
 * set an unpaid invoice «paid» by hand with no payment; its services renew, no payment row is made up, and a credit
 * purchase is still refused. On an unpaid invoice the WHMCS importer brought (a «Hosting» line, no period).
 */
class StaffFreedomMarkPaidTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    private Invoice $invoice;

    private Staff $staff;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-10-05 10:00:00');
        config(['settings.credits_enabled' => true]);
        $this->user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($this->user->client()->id, $product->product->id);
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsInvoice(502, 'Unpaid', [31 => 10.0], due: '2026-10-10');
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
            'permissions' => ['admin.invoices.update', 'admin.invoice_transactions.create']]);

        return $token;
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->where('client_id', $this->invoice->client_id)->value('amount') ?? 0), 2, '.', '');
    }

    /** No payment row and no journal entry was made up for it. */
    private function assertNoMoneyRecorded(): void
    {
        $this->assertSame(0, InvoiceTransaction::query()->where('invoice_id', $this->invoice->id)->count(), 'no fake payment row');
        $this->assertSame(0, MoneyEvent::query()->where('invoice_id', $this->invoice->id)->count(), 'nothing in the money journal');
        $this->assertSame('0.00', $this->credit());
    }

    public function test_staff_set_an_unpaid_imported_invoice_paid_from_the_status_select_with_the_confirmation(): void
    {
        $page = $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $this->invoice->getKey()]);

        // Without the confirmation: asked for, nothing saved.
        $page->set('data.status', Invoice::STATUS_PAID)
            ->assertSee('No payment is recorded: mark it paid anyway, with 10.00 EUR still owed on it.')
            ->call('save')->assertHasFormErrors(['paid_without_payment']);
        $this->assertSame(Invoice::STATUS_UNPAID, $this->invoice->fresh()->status);

        $page->set('data.paid_without_payment', true)->call('save')->assertHasNoFormErrors()->assertNotified(__('Check its balance'));

        $fresh = $this->invoice->fresh();
        $this->assertSame(Invoice::STATUS_PAID, $fresh->status);
        $this->assertSame('2026-10-05', $fresh->paid_at->toDateString(), 'paid when staff said so');
        $this->assertSame([1000, 1000], [RecordPaymentService::toCents($fresh->total), RecordPaymentService::toCents($fresh->remaining)], 'it still owes all of it, and says so');
        $this->assertNoMoneyRecorded();
        // Its services renew, as staff meant: the imported line (no period) gives the service its next month, once.
        $this->assertSame('2026-11-10', Service::query()->findOrFail(31)->expires_at->toDateString());
        $this->assertNotNull($fresh->items()->sole()->applied_at);
        $this->assertNull($fresh->apply_pending_since, 'applied: nothing left for the cron');
        $this->assertFalse($fresh->paid_without_payment, 'the word was for that save only');
    }

    public function test_the_admin_api_marks_paid_only_with_paid_without_payment(): void
    {
        $token = $this->token();

        $this->withToken($token)->putJson(route('api.v1.admin.invoices.update', $this->invoice), ['status' => 'paid'])->assertStatus(422);
        $this->assertSame(Invoice::STATUS_UNPAID, $this->invoice->fresh()->status, 'kept: «paid» alone still follows a payment');
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString());

        $this->withToken($token)->putJson(route('api.v1.admin.invoices.update', $this->invoice), ['status' => 'paid', 'paid_without_payment' => true])->assertSuccessful();

        $this->assertSame(Invoice::STATUS_PAID, $this->invoice->fresh()->status);
        $this->assertSame('2026-11-10', Service::query()->findOrFail(31)->expires_at->toDateString());
        $this->assertNoMoneyRecorded();
    }

    /** Kept: no code path marks an invoice paid by its status alone; only staff's own word does. */
    public function test_kept_paid_by_status_alone_is_still_refused_without_staffs_word(): void
    {
        try {
            $this->invoice->fresh()->update(['status' => Invoice::STATUS_PAID]);
            $this->fail('marked paid with something left to pay and no confirmation');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('Mark Paid', $e->getMessage());
        }
        // Nor a cancelled one, even with the word: it is reopened first.
        $this->invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'x']);
        $cancelled = $this->invoice->fresh();
        $cancelled->paid_without_payment = true;
        try {
            $cancelled->update(['status' => Invoice::STATUS_PAID]);
            $this->fail('a cancelled invoice was marked paid');
        } catch (DisplayException) {
        }

        $this->assertSame(Invoice::STATUS_CANCELLED, $this->invoice->fresh()->status);
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString());
    }

    /** Kept: a credit purchase (Add Funds) marked paid by hand would mint credit from nothing. */
    public function test_kept_a_credit_purchase_is_not_marked_paid_without_its_money(): void
    {
        $funds = Invoice::query()->create(['client_id' => $this->invoice->client_id, 'currency_code' => 'EUR', 'due_at' => now()->addWeek(), 'status' => Invoice::STATUS_UNPAID]);
        $funds->items()->create(['description' => 'Add funds', 'quantity' => 1, 'price' => '50.00', 'taxed' => false, 'reference_type' => Credit::class]);

        $page = $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $funds->getKey()]);
        $page->set('data.status', Invoice::STATUS_PAID)->set('data.paid_without_payment', true)->call('save')->assertNotified(__('Invoice not saved'));
        $this->withToken($this->token())->putJson(route('api.v1.admin.invoices.update', $funds), ['status' => 'paid', 'paid_without_payment' => true])->assertStatus(422);

        $this->assertSame(Invoice::STATUS_UNPAID, $funds->fresh()->status);
        $this->assertSame('0.00', $this->credit(), 'no credit from nothing');
        $this->assertNull($funds->items()->sole()->applied_at);
        $this->assertSame(0, MoneyEvent::query()->where('invoice_id', $funds->id)->count());
    }

    /** With a part payment on it: the rest is what staff waive; the payment stays as it is. */
    public function test_a_part_paid_invoice_marked_paid_keeps_its_payment_and_owes_the_rest(): void
    {
        (new RecordPaymentService)->handle($this->invoice, null, '4.00', transactionId: 'wire-4', enteredByStaff: true, paidAt: Carbon::parse('2026-10-02 09:00:00'));

        $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $this->invoice->getKey()])
            ->set('data.status', Invoice::STATUS_PAID)->set('data.paid_without_payment', true)->call('save')->assertHasNoFormErrors();

        $fresh = $this->invoice->fresh();
        $this->assertSame([Invoice::STATUS_PAID, 600], [$fresh->status, RecordPaymentService::toCents($fresh->remaining)]);
        $this->assertSame('4.00', (string) $fresh->transactions()->sole()->amount);
        $this->assertSame('2026-10-02', $fresh->paid_at->toDateString(), 'the date of the money it does have');
        $this->assertSame('2026-11-10', Service::query()->findOrFail(31)->expires_at->toDateString());
    }

    /** What a paid invoice with no money on it looks like in the daily money check, the income report and the Ekdosi feed. */
    public function test_money_check_income_report_and_ekdosi_feed_say_marked_paid_no_payment(): void
    {
        Gateway::query()->firstOrCreate(['extension' => 'BankTransfer'], ['name' => 'Bank Transfer', 'type' => 'gateway']);
        $marked = $this->invoice->fresh();
        $marked->paid_without_payment = true;
        $marked->update(['status' => Invoice::STATUS_PAID]);

        // The daily check: listed under paid_balance, said as what it is; no other rule trips over it.
        $found = (new MoneyCheck)->run(array_values(array_diff(MoneyCheck::RULES, ['gateway_received'])));
        $this->assertSame([['rule' => 'paid_balance', 'subject' => 'invoice #502', 'detail' => 'marked paid by staff, no payment: still to pay 10.00 EUR']], $found);

        // The income report: a row as any invoice paid in the year, and how it was «paid» is said.
        $year = IncomeReport::year('EUR', 2026);
        $row = collect($year['invoices'])->firstWhere('number', (string) ($marked->fresh()->number ?: 502));
        $this->assertSame(['10.00', 'Marked paid by staff, no payment'], [$row['total'], $row['paid_with']]);
        $before = collect($year['invoices'])->firstWhere('number', (string) (Invoice::query()->findOrFail(480)->number ?: 480));
        $this->assertNotSame('Marked paid by staff, no payment', $before['paid_with'], 'an invoice with its payment reads as before');

        // The Ekdosi feed: Paid, in paid_unfiled, with no transactions, and `mycs.balance` says what is owed on it.
        $page = (new EkdosiFeed)->page('paid_unfiled', null, 0, 50);
        $fed = collect($page['invoices'])->firstWhere('invoiceid', 502);
        $this->assertSame(['Paid', [], '10.00', '10.00', ''], [$fed['status'], $fed['transactions'], $fed['total'], $fed['mycs']['balance'], $fed['paymentmethod']]);
        $this->assertSame('0.00', collect($page['invoices'])->firstWhere('invoiceid', 480)['mycs']['balance'], 'a paid invoice with its money: nothing owed');

        // And the customer: the page says «paid», and that 10.00 is still to pay.
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $marked->fresh()])->assertOk()
            ->assertSee(__('invoices.paid_owes'))->assertSee($marked->formattedTotal->format(10.0));
    }
}
