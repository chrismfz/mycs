<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Livewire\Invoices\Index;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\PaymentGroup;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Tests\TestCase;

/**
 * Adversarial review of PR #606 (the invoices list's Mass Pay bar). The ADV-606-N tests fail on the PR head and state the
 * expected behaviour; tests named test_pin_* passed there and pin a rule the bar must keep.
 *
 * The one rule behind most of them: the bar says «ready» (no problem, the button enabled, a total) exactly when
 * MassPay::open would take what is ticked, and the total it shows is what the group charges.
 */
class Adv606MassPayBarTest extends TestCase
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

    /** The bar's verdict, then what MassPay::open makes of the same ticks: they must agree. */
    private function assertBarAgreesWithOpen(Testable $page, string $message): void
    {
        $selection = $page->viewData('selection');
        $before = PaymentGroup::query()->count();
        $page->call('payTogether');
        $made = PaymentGroup::query()->count() > $before;
        if ($selection['problem'] === null) {
            $this->assertTrue($made, $message . ': the bar says «' . __('invoices.mass_pay_bar.ready') . '» (' . $selection['total'] . '), but Pay together made no group');
        } else {
            $this->assertFalse($made, $message . ': the bar blocks it («' . $selection['problem'] . '»), but Pay together took it');
        }
    }

    /**
     * ADV-606-1 (medium). The customer ticks two invoices, then a payment for one of them starts elsewhere (a SEPA
     * debit from its own page in another tab, a PayPal review): refusal() now refuses it. On the next render the bar
     * still says «One payment, all of them paid.» with «Pay together · 20.00 USD» enabled, and the click fails with «A
     * payment … is still being processed». Worse, the ticked invoice's checkbox is now disabled while still ticked,
     * so the customer cannot untick it: only «Clear» gets out. selection() never asks refusal().
     */
    public function test_adv_606_1_a_ticked_invoice_that_can_no_longer_join_still_reads_ready(): void
    {
        $a = $this->invoice();
        $b = $this->invoice();
        $page = $this->livewireAs($this->user)->test(Index::class)->set('selected', [(string) $a->id, (string) $b->id]);
        $this->assertNull($page->viewData('selection')['problem'], 'ready while both can join');

        $this->processing($b);
        $page->call('$refresh');

        $this->assertNotNull($page->viewData('selection')['problem'], 'a ticked invoice refusal() refuses: the bar must not say ready');
        $this->assertBarAgreesWithOpen($page, 'a ticked invoice now refused');
    }

    /**
     * ADV-606-1b (medium), the same through a crafted update, or a tick kept from another page: the disabled checkbox
     * is only HTML, and a ticked invoice on another page has no refusal shown at all. Each refusal() reason the bar
     * does not ask: a payment on its way, nothing left to pay, an invoice that buys credit. Each reads «ready».
     */
    public function test_adv_606_1b_a_refused_invoice_in_selected_reads_ready(): void
    {
        $refused = [
            'a payment on its way' => function () {
                $invoice = $this->invoice();
                $this->processing($invoice);

                return $invoice;
            },
            'nothing left to pay' => function () {
                $invoice = $this->invoice();
                DB::table('invoice_transactions')->insert(['invoice_id' => $invoice->id, 'gateway_id' => null, 'amount' => '10.00',
                    'status' => InvoiceTransactionStatus::Succeeded->value, 'transaction_id' => 'full_' . $invoice->id, 'created_at' => now(), 'updated_at' => now()]);

                return $invoice;
            },
            'buys credit' => function () {
                $invoice = $this->invoice();
                $invoice->items()->update(['reference_type' => Credit::class, 'reference_id' => null]);

                return $invoice;
            },
        ];
        $failures = [];
        foreach ($refused as $why => $make) {
            $a = $this->invoice();
            $b = $make();
            $this->assertNotNull(MassPay::refusal($b->fresh()), $why . ': refusal() refuses it');
            $selection = $this->livewireAs($this->user)->test(Index::class)->set('selected', [(string) $a->id, (string) $b->id])->viewData('selection');
            if ($selection['problem'] === null) {
                $failures[] = $why . ' → «' . __('invoices.mass_pay_bar.ready') . '» ' . $selection['total'];
            }
        }
        $this->assertSame([], $failures, 'the bar reads ready for invoices MassPay::open refuses');
    }

    /**
     * ADV-606-1c (low, UX). After ADV-606-1, the refused invoice is still in `selected` and its checkbox is rendered
     * disabled: ticked, and the customer cannot untick it. Either it leaves the selection, or its box stays usable.
     */
    public function test_adv_606_1c_a_ticked_invoice_that_becomes_refused_cannot_be_unticked(): void
    {
        $a = $this->invoice();
        $b = $this->invoice();
        $page = $this->livewireAs($this->user)->test(Index::class)->set('selected', [(string) $a->id, (string) $b->id]);
        $this->processing($b);
        $page->call('$refresh');

        $stuck = in_array((string) $b->id, $page->get('selected'), true)
            && preg_match('/id="pay-together-' . $b->id . '"[^>]*\sdisabled\s/', $page->html()) === 1;
        $this->assertFalse($stuck, 'ticked, and its checkbox disabled: it cannot be unticked');
    }

    /**
     * ADV-606-2 (medium). Three ticked; one of them is paid meanwhile (the cron's charge, its own page in another tab,
     * staff). selection() quietly drops it (status = unpaid) and the bar reads «2 selected · 20.00 USD», ready; but
     * payTogether sends the raw `selected`, still with the paid one, and open() refuses all of it («Invoice … is not
     * unpaid»). With the «Unpaid» filter the paid one is not even on the page, so nothing on screen says which.
     */
    public function test_adv_606_2_a_ticked_invoice_paid_meanwhile_is_dropped_by_the_bar_but_not_by_pay(): void
    {
        $a = $this->invoice();
        $b = $this->invoice();
        $c = $this->invoice();
        $page = $this->livewireAs($this->user)->test(Index::class)->call('filter', 'unpaid')
            ->set('selected', [(string) $a->id, (string) $b->id, (string) $c->id]);

        $c->forceFill(['status' => Invoice::STATUS_PAID])->saveQuietly();
        $page->call('$refresh');

        $selection = $page->viewData('selection');
        $this->assertSame(2, $selection['count']);
        $this->assertBarAgreesWithOpen($page, 'a ticked invoice paid meanwhile');
    }

    /**
     * ADV-606-2b (medium), the same with an invoice staff cancelled, or one of another account: the bar counts what
     * is left, payTogether sends what was ticked.
     */
    public function test_adv_606_2b_a_ticked_invoice_cancelled_meanwhile(): void
    {
        $a = $this->invoice();
        $b = $this->invoice();
        $c = $this->invoice();
        $page = $this->livewireAs($this->user)->test(Index::class)->set('selected', [(string) $a->id, (string) $b->id, (string) $c->id]);

        $c->forceFill(['status' => Invoice::STATUS_CANCELLED])->saveQuietly();
        $page->call('$refresh');

        $this->assertBarAgreesWithOpen($page, 'a ticked invoice cancelled meanwhile');
    }

    /**
     * ADV-606-3 (low, crafted). selection() keeps only whole-number strings (ctype_digit), open() takes intval() of
     * everything: '7abc' is 7, '1 OR 1=1' is 1, an array is 1, 'x' is 0. So the two read different sets. Here a stray
     * value makes the bar read ready while open() counts one id more and refuses («changed meanwhile»).
     */
    public function test_adv_606_3_bar_and_open_read_a_crafted_selection_differently(): void
    {
        $a = $this->invoice();
        $b = $this->invoice();

        $page = $this->livewireAs($this->user)->test(Index::class)->set('selected', [(string) $a->id, (string) $b->id, 'x']);
        $this->assertBarAgreesWithOpen($page, 'a stray value in selected');
    }

    /** ADV-606-3b (low, crafted), the reverse: the bar blocks ('5abc' counts for nothing) what open() takes as 5. */
    public function test_adv_606_3b_bar_blocks_what_open_takes(): void
    {
        $a = $this->invoice();
        $b = $this->invoice();

        $page = $this->livewireAs($this->user)->test(Index::class)->set('selected', [(string) $a->id, $b->id . 'abc']);
        $this->assertBarAgreesWithOpen($page, 'an id with a tail');
    }

    /**
     * ADV-606-4 (low). The bar's total is number_format() with a dot and «USD»-style code, whatever the currency's
     * format; each card above it shows the currency's own (Price: €1.234,50 for a '1.000,00' currency, nixpal's EUR).
     * A Greek customer reads «1,234.50 EUR» under cards in «€1.234,50». The amount is right; its writing is not the
     * page's.
     */
    public function test_adv_606_4_the_total_is_written_as_the_cards_write_money(): void
    {
        Currency::query()->updateOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'suffix' => '', 'format' => '1.000,00']);
        $a = $this->invoice('1000.00', 'EUR');
        $b = $this->invoice('234.50', 'EUR');

        $page = $this->livewireAs($this->user)->test(Index::class)->set('selected', [(string) $a->id, (string) $b->id]);
        $page->assertSee('€1.000,00'); // the card
        $this->assertStringContainsString('1.234,50', (string) $page->viewData('selection')['total'], 'the bar writes money as the cards do');
    }

    /**
     * ADV-606-5 (low, performance). The page reads each unpaid invoice's PaymentStatus and refusal() one by one, and
     * remaining lazy-loads its transactions: the queries grow with the unpaid invoices on the page. Measured on the PR
     * head (SQLite, no saved method, no service): see the report. A page of N unpaid invoices should cost about the
     * same as a page of 2.
     */
    public function test_adv_606_5_queries_do_not_grow_with_the_unpaid_invoices_on_the_page(): void
    {
        config(['settings.pagination' => 20]);
        $count = function (int $unpaid, bool $tick): int {
            Invoice::query()->delete();
            $ids = [];
            for ($i = 0; $i < $unpaid; $i++) {
                $ids[] = (string) $this->invoice()->id;
            }
            $page = $this->livewireAs($this->user)->test(Index::class)->set('selected', $tick ? $ids : []);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $page->call('$refresh');
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        [$two, $twenty, $twentyTicked] = [$count(2, false), $count(20, false), $count(20, true)];
        fwrite(STDERR, "\nADV-606-5 queries per render: 2 unpaid = {$two}, 20 unpaid = {$twenty}, 20 unpaid all ticked = {$twentyTicked}\n");

        $this->assertLessThan($two + 20, $twentyTicked, '18 more unpaid invoices on the page cost ' . ($twentyTicked - $two) . ' more queries');
    }

    /**
     * Pin: a login with two accounts. Invoices ticked on one, then the session switches to the other (another tab):
     * the bar counts nothing of the first, and Pay together makes no group.
     */
    public function test_pin_another_accounts_ticks_count_for_nothing_after_a_switch(): void
    {
        $other = Client::factory()->create();
        ClientUser::query()->create(['client_id' => $other->id, 'user_id' => $this->user->id, 'permissions' => null, 'granted_at' => now()]);
        $a = $this->invoice();
        $b = $this->invoice();
        $this->invoice('10.00', 'USD', Invoice::STATUS_UNPAID, $other);
        $this->invoice('10.00', 'USD', Invoice::STATUS_UNPAID, $other);

        $page = $this->livewireAs($this->user)->test(Index::class)->set('selected', [(string) $a->id, (string) $b->id]);
        $this->assertSame(2, $page->viewData('selection')['count']);

        session([CurrentClient::SESSION_KEY => $other->id]);
        $page->call('$refresh');
        $this->assertSame(0, $page->viewData('selection')['count'], 'the other account\'s ticks count for nothing');
        $before = PaymentGroup::query()->count();
        $page->call('payTogether');
        $this->assertSame($before, PaymentGroup::query()->count());
    }

    /**
     * Pin: the bar's total is what the group charges (open() sums remaining into the pivot), after a partial payment
     * and with cents that round.
     */
    public function test_pin_the_total_is_what_the_group_charges(): void
    {
        $a = $this->invoice('10.005');
        $b = $this->invoice('20.00');
        DB::table('invoice_transactions')->insert(['invoice_id' => $b->id, 'gateway_id' => null, 'amount' => '7.33',
            'status' => InvoiceTransactionStatus::Succeeded->value, 'transaction_id' => 'part_' . $b->id, 'created_at' => now(), 'updated_at' => now()]);

        $page = $this->livewireAs($this->user)->test(Index::class)->set('selected', [(string) $a->id, (string) $b->id]);
        $total = $page->viewData('selection')['total'];
        $page->call('payTogether');
        $group = PaymentGroup::query()->latest('id')->firstOrFail();
        $this->assertSame(number_format((float) $group->total, 2) . ' USD', $total);
    }

    /**
     * Pin: select all takes only this page's unpaid invoices refusal() lets join, never more than MAX_INVOICES, and not
     * those another filter hides.
     */
    public function test_pin_select_all_stays_on_the_page_and_under_the_cap(): void
    {
        config(['settings.pagination' => 60]);
        $ids = [];
        for ($i = 0; $i < 55; $i++) {
            $ids[] = (string) $this->invoice()->id;
        }
        $this->processing(Invoice::query()->findOrFail((int) $ids[54]));

        $page = $this->livewireAs($this->user)->test(Index::class)->call('selectAll');
        $selected = $page->get('selected');
        $this->assertCount(50, $selected);
        $this->assertNotContains($ids[54], $selected, 'the refused one');
        $this->assertNull($page->viewData('selection')['problem']);

        config(['settings.pagination' => 10]);
        $page = $this->livewireAs($this->user)->test(Index::class)->call('selectAll');
        $this->assertCount(9, $page->get('selected'), 'this page only (the newest ten, one of them refused)');
    }

    /**
     * Pin: the PaymentStatus line renders for each unpaid state the list can meet, without a 500: a saved method's
     * charge held, one taken, a group's payment on its way (PROCESSING_GROUP), no due date. And a held one cannot join.
     */
    public function test_pin_the_status_line_renders_for_every_unpaid_state(): void
    {
        $gateway = Gateway::query()->create(['extension' => 'Adv606None', 'name' => 'CardCo', 'type' => 'gateway', 'enabled' => true]);
        $agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_606', 'type' => 'card']);
        $attempt = fn (Invoice $invoice, string $status, ?int $group = null) => ChargeAttempt::query()->create(['key' => (string) Str::uuid(),
            'invoice_id' => $invoice->id, 'client_id' => $this->client->id, 'billing_agreement_id' => $agreement->id, 'payment_group_id' => $group,
            'amount' => '10.00', 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CLIENT, 'status' => $status, 'started_at' => now(), 'sent_at' => now()]);

        $held = $this->invoice();
        $attempt($held, ChargeAttempt::STATUS_STARTED);
        $taken = $this->invoice();
        $attempt($taken, ChargeAttempt::STATUS_SUCCEEDED);
        $lead = $this->invoice();
        $other = $this->invoice();
        $group = (new MassPay)->open($this->client, [$lead->id, $other->id]);
        $this->processing($lead->fresh());
        $group->update(['invoice_transaction_id' => DB::table('invoice_transactions')->where('invoice_id', $lead->id)->value('id')]);
        $undated = $this->invoice();
        $undated->forceFill(['due_at' => null])->saveQuietly();
        $this->invoice();

        $page = $this->livewireAs($this->user)->test(Index::class)->assertOk()
            ->assertSee(__('invoices.payment_status.held.title'))->assertSee(__('invoices.payment_status.taken.title'))
            ->assertSee(__('invoices.payment_status.processing_group.title'));
        foreach ([$held, $taken, $lead, $other] as $invoice) {
            $this->assertMatchesRegularExpression('/id="pay-together-' . $invoice->id . '"[^>]*\sdisabled\s/', $page->html(), "#{$invoice->id} cannot join");
        }
        $this->assertDoesNotMatchRegularExpression('/id="pay-together-' . $undated->id . '"[^>]*\sdisabled\s/', $page->html(), 'no due date: it can join');
    }

    /** Pin: no checkboxes and no bar on the paid or cancelled filter, nor with fewer than two unpaid invoices. */
    public function test_pin_no_bar_where_mass_pay_does_not_apply(): void
    {
        $a = $this->invoice();
        $b = $this->invoice();
        $this->invoice('10.00', 'USD', Invoice::STATUS_PAID);
        foreach (['paid', 'cancelled'] as $filter) {
            $this->livewireAs($this->user)->test(Index::class)->call('filter', $filter)->set('selected', [(string) $a->id, (string) $b->id])
                ->assertDontSeeHtml('wire:model.live="selected"')->assertDontSee(__('invoices.mass_pay_bar.clear'));
        }
        $b->forceFill(['status' => Invoice::STATUS_CANCELLED])->saveQuietly();
        $this->livewireAs($this->user)->test(Index::class)->set('selected', [(string) $a->id, (string) $b->id])
            ->assertDontSeeHtml('wire:model.live="selected"')->assertDontSee(__('invoices.mass_pay_bar.clear'));
    }
}
