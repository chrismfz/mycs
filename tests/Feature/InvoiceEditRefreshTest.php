<?php

namespace Tests\Feature;

use App\Admin\Actions\SendToEkdosi;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Helpers\ExtensionHelper;
use App\Models\EkdosiDocument;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invoice's edit page after the money changes (live test of 2026-10-27): a payment recorded from its header shows in
 * the tables below at once; a save that took the Ekdosi request off offers «Send to Ekdosi» again; the lines of an
 * invoice that keeps its amounts are shown locked, with why, rather than refused on Save.
 */
class InvoiceEditRefreshTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD', 'settings.ekdosi_enabled' => true,
            'settings.ekdosi_secret' => 'a-test-secret-of-at-least-thirty-two-chars', 'settings.ekdosi_url' => 'https://ekdosi.example.test/ekdosi']);
        Gateway::query()->create(['name' => 'Bank transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $this->user = User::factory()->create();
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function invoice(): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);

        return $invoice->fresh();
    }

    public function test_mark_paid_tells_the_tables_below(): void
    {
        $invoice = $this->invoice();

        $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('markPaid', data: ['amount' => '100.00', 'paid_at' => now()->toDateString(), 'gateway_id' => null, 'transaction_id' => 'TX-1'])
            ->assertHasNoActionErrors()
            ->assertDispatched(TransactionsRelationManager::PAYMENTS_CHANGED);
    }

    public function test_the_transactions_table_shows_a_payment_once_told(): void
    {
        $invoice = $this->invoice();
        $table = $this->livewireAs($this->staff())
            ->test(TransactionsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => EditInvoice::class])
            ->assertDontSee('TX-LATER');

        ExtensionHelper::addPayment($invoice, 'BankTransfer', '100.00', transactionId: 'TX-LATER');

        $table->dispatch(TransactionsRelationManager::PAYMENTS_CHANGED)->assertSee('TX-LATER');
    }

    public function test_a_save_that_takes_the_request_off_offers_send_again(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($staff = $this->staff(), 'staff');
        SendToEkdosi::request([$invoice], true);

        $page = $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionVisible('withdrawFromEkdosi');
        $line = array_key_first($page->get('data.items'));
        $page->set("data.items.{$line}.price", '120.00')->call('save')->assertHasNoErrors();

        $this->assertNull($invoice->fresh()->ekdosi_requested_at, 'lines changed: the request is taken off');
        $page->assertActionHidden('withdrawFromEkdosi')->assertActionVisible('sendToEkdosi');
    }

    public function test_a_paid_invoices_lines_are_shown_locked_with_why(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice, 'BankTransfer', '100.00', transactionId: 'TX-2');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $page = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $line = array_key_first($page->get('data.items'));
        $page->assertFormFieldIsDisabled("items.{$line}.price")
            ->assertFormFieldIsDisabled("items.{$line}.quantity")
            ->assertFormFieldIsEnabled("items.{$line}.description")
            ->assertSee(__('A paid invoice keeps its lines: set it back to unpaid first.'));
    }

    public function test_the_lines_lock_on_payments_and_on_a_document_and_not_before(): void
    {
        $open = $this->invoice();
        $this->assertNull(InvoiceResource::linesKeepAmounts($open));

        $part = $this->invoice();
        ExtensionHelper::addPayment($part, 'BankTransfer', '40.00', transactionId: 'TX-3');
        $this->assertSame(Invoice::STATUS_UNPAID, $part->fresh()->status);
        $this->assertNotNull(InvoiceResource::linesKeepAmounts($part->fresh()), 'a part paid: the Save refuses new amounts');

        $documented = $this->invoice();
        EkdosiDocument::query()->create(['invoice_id' => $documented->id, 'mark' => '400001234567890', 'code' => 'ΤΠΥ1', 'state' => EkdosiDocument::ACTIVE]);
        $this->assertNotNull(InvoiceResource::linesKeepAmounts($documented->fresh()));

        $page = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $open->getKey()]);
        $page->assertFormFieldIsEnabled('items.' . array_key_first($page->get('data.items')) . '.price');
    }

    /** Review of #483: the lock follows the invoice saved in the same response (the memo keyed on its state). */
    public function test_reopening_by_save_unlocks_the_lines_at_once(): void
    {
        $invoice = $this->invoice();
        $invoice->items()->first()->update(['price' => '0.00']);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_PAID]);

        $page = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $line = array_key_first($page->get('data.items'));
        $page->assertFormFieldIsDisabled("items.{$line}.price")
            ->set('data.status', Invoice::STATUS_UNPAID)->call('save')->assertHasNoErrors();

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $page->assertFormFieldIsEnabled("items.{$line}.price")
            ->assertDontSee(__('A paid invoice keeps its lines: set it back to unpaid first.'));
    }

    /** Review of #483: a payment deleted in the table tells the page and the other tables. */
    public function test_the_transactions_table_tells_the_page_when_a_payment_goes(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice, 'BankTransfer', '100.00', transactionId: 'TX-GONE');
        $payment = $invoice->transactions()->sole();

        $this->livewireAs($this->staff())
            ->test(TransactionsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class])
            ->callTableAction('delete', $payment)
            ->assertDispatched(TransactionsRelationManager::PAYMENTS_CHANGED);
    }

    /** Review of #483: told, the page reads the invoice again: its status, and its lines locked by a payment made below. */
    public function test_the_page_reads_the_invoice_again_when_told(): void
    {
        $invoice = $this->invoice();
        $page = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $line = array_key_first($page->get('data.items'));
        $page->assertFormFieldIsEnabled("items.{$line}.price");

        ExtensionHelper::addPayment($invoice, 'BankTransfer', '100.00', transactionId: 'TX-BELOW');

        $page->dispatch(TransactionsRelationManager::PAYMENTS_CHANGED)
            ->assertSet('data.status', Invoice::STATUS_PAID)
            ->assertFormFieldIsDisabled("items.{$line}.price");
    }
}
