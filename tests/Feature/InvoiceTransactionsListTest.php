<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceTransactions\Pages\ListInvoiceTransactions;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Transactions list (owner 2026-11-13): its invoice an id («#7283»), not an amount («7,283»). */
class InvoiceTransactionsListTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_invoice_id_is_shown_as_an_id(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $invoice = Invoice::factory()->create(['id' => 7283, 'client_id' => User::factory()->create()->client()->id, 'currency_code' => 'EUR']);
        $payment = InvoiceTransaction::query()->create(['invoice_id' => $invoice->id, 'amount' => '1.80', 'fee' => 0,
            'status' => InvoiceTransactionStatus::Succeeded]);
        $staff = Staff::factory()->withPermissions(['admin.invoice_transactions.viewAny', 'admin.invoices.viewAny'])->create();

        $this->livewireAs($staff)->test(ListInvoiceTransactions::class)
            ->assertCanSeeTableRecords([$payment])
            ->assertSee('#7283')->assertDontSee('7,283')
            ->searchTable('7283')->assertCanSeeTableRecords([$payment]);
    }
}
