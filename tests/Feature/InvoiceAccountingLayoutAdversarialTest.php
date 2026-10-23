<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Staff;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of #414 (the invoice page as an accountant reads it, «Split to a new invoice» from the lines
 * ticked). test_advN_* show a defect; test_guard_* pin a guard the new action relies on.
 */
class InvoiceAccountingLayoutAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    private Invoice $invoice;

    private InvoiceItem $hosting;

    private InvoiceItem $domain;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->admin()->create();
        $client = User::factory()->create()->clients()->firstOrFail();
        $this->invoice = Invoice::query()->create(['client_id' => $client->id, 'currency_code' => 'USD', 'due_at' => now()->addDays(3), 'status' => Invoice::STATUS_UNPAID]);
        $this->hosting = $this->invoice->items()->create(['price' => 30, 'quantity' => 1, 'description' => 'Hosting plan']);
        $this->domain = $this->invoice->items()->create(['price' => 12, 'quantity' => 1, 'description' => 'Domain renewal']);
    }

    private function page()
    {
        return $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $this->invoice->id]);
    }

    private function splitTicked($page)
    {
        return $page->callAction(TestAction::make('splitSelected')->schemaComponent('lineActions', 'form'));
    }

    private function splits(): int
    {
        return Invoice::query()->where('split_from_id', $this->invoice->id)->count();
    }

    /**
     * ADV-1: «Split» refuses lines changed and not saved, because the page reloads after the split and would drop what
     * was typed. It does not look at the invoice's own fields: a due date (or status, currency) typed and not saved is
     * dropped silently by the reload, and the new invoice is made with the old one. Either the split is refused, as for
     * the lines, or what was typed survives.
     */
    public function test_adv1_a_due_date_typed_and_not_saved_is_dropped_by_the_split(): void
    {
        $typed = now()->addDays(30)->toDateString();
        $this->splitTicked($this->page()->set('data.due_at', $typed)
            ->set("data.items.record-{$this->domain->id}.selected", true));

        $this->assertTrue($this->splits() === 0 || $this->invoice->refresh()->due_at->toDateString() === $typed,
            'split with a due date typed and not saved: the typed date is lost and the new invoice takes the old one');
    }

    /** Guard: a tampered state that ticks a line of another customer's invoice never moves it. */
    public function test_guard_a_line_of_another_invoice_ticked_by_a_tampered_state_never_moves(): void
    {
        $other = Invoice::query()->create(['client_id' => User::factory()->create()->clients()->firstOrFail()->id, 'currency_code' => 'USD', 'due_at' => now()->addDays(3), 'status' => Invoice::STATUS_UNPAID]);
        $foreign = $other->items()->create(['price' => 50, 'quantity' => 1, 'description' => 'Foreign']);
        $other->items()->create(['price' => 50, 'quantity' => 1, 'description' => 'Foreign 2']);
        $page = $this->page();
        $items = $page->get('data.items');
        $items["record-{$foreign->id}"] = ['description' => 'Foreign', 'quantity' => 1, 'price' => '50.00', 'taxed' => true, 'selected' => true];
        $this->splitTicked($page->set('data.items', $items));

        $this->assertSame((int) $other->id, (int) $foreign->refresh()->invoice_id);
        $this->assertSame(0, $this->splits());
    }

    /** Guard: the ticks never split an invoice with a payment (the service's refusal, reached from the new action). */
    public function test_guard_an_invoice_with_a_payment_is_not_split_from_the_ticks(): void
    {
        $this->invoice->transactions()->create(['amount' => 5, 'status' => InvoiceTransactionStatus::Succeeded, 'transaction_id' => 'adv-414-pay', 'fee' => 0]);
        $this->splitTicked($this->page()->set("data.items.record-{$this->domain->id}.selected", true));

        $this->assertSame(0, $this->splits());
        $this->assertSame((int) $this->invoice->id, (int) $this->domain->refresh()->invoice_id);
    }

    /** Guard: with a payment, the table still saves a description and still refuses a new price or the «taxed» tick. */
    public function test_guard_the_table_keeps_the_amounts_of_an_invoice_with_a_payment(): void
    {
        $this->invoice->transactions()->create(['amount' => 5, 'status' => InvoiceTransactionStatus::Succeeded, 'transaction_id' => 'adv-414-pay2', 'fee' => 0]);
        $this->page()->set("data.items.record-{$this->hosting->id}.description", 'Hosting plan, renamed')->call('save')->assertHasNoFormErrors();
        $this->assertSame('Hosting plan, renamed', $this->hosting->refresh()->description);

        $this->page()->set("data.items.record-{$this->hosting->id}.price", '1')->call('save');
        $this->page()->set("data.items.record-{$this->hosting->id}.taxed", false)->call('save');
        $this->hosting->refresh();
        $this->assertSame(['30.00', true], [(string) $this->hosting->price, (bool) $this->hosting->taxed]);
    }
}
