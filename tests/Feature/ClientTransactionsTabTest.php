<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Pages\ShowTransactions;
use App\Admin\Resources\ClientResource\Widgets\ClientTransactionTotals;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Owner 2026-11-16: the client's payments in a tab between Invoices and Credits, as WHMCS's «Transactions». */
class ClientTransactionsTabTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private InvoiceTransaction $card;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        $this->client = User::factory()->create()->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'Cards or IRIS', 'type' => 'gateway']);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $this->card = InvoiceTransaction::query()->create(['invoice_id' => $invoice->id, 'gateway_id' => $gateway->id, 'amount' => '81.84', 'fee' => '1.50',
            'refunded_amount' => '10.00', 'transaction_id' => '320246429437', 'status' => InvoiceTransactionStatus::Succeeded]);
        // Paid from credit (an allocation of a payment counted elsewhere), and a failed try: neither is money in.
        InvoiceTransaction::query()->create(['invoice_id' => $invoice->id, 'amount' => '20.00', 'is_credit_transaction' => true, 'status' => InvoiceTransactionStatus::Succeeded]);
        InvoiceTransaction::query()->create(['invoice_id' => $invoice->id, 'gateway_id' => $gateway->id, 'amount' => '99.00', 'status' => InvoiceTransactionStatus::Failed]);
        // Another client's.
        $other = Invoice::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        InvoiceTransaction::query()->create(['invoice_id' => $other->id, 'amount' => '500.00', 'transaction_id' => 'OTHER-1', 'status' => InvoiceTransactionStatus::Succeeded]);
    }

    private function staff(array $permissions): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . count($permissions), 'permissions' => $permissions])->id]);
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    public function test_the_tab_lists_its_payments_with_method_invoice_and_id_and_not_another_clients(): void
    {
        $this->staff(['*']);

        $this->get(ClientResource::getUrl('transactions', ['record' => $this->client]))->assertOk()
            ->assertSee('320246429437')->assertSee('Cards or IRIS')->assertSee('#' . $this->card->invoice_id)
            ->assertSee(__('Account credit'))->assertDontSee('OTHER-1');
        Livewire::test(ShowTransactions::class, ['record' => $this->client->getRouteKey()])
            ->assertCanSeeTableRecords([$this->card])->assertCountTableRecords(3);
    }

    public function test_the_totals_count_money_in_once_without_credit_or_failed_ones(): void
    {
        $this->staff(['*']);

        $stats = collect((fn () => $this->getStats())->call(Livewire::test(ClientTransactionTotals::class, ['record' => $this->client])->instance()))
            ->mapWithKeys(fn (Stat $stat) => [(string) $stat->getLabel() => (string) $stat->getValue()]);

        $this->assertSame(['Received', 'Fees', 'Refunded', 'Balance'], $stats->keys()->all());
        $this->assertStringContainsString('81,84', $stats['Received']);
        $this->assertStringContainsString('1,50', $stats['Fees']);
        $this->assertStringContainsString('10,00', $stats['Refunded']);
        $this->assertStringContainsString('70,34', $stats['Balance']);
    }

    public function test_staff_who_may_not_see_transactions_get_no_tab(): void
    {
        $this->staff(['admin.clients.viewAny', 'admin.clients.view', 'admin.invoices.viewAny']);

        $this->assertFalse(ShowTransactions::canAccess(['record' => $this->client]));
        $this->get(ClientResource::getUrl('transactions', ['record' => $this->client]))->assertForbidden();
    }
}
