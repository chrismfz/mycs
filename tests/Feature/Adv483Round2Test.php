<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\Pages\ViewInvoice;
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
 * Adversarial review of #483, round 2 (the round-1 fixes: the Ekdosi refusal on Save, cancelled invoices with payments
 * locked, the memo key, the PAYMENTS_CHANGED listener). R2-n tests fail on the branch for a defect; PIN tests hold
 * guarantees verified on it.
 */
class Adv483Round2Test extends TestCase
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

    /**
     * R2-1: the round-1 lock of a cancelled invoice holding money is not matched by the Save. The page opens on a
     * cancelled invoice with nothing on it (lines open); staff reprice; a payment lands meanwhile and stays processing
     * (a late checkout). On Save the form now locks the price, so it is not dehydrated, and refuseWithPayments only
     * refuses an open one: the new price is dropped without a word and «Saved» is shown (ADV-1's class, for this lock).
     */
    public function test_r2_1_a_repricing_of_a_cancelled_invoice_locked_meanwhile_is_not_dropped_silently(): void
    {
        $invoice = $this->invoice();
        $invoice->forceFill(['status' => Invoice::STATUS_CANCELLED])->saveQuietly();

        $page = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $line = array_key_first($page->get('data.items'));
        $page->assertFormFieldIsEnabled("items.{$line}.price")->set("data.items.{$line}.price", '120.00');

        ExtensionHelper::addProcessingPayment($invoice->fresh(), 'BankTransfer', '100.00', transactionId: 'TX-LATE');
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertNotNull(InvoiceResource::linesKeepAmounts($invoice->fresh()), 'the form locks it now');

        $page->call('save');

        // Refused, as the reopening Save and ADV-1's document are: the price kept and staff told why.
        $this->assertSame('100.00', (string) $invoice->items()->first()->price);
        $page->assertNotified(__('Invoice not saved'));
    }

    /**
     * R2-2: told that a payment changed below, the page writes the stored status over the one staff chose and did not
     * save, even when the stored one did not change (a part payment). The choice is lost and statusWhenLoaded moves, so
     * the Save's stale-status guard (mutateFormDataBeforeSave) has nothing to compare with.
     */
    public function test_r2_2_a_status_chosen_and_not_saved_survives_a_payment_that_leaves_the_status(): void
    {
        $invoice = $this->invoice();
        $page = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->set('data.status', Invoice::STATUS_CANCELLED);

        ExtensionHelper::addPayment($invoice, 'BankTransfer', '40.00', transactionId: 'TX-PART');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        $page->dispatch(TransactionsRelationManager::PAYMENTS_CHANGED)
            ->assertSet('data.status', Invoice::STATUS_CANCELLED);
    }

    /** PIN: a description-only Save after Ekdosi issued its document meanwhile still saves (no amount moved). */
    public function test_pin_a_description_edit_after_a_document_landed_saves(): void
    {
        $invoice = $this->invoice();
        $page = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $line = array_key_first($page->get('data.items'));
        $page->set("data.items.{$line}.description", 'Hosting, renamed');

        EkdosiDocument::query()->create(['invoice_id' => $invoice->id, 'mark' => '400001234567892', 'code' => 'ΤΠΥ3', 'state' => EkdosiDocument::ACTIVE]);

        $page->call('save')->assertHasNoErrors()->assertNotNotified(__('Invoice not saved'));
        $this->assertSame('Hosting, renamed', $invoice->items()->first()->description);
        $this->assertSame('100.00', (string) $invoice->items()->first()->price);
    }

    /** PIN: payments and a document both: refused with the payments message, nothing written. */
    public function test_pin_payments_and_a_document_refuse_with_the_payments_message(): void
    {
        $invoice = $this->invoice();
        $page = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $line = array_key_first($page->get('data.items'));
        $page->set("data.items.{$line}.price", '120.00');

        ExtensionHelper::addPayment($invoice, 'BankTransfer', '40.00', transactionId: 'TX-BOTH');
        EkdosiDocument::query()->create(['invoice_id' => $invoice->id, 'mark' => '400001234567893', 'code' => 'ΤΠΥ4', 'state' => EkdosiDocument::ACTIVE]);

        $page->call('save')->assertNotified(__('Invoice not saved'));
        $this->assertSame('100.00', (string) $invoice->items()->first()->price);
    }

    /** PIN: a cancelled invoice whose payment was refunded in full unlocks; reopening and repricing in one Save works. */
    public function test_pin_a_cancelled_invoice_refunded_in_full_reopens_and_reprices(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice, 'BankTransfer', '40.00', transactionId: 'TX-R');
        $invoice->refresh()->forceFill(['status' => Invoice::STATUS_CANCELLED])->saveQuietly();
        $this->assertNotNull(InvoiceResource::linesKeepAmounts($invoice->fresh()));

        $page = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $line = array_key_first($page->get('data.items'));
        $page->assertFormFieldIsDisabled("items.{$line}.price");

        $invoice->transactions()->first()->forceFill(['refunded_amount' => '40.00'])->saveQuietly();
        $page->dispatch(TransactionsRelationManager::PAYMENTS_CHANGED)
            ->assertFormFieldIsEnabled("items.{$line}.price")
            ->set('data.status', Invoice::STATUS_UNPAID)->set("data.items.{$line}.price", '120.00')
            ->call('save')->assertNotNotified(__('Invoice not saved'));

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame('120.00', (string) $invoice->items()->first()->price);
    }

    /** PIN: the table on the view page (no form, no listener) deletes a payment and tells without error. */
    public function test_pin_the_table_on_the_view_page_tells_without_error(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice, 'BankTransfer', '40.00', transactionId: 'TX-V');

        $this->livewireAs($this->staff())
            ->test(TransactionsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => ViewInvoice::class])
            ->callTableAction('delete', $invoice->transactions()->sole())
            ->assertHasNoTableActionErrors()
            ->assertDispatched(TransactionsRelationManager::PAYMENTS_CHANGED)
            ->dispatch(TransactionsRelationManager::PAYMENTS_CHANGED)
            ->assertDontSee('TX-V');
        $this->livewireAs($this->staff())->test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->dispatch(TransactionsRelationManager::PAYMENTS_CHANGED)->assertHasNoErrors();
    }
}
