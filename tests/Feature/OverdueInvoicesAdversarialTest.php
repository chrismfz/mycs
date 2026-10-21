<?php

namespace Tests\Feature;

use App\Admin\AdminSidebar;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of #286 (overdue invoices). The PR says overdue is counted «as the overdue reminders count»
 * (PaymentReminders); the reminders' open() leaves out what nobody owes or what is being paid already.
 */
class OverdueInvoicesAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
        $this->travelTo('2026-10-21 10:00:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
        $this->clientId = User::factory()->create()->client()->id;
    }

    private function late(string $due = '2026-10-11'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->clientId, 'status' => Invoice::STATUS_UNPAID, 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 20]);

        return $invoice;
    }

    /**
     * ADV-1: an «add funds» invoice left at the gateway (Livewire\Client\Credits: due_at now(); WHMCS AddFunds imported
     * as Credit lines) is not a debt (PaymentReminders::open, review R6/A5): nothing is owed. The PR counts it as
     * overdue from the next day on, forever (nothing cancels it), in the scope, the filter and the red sidebar count.
     */
    public function test_adv1_an_unpaid_add_funds_invoice_is_not_overdue(): void
    {
        $deposit = Invoice::factory()->create(['client_id' => $this->clientId, 'status' => Invoice::STATUS_UNPAID, 'due_at' => '2026-10-01']);
        $deposit->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => 50, 'taxed' => false]);
        $debt = $this->late();

        $this->assertSame([$debt->id], Invoice::query()->overdue()->pluck('id')->all(), 'an add-funds offer is not overdue');
        $this->assertSame(0, $deposit->daysOverdue(), 'nor shown «N days late»');
        $this->assertSame('1', collect(AdminSidebar::queues())->firstWhere('label', 'Overdue invoices')['badge']);
    }

    /**
     * ADV-2: a payment on its way (a SEPA debit still processing) is not chased: the reminders skip it, the cron does
     * not suspend for it («no payment on its way»). The overdue queue asks staff to chase it.
     */
    public function test_adv2_an_invoice_with_a_payment_processing_is_not_overdue(): void
    {
        $sepa = $this->late();
        (new RecordPaymentService)->handle($sepa, null, 20.00, transactionId: 'sepa-adv-286', status: InvoiceTransactionStatus::Processing);
        $this->assertSame(Invoice::STATUS_UNPAID, $sepa->fresh()->status);

        $this->assertSame([], Invoice::query()->overdue()->pluck('id')->all(), 'its payment is on its way');
    }

    /** ADV-3: the sidebar link must open the list filtered (its URL, not only a filterTable() call in a test). */
    public function test_adv3_the_sidebar_url_opens_the_list_filtered(): void
    {
        $late = $this->late();
        $inTime = $this->late('2026-10-25');
        $url = collect(AdminSidebar::queues())->firstWhere('label', 'Overdue invoices')['url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        Livewire::withQueryParams($query)->test(ListInvoices::class)
            ->assertCanSeeTableRecords([$late])->assertCanNotSeeTableRecords([$inTime]);
    }

    /**
     * ADV-4: lang/el.json gains «Overdue» → «Ληξιπρόθεσμα», but the filter's label is a bare ->label('Overdue') (no
     * __() nor translateLabel()), so a Greek admin reads «Overdue». The «N days late» line and the queue are translated.
     */
    public function test_adv4_the_overdue_filter_label_is_translated(): void
    {
        app()->setLocale('el');

        $filter = Livewire::test(ListInvoices::class)->instance()->getTable()->getFilter('overdue');

        $this->assertSame('Ληξιπρόθεσμα', $filter->getLabel());
    }
}
