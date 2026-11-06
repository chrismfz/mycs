<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Livewire\Invoices\Index;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\PaymentGroup;
use App\Models\User;
use App\Services\Invoice\MassPay;
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
        $page->assertSee(__('A payment for invoice :invoice is still being processed.', ['invoice' => '#' . ($held->number ?: $held->id)]));

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
            ->assertSee(__('invoices.mass_pay_bar.pay', ['amount' => (string) $a->formattedTotal->format(15.50)]))->assertSee(__('invoices.mass_pay_bar.ready'));
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

        $page = $this->livewireAs($this->user)->test(Index::class)
            ->set('selected', [(string) $mine->id, (string) $theirs->id, (string) $paid->id, 'abc', '1 OR 1=1', ['nested'], -3]);
        $selection = $page->viewData('selection');
        $this->assertSame(1, $selection['count'], 'only the account\'s own unpaid invoice');
        $this->assertSame(__('invoices.mass_pay_bar.pick_more'), $selection['problem']);
        // And the button agrees: nothing is opened.
        $page->call('payTogether')->assertNoRedirect();
        $this->assertSame(0, PaymentGroup::query()->count());
    }

    /** Review of #606: a ticked invoice paid meanwhile drops out; the others are paid together as the bar says. */
    public function test_a_ticked_invoice_paid_meanwhile_drops_out_of_what_is_paid(): void
    {
        $a = $this->invoice();
        $b = $this->invoice();
        $c = $this->invoice();

        $page = $this->livewireAs($this->user)->test(Index::class)->set('selected', [(string) $a->id, (string) $b->id, (string) $c->id]);
        $c->forceFill(['status' => Invoice::STATUS_PAID])->saveQuietly();
        $page->call('filter', 'unpaid')->set('selected', [(string) $a->id, (string) $b->id, (string) $c->id]);
        $this->assertSame([(int) $a->id, (int) $b->id], $page->viewData('selection')['ids']);
        $page->call('payTogether');
        $group = PaymentGroup::query()->latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $group->invoices()->pluck('invoices.id')->all());
    }

    /** Review of #606: one refused after it was ticked is refused at the click with its reason, nothing opened. */
    public function test_one_refused_after_the_tick_is_refused_at_the_click(): void
    {
        $a = $this->invoice();
        $b = $this->invoice();
        $page = $this->livewireAs($this->user)->test(Index::class)->set('selected', [(string) $a->id, (string) $b->id]);
        $this->processing($b);

        $page->call('payTogether')->assertNoRedirect();
        $this->assertSame(0, PaymentGroup::query()->count());
    }

    /** One ticked that cannot join (a crafted tick) keeps its checkbox, so it can be unticked, and the bar says so. */
    public function test_a_ticked_one_that_cannot_join_can_be_unticked(): void
    {
        $a = $this->invoice();
        $b = $this->invoice();
        $held = $this->invoice();
        $this->processing($held);

        $page = $this->livewireAs($this->user)->test(Index::class)->set('selected', [(string) $a->id, (string) $held->id]);
        $this->assertDoesNotMatchRegularExpression('/id="pay-together-' . $held->id . '"[^>]*\sdisabled\s/', $page->html());
        $page->assertSee(__('invoices.mass_pay_bar.untick', ['invoice' => $held->displayTitle()]));
        $this->assertNotNull($b->id);
    }

    public function test_more_than_the_most_together_is_not_ready(): void
    {
        $ids = [];
        for ($i = 0; $i <= MassPay::MAX_INVOICES; $i++) {
            $ids[] = (string) $this->invoice()->id;
        }
        $this->livewireAs($this->user)->test(Index::class)->set('selected', $ids)
            ->assertSee(__('invoices.mass_pay_bar.too_many', ['count' => MassPay::MAX_INVOICES]));
    }

    /** Review of #606: «select all» adds this page's to those ticked on other pages. */
    public function test_select_all_keeps_the_ticks_of_other_pages(): void
    {
        config(['settings.pagination' => 2]);
        $old = $this->invoice();
        $b = $this->invoice();
        $c = $this->invoice();

        $page = $this->livewireAs($this->user)->test(Index::class)->call('gotoPage', 2)->set('selected', [(string) $old->id])
            ->call('gotoPage', 1)->call('selectAll');
        $this->assertEqualsCanonicalizing([(string) $old->id, (string) $b->id, (string) $c->id], $page->get('selected'));
    }

    /** Review of #606: a tick does not read every invoice's status again. */
    public function test_a_tick_costs_few_queries(): void
    {
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = (string) $this->invoice()->id;
        }
        $page = $this->livewireAs($this->user)->test(Index::class);
        DB::enableQueryLog();
        $page->set('selected', [$ids[0], $ids[1]]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(40, $queries, "a tick ran {$queries} queries");
    }

    public function test_each_unpaid_invoice_says_what_happens_to_it_now(): void
    {
        $this->invoice();
        $held = $this->invoice();
        $this->processing($held);

        $page = $this->livewireAs($this->user)->test(Index::class)->assertSee(__('invoices.payment_status.processing.title'));
        // One that is only due says nothing more than its due date.
        $page->assertDontSee(__('invoices.payment_status.due.title', ['date' => now()->addWeek()->format('d/m/Y')]));
    }

    public function test_no_bar_where_mass_pay_does_not_apply(): void
    {
        $a = $this->invoice();
        $b = $this->invoice();
        config(['settings.mass_pay_enabled' => false]);

        $this->livewireAs($this->user)->test(Index::class)->set('selected', [(string) $a->id, (string) $b->id])
            ->assertDontSee(__('invoices.mass_pay_bar.clear'))->assertDontSeeHtml('wire:model.live="selected"');

        // Nor on the paid list.
        config(['settings.mass_pay_enabled' => true]);
        $this->livewireAs($this->user)->test(Index::class)->call('filter', 'paid')->assertDontSeeHtml('wire:model.live="selected"');
    }
}
