<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Livewire\Invoices\Index;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\PaymentGroup;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The invoices list's Mass Pay bar (docs/pay-flow-ux.md, PR 5): the checkbox in each card, why one cannot join, a bar
 * that follows the ticks (how many, their total, what still stops them), «select all» of those that can join, and what
 * happens to each unpaid invoice now. MassPay::open checks everything again.
 */
class InvoicesMassPayBarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.mass_pay_enabled' => true]);
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        session([CurrentClient::SESSION_KEY => $this->client->id]);
    }

    private function invoice(string $amount = '10.00', string $currency = 'USD', string $status = Invoice::STATUS_UNPAID, ?Client $client = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => ($client ?? $this->client)->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => $currency, 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting ' . $invoice->id, 'price' => $amount, 'quantity' => 1]);
        if ($status !== Invoice::STATUS_UNPAID) {
            $invoice->forceFill(['status' => $status])->saveQuietly();
        }

        return $invoice->fresh();
    }

    private function processing(Invoice $invoice): void
    {
        DB::table('invoice_transactions')->insert(['invoice_id' => $invoice->id, 'gateway_id' => null, 'amount' => 5,
            'status' => InvoiceTransactionStatus::Processing->value, 'transaction_id' => 'sepa_' . $invoice->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_one_that_cannot_join_says_why_and_select_all_takes_only_those_that_can(): void
    {
        $a = $this->invoice();
        $b = $this->invoice('5.00');
        $held = $this->invoice();
        $this->processing($held);

        $page = $this->livewireAs($this->user)->test(Index::class);
        $html = $page->html();
        $this->assertMatchesRegularExpression('/id="pay-together-' . $held->id . '"[^>]*\sdisabled\s/', $html, 'its checkbox is off');
        $this->assertDoesNotMatchRegularExpression('/id="pay-together-' . $a->id . '"[^>]*\sdisabled\s/', $html);
        $page->assertSee(__('invoices.mass_pay_bar.cannot_join', ['why' => __('A payment for invoice :invoice is still being processed.', ['invoice' => '#' . ($held->number ?: $held->id)])]));

        $page->call('selectAll');
        $this->assertEqualsCanonicalizing([(string) $a->id, (string) $b->id], $page->get('selected'));
    }

    public function test_the_bar_follows_the_ticks(): void
    {
        $a = $this->invoice('10.00');
        $b = $this->invoice('5.50');

        $page = $this->livewireAs($this->user)->test(Index::class);
        $page->assertDontSee(__('invoices.mass_pay_bar.clear'), 'no bar with nothing ticked');

        $page->set('selected', [(string) $a->id])->assertSee(__('invoices.mass_pay_bar.pick_more'));
        $this->assertNotNull($page->viewData('selection')['problem'], 'one: not yet');

        $page->set('selected', [(string) $a->id, (string) $b->id])
            ->assertSee(trans_choice('invoices.mass_pay_bar.selected', 2, ['count' => 2]))
            ->assertSee(__('invoices.mass_pay_bar.pay', ['amount' => '15.50 USD']))->assertSee(__('invoices.mass_pay_bar.ready'));
        $page->call('payTogether');
        $group = PaymentGroup::query()->latest('id')->firstOrFail();
        $page->assertRedirect(route('invoices.pay-together', $group));

        $page->call('clearSelection')->assertSet('selected', []);
    }

    public function test_two_currencies_are_not_paid_together(): void
    {
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        $usd = $this->invoice();
        $eur = $this->invoice('10.00', 'EUR');

        $selection = $this->livewireAs($this->user)->test(Index::class)->set('selected', [(string) $usd->id, (string) $eur->id])
            ->assertSee(__('invoices.mass_pay_bar.one_currency'))->viewData('selection');
        $this->assertNull($selection['total']);
        $this->assertNotNull($selection['problem']);
    }

    public function test_a_crafted_tick_counts_for_nothing(): void
    {
        $mine = $this->invoice();
        $this->invoice();
        $theirs = $this->invoice('10.00', 'USD', Invoice::STATUS_UNPAID, User::factory()->create()->client());
        $paid = $this->invoice('10.00', 'USD', Invoice::STATUS_PAID);

        $selection = $this->livewireAs($this->user)->test(Index::class)
            ->set('selected', [(string) $mine->id, (string) $theirs->id, (string) $paid->id, 'abc', '1 OR 1=1'])->viewData('selection');
        $this->assertSame(1, $selection['count'], 'only the account\'s own unpaid invoice');
        $this->assertSame(__('invoices.mass_pay_bar.pick_more'), $selection['problem']);
    }

    public function test_each_unpaid_invoice_says_what_happens_to_it_now(): void
    {
        $this->invoice();
        $held = $this->invoice();
        $this->processing($held);

        $this->livewireAs($this->user)->test(Index::class)->assertSee(__('invoices.payment_status.processing.title'));
    }

    public function test_no_bar_where_mass_pay_does_not_apply(): void
    {
        $a = $this->invoice();
        $b = $this->invoice();
        config(['settings.mass_pay_enabled' => false]);

        $this->livewireAs($this->user)->test(Index::class)->set('selected', [(string) $a->id, (string) $b->id])
            ->assertDontSee(__('invoices.mass_pay_bar.clear'))->assertDontSeeHtml('wire:model.live="selected"');
    }
}
