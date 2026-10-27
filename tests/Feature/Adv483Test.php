<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
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
 * Adversarial review of #483 (locked line amounts on the invoice edit page). ADV-n tests fail on the branch for a defect;
 * PIN tests hold guarantees verified on it.
 */
class Adv483Test extends TestCase
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
     * ADV-1: the page was opened while the lines could change; Ekdosi issues its document meanwhile. Staff reprice and
     * Save. Before #483 the observer refused it with the Ekdosi message; now the price field is disabled in the Save's
     * request, so it is not dehydrated: the new price is dropped silently and «Saved» is shown.
     */
    public function test_adv1_a_repricing_after_a_document_landed_meanwhile_is_refused_not_silently_dropped(): void
    {
        $invoice = $this->invoice();
        $page = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $line = array_key_first($page->get('data.items'));
        $page->set("data.items.{$line}.price", '120.00');

        EkdosiDocument::query()->create(['invoice_id' => $invoice->id, 'mark' => '400001234567891', 'code' => 'ΤΠΥ2', 'state' => EkdosiDocument::ACTIVE]);

        $page->call('save');

        $this->assertSame('100.00', (string) $invoice->items()->first()->price, 'the line keeps its amount');
        $page->assertNotified(__('Invoice not saved'));
    }

    /**
     * PIN: a part payment recorded from the header (Mark Paid) locks the lines in the same request (linesKeepAmounts is
     * memoised by id|status|updated_at, which a part payment could leave as they were: it does not go stale).
     */
    public function test_pin_a_part_payment_from_the_header_locks_the_lines_at_once(): void
    {
        $invoice = $this->invoice();
        $page = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $line = array_key_first($page->get('data.items'));
        $page->assertFormFieldIsEnabled("items.{$line}.price");

        $page->callAction('markPaid', data: ['amount' => '40.00', 'paid_at' => now()->toDateString(), 'gateway_id' => null, 'transaction_id' => 'TX-P'])
            ->assertHasNoActionErrors();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        $page->assertFormFieldIsDisabled("items.{$line}.price")
            ->assertSee(__('This invoice has payments: its lines keep their amounts. Refund the payment first (back, or to account credit), or give a discount with a note.'));
    }

    /**
     * ADV-3: a cancelled invoice with a payment shows its lines open (not in OPEN_STATUSES), but reopening it in the same
     * form (cancelled → unpaid) with a new price is refused by the Save (refuseWithPayments with the status after). The
     * form offers a change the Save refuses, which #483 set out to stop.
     */
    public function test_adv3_a_cancelled_invoice_with_payments_shows_its_lines_as_the_reopening_save_treats_them(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice, 'BankTransfer', '40.00', transactionId: 'TX-C');
        $invoice->refresh()->forceFill(['status' => Invoice::STATUS_CANCELLED])->saveQuietly();

        $page = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $line = array_key_first($page->get('data.items'));
        $page->set('data.status', Invoice::STATUS_UNPAID)->set("data.items.{$line}.price", '120.00')->call('save');
        $refused = $invoice->items()->first()->price === '100.00';

        $this->assertFalse($refused && InvoiceResource::linesKeepAmounts($invoice->fresh()) === null,
            'the Save refused the repricing, yet the form showed the lines open');
    }

    /** PIN: a paid invoice's description changes on Save; its amounts and lines stay exactly as they were. */
    public function test_pin_a_description_edit_on_a_paid_invoice_saves_and_keeps_the_amounts(): void
    {
        $invoice = $this->invoice();
        $invoice->items()->first()->update(['taxed' => false]);
        ExtensionHelper::addPayment($invoice, 'BankTransfer', (string) $invoice->fresh()->total, transactionId: 'TX-D');
        $before = $invoice->items()->first()->only(['price', 'quantity', 'taxed', 'tax_amount', 'line_total']);
        $total = (string) $invoice->fresh()->total;

        $page = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $line = array_key_first($page->get('data.items'));
        $page->set("data.items.{$line}.description", 'Hosting, renamed')->call('save')->assertHasNoErrors()
            ->assertNotNotified(__('Invoice not saved'));

        $item = $invoice->items()->first();
        $this->assertSame('Hosting, renamed', $item->description);
        $this->assertSame($before, $item->only(['price', 'quantity', 'taxed', 'tax_amount', 'line_total']));
        $this->assertSame($total, (string) $invoice->fresh()->total);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** PIN: a crafted request on a paid invoice (new price, a line added, one removed) moves no amount. */
    public function test_pin_crafted_line_changes_on_a_paid_invoice_move_no_money(): void
    {
        $invoice = $this->invoice();
        $invoice->items()->create(['description' => 'Extra', 'quantity' => 1, 'price' => '10.00']);
        ExtensionHelper::addPayment($invoice, 'BankTransfer', (string) $invoice->fresh()->total, transactionId: 'TX-E');
        $total = (string) $invoice->fresh()->total;

        $page = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $items = $page->get('data.items');
        [$first, $second] = array_keys($items);
        $items[$first]['price'] = '1.00';
        unset($items[$second]);
        $items['new-line'] = ['description' => 'Free', 'quantity' => 1, 'price' => '0.00', 'taxed' => true];
        $page->set('data.items', $items)->call('save');

        $this->assertSame($total, (string) $invoice->fresh()->total);
        $this->assertSame(2, $invoice->items()->count());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** PIN: an open invoice with a part payment still refuses a crafted repricing on Save. */
    public function test_pin_a_crafted_repricing_with_a_part_payment_is_refused(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice, 'BankTransfer', '40.00', transactionId: 'TX-F');

        $page = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $line = array_key_first($page->get('data.items'));
        $page->set("data.items.{$line}.price", '10.00')->call('save')->assertNotified(__('Invoice not saved'));

        $this->assertSame('100.00', (string) $invoice->items()->first()->price);
    }

    /** PIN: once its payment is refunded in full, an open invoice's lines unlock and a repricing saves. */
    public function test_pin_a_refunded_payment_unlocks_the_lines(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice, 'BankTransfer', '40.00', transactionId: 'TX-G');
        $invoice->transactions()->first()->forceFill(['refunded_amount' => '40.00'])->saveQuietly();
        $this->assertNull(InvoiceResource::linesKeepAmounts($invoice->fresh()));

        $page = $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $line = array_key_first($page->get('data.items'));
        $page->assertFormFieldIsEnabled("items.{$line}.price")
            ->set("data.items.{$line}.price", '120.00')->call('save')->assertNotNotified(__('Invoice not saved'));

        $this->assertSame('120.00', (string) $invoice->items()->first()->price);
    }
}
