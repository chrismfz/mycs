<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Staff;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invoice's page as an accountant reads it (owner 2026-10-24): its lines as a table (description, quantity, price
 * before tax, taxed, amount), its totals and balance as saved, and «Split to a new invoice» from the lines ticked.
 */
class InvoiceAccountingLayoutTest extends TestCase
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

    public function test_the_lines_read_as_a_table_with_its_totals_and_balance(): void
    {
        $total = (string) $this->invoice->refresh()->formattedTotal;

        $this->actingAs($this->staff, 'staff')->get(InvoiceResource::getUrl('edit', ['record' => $this->invoice]))->assertOk()
            ->assertSeeInOrder([__('Description'), __('Qty'), __('Unit price'), __('VAT'), __('Amount')])
            ->assertSeeInOrder([__('Subtotal'), __('Total due')])
            ->assertSee(__('Balance due'))->assertSee(__('Split to a new invoice'))->assertSee(__('Add line'));
        $this->assertSame(42.0, (float) $this->invoice->subtotal, 'the premise: the sums the page shows');
        $this->actingAs($this->staff, 'staff')->get(InvoiceResource::getUrl('edit', ['record' => $this->invoice]))->assertSee($total);
    }

    public function test_the_lines_ticked_are_split_off_to_a_new_invoice(): void
    {
        $this->splitTicked($this->page()->set("data.items.record-{$this->domain->id}.selected", true))->assertHasNoActionErrors();

        $new = Invoice::query()->where('split_from_id', $this->invoice->id)->sole();
        $this->assertSame($new->id, (int) $this->domain->refresh()->invoice_id);
        $this->assertSame($this->invoice->id, (int) $this->hosting->refresh()->invoice_id);
    }

    public function test_nothing_ticked_splits_nothing(): void
    {
        $this->splitTicked($this->page());

        $this->assertSame(0, Invoice::query()->where('split_from_id', $this->invoice->id)->count());
    }

    public function test_lines_changed_and_not_saved_are_not_split(): void
    {
        $this->splitTicked($this->page()->set("data.items.record-{$this->domain->id}.selected", true)
            ->set("data.items.record-{$this->hosting->id}.price", '99.00'));

        $this->assertSame(0, Invoice::query()->where('split_from_id', $this->invoice->id)->count());
        $this->assertSame($this->invoice->id, (int) $this->domain->refresh()->invoice_id);
    }

    public function test_a_line_not_saved_yet_is_not_split(): void
    {
        $page = $this->page();
        $items = $page->get('data.items');
        $items['new-line'] = ['description' => 'Extra', 'quantity' => 1, 'price' => '5.00', 'taxed' => true, 'selected' => true];
        $this->splitTicked($page->set('data.items', $items));

        $this->assertSame(0, Invoice::query()->where('split_from_id', $this->invoice->id)->count());
    }

    public function test_saving_keeps_the_lines_and_never_stores_the_tick(): void
    {
        $this->page()->set("data.items.record-{$this->domain->id}.selected", true)
            ->set("data.items.record-{$this->domain->id}.price", '15.00')
            ->set("data.items.record-{$this->domain->id}.taxed", false)
            ->call('save')->assertHasNoFormErrors();

        $this->domain->refresh();
        $this->assertSame(['15.00', false, '15.00'], [(string) $this->domain->price, $this->domain->taxed, (string) $this->domain->line_total]);
        $this->assertSame(2, $this->invoice->items()->count());
    }
}
