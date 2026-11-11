<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Exceptions\DisplayException;
use App\Models\AdjustmentNote;
use App\Models\Credit;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use App\Services\Invoice\MergeInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\SplitInvoice;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Staff freedom on invoices, item 6 (owner 2026-11-10, sweep 7 TIGHT-INVOICES-PAYMENTS-26, docs/staff-freedom.md): merge
 * and split no longer refuse a draft, an order's own invoice, other billing details or a note. On unpaid invoices the
 * WHMCS importer brought for two services («Hosting» lines with no period, none applied).
 */
class StaffFreedomMergeSplitTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    private Invoice $a;

    private Invoice $b;

    private Staff $staff;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-10-05 10:00:00');
        config(['settings.credits_enabled' => true]);
        $this->clientId = User::factory()->create()->client()->id;
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($this->clientId, $product->product->id);
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsService(32, '2026-10-12');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0, 32 => 10.0], due: '2026-09-10');
        $this->whmcsInvoice(502, 'Unpaid', [31 => 10.0], due: '2026-10-10');
        $this->whmcsInvoice(503, 'Unpaid', [32 => 10.0], due: '2026-10-12');
        $this->importWhmcs();
        $this->a = $this->imported(502);
        $this->b = $this->imported(503);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cents(Invoice $invoice): array
    {
        $fresh = $invoice->fresh();

        return [RecordPaymentService::toCents($fresh->total), RecordPaymentService::toCents($fresh->remaining)];
    }

    private function expires(int $service): string
    {
        return Service::query()->findOrFail($service)->expires_at->toDateString();
    }

    public function test_a_draft_is_merged_and_split(): void
    {
        DB::table('invoices')->where('id', $this->b->id)->update(['status' => Invoice::STATUS_DRAFT]);

        $target = (new MergeInvoices)->handle($this->a->id, [$this->b->id]);

        $this->assertSame([Invoice::STATUS_UNPAID, 2], [$target->status, $target->items()->count()]);
        $this->assertSame([Invoice::STATUS_CANCELLED, 0], [$this->b->fresh()->status, $this->b->fresh()->adjustmentNotes()->count()], 'no credit note for a draft merged away');

        // A draft split gives a draft.
        DB::table('invoices')->where('id', $this->a->id)->update(['status' => Invoice::STATUS_DRAFT]);
        $new = (new SplitInvoice)->handle($this->a->id, [$this->a->items()->where('reference_id', 32)->value('id')]);
        $this->assertSame([Invoice::STATUS_DRAFT, $this->a->id, 1000], [$new->status, $new->split_from_id, RecordPaymentService::toCents($new->total)]);
        $this->assertSame([1000, 1000], $this->cents($this->a));
    }

    public function test_an_orders_own_invoice_is_merged_and_split_and_the_order_follows_a_merge(): void
    {
        $order = Order::query()->create(['client_id' => $this->clientId, 'currency_code' => 'EUR']);
        $order->forceFill(['invoice_id' => $this->b->id])->save();

        $target = (new MergeInvoices)->handle($this->a->id, [$this->b->id]);

        $this->assertSame($this->a->id, (int) $order->fresh()->invoice_id);
        $new = (new SplitInvoice)->handle($target->id, [$target->items()->where('reference_id', 31)->value('id')]);
        $this->assertSame([1000, 1000], [RecordPaymentService::toCents($new->total), RecordPaymentService::toCents($target->fresh()->total)]);
        $this->assertSame($this->a->id, (int) $order->fresh()->invoice_id, 'a split leaves the order with its invoice');
    }

    public function test_invoices_billed_under_other_details_merge_under_the_targets_and_no_line_is_retaxed(): void
    {
        foreach ([[$this->a, 'GR', 24], [$this->b, 'DE', 19]] as [$invoice, $country, $rate]) {
            DB::table('invoice_snapshots')->insert(['invoice_id' => $invoice->id, 'name' => 'Customer ' . $country, 'tax_country' => $country, 'tax_rate' => $rate,
                'tax_name' => 'VAT', 'created_at' => now(), 'updated_at' => now()]);
        }
        $line = $this->b->items()->sole()->only(['tax_rate', 'tax_amount', 'line_total']);

        $target = (new MergeInvoices)->handle($this->a->id, [$this->b->id]);

        $this->assertSame('Customer GR', $target->user_name, 'the target\'s details apply');
        $this->assertSame($line, $target->items()->where('reference_id', 32)->sole()->only(['tax_rate', 'tax_amount', 'line_total']), 'the line keeps its tax as issued');
        $this->assertSame([2000, 2000], $this->cents($this->a));
    }

    public function test_a_note_goes_with_a_merge_and_stays_at_a_split(): void
    {
        $discount = $this->b->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -3, 'description' => 'Loyalty']);
        $voided = $this->b->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -1, 'description' => 'Mistake']);
        $voided->update(['status' => AdjustmentNoteStatus::Voided]);
        $this->assertSame([700, 700], $this->cents($this->b));

        $target = (new MergeInvoices)->handle($this->a->id, [$this->b->id]);

        $this->assertSame([$this->a->id, '-3.00', $discount->number], [$discount->fresh()->invoice_id, (string) $discount->fresh()->amount, $discount->fresh()->number], 'the discount follows its lines');
        $this->assertSame($this->b->id, $voided->fresh()->invoice_id, 'a voided note stays behind');
        $this->assertSame([1700, 1700], $this->cents($this->a), 'the customer keeps the discount: 20.00 less 3.00');
        $this->assertSame(0.0, (float) $this->b->fresh()->total, 'nothing is left on the merged-away one');

        // Split: the note stays on the invoice it is on now.
        $new = (new SplitInvoice)->handle($target->id, [$target->items()->where('reference_id', 32)->value('id')]);
        $this->assertSame($this->a->id, $discount->fresh()->invoice_id);
        $this->assertSame([[700, 700], [1000, 1000]], [$this->cents($this->a), $this->cents($new)]);
        $this->assertSame(1700, array_sum([$this->cents($this->a)[0], $this->cents($new)[0]]), 'nothing gained or lost by the split');
    }

    /** Kept: each part of a split bills more than nothing, the notes that stay counted in. */
    public function test_kept_a_split_does_not_leave_the_first_invoice_below_nothing(): void
    {
        $target = (new MergeInvoices)->handle($this->a->id, [$this->b->id]);
        $target->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -12, 'description' => 'Big discount']);

        try {
            (new SplitInvoice)->handle($target->id, [$target->items()->where('reference_id', 32)->value('id')]);
            $this->fail('a split left an invoice of less than nothing');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('more than nothing', $e->getMessage());
        }
        $this->assertSame(2, $target->fresh()->items()->count());
        $this->assertSame(0, Invoice::query()->where('split_from_id', $target->id)->count());
    }

    /** Kept (REV-780-5): a move to another account never cuts an order's own invoice; whole with its order, it goes. */
    public function test_kept_a_move_to_another_account_does_not_cut_an_orders_own_invoice(): void
    {
        $target = (new MergeInvoices)->handle($this->a->id, [$this->b->id]); // one invoice billing both services
        $order = Order::query()->create(['client_id' => $this->clientId, 'currency_code' => 'EUR']);
        $order->forceFill(['invoice_id' => $target->id])->save();
        Service::query()->whereKey([31, 32])->update(['order_id' => $order->id]);
        $to = User::factory()->create()->client();

        try {
            app(MoveToClient::class)->service(Service::query()->findOrFail(31), $to, $this->staff);
            $this->fail('the order\'s own invoice was cut by the move');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('invoice of an order', $e->getMessage());
        }
        $this->assertSame([2, $this->clientId], [$target->fresh()->items()->count(), (int) Service::query()->findOrFail(31)->client_id]);
    }

    /** Kept, each with its reason: what would lose or double money, or is Ekdosi's. */
    public function test_kept_payments_applied_lines_currencies_subscriptions_and_ekdosi_still_refuse(): void
    {
        $refused = function (\Closure $arrange, string $needle): void {
            DB::beginTransaction();
            try {
                $arrange();
                try {
                    (new MergeInvoices)->handle($this->a->id, [$this->b->id]);
                    $this->fail("merged although: {$needle}");
                } catch (DisplayException $e) {
                    $this->assertStringContainsString($needle, $e->getMessage());
                }
            } finally {
                DB::rollBack();
            }
            $this->assertSame([1, 1], [$this->a->items()->count(), $this->b->items()->count()], $needle);
        };

        // Payments on it: the money would stay on a cancelled, empty invoice.
        $refused(fn () => (new RecordPaymentService)->handle($this->b->fresh(), null, '4.00', transactionId: 'wire-4', enteredByStaff: true), 'has payments');
        // A line already applied: its period would be given twice.
        $refused(fn () => DB::table('invoice_items')->where('invoice_id', $this->b->id)->update(['applied_at' => now()]), 'already applied');
        // Other currencies.
        $refused(function () {
            DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
            DB::table('invoices')->where('id', $this->b->id)->update(['currency_code' => 'USD']);
        }, 'one currency');
        // A gateway subscription pays its own invoice.
        $refused(fn () => DB::table('services')->where('id', 32)->update(['subscription_id' => 'I-SUB32']), 'gateway subscription');
        // Ekdosi's: a fiscal document; and a paid invoice (what Ekdosi issues from).
        $refused(fn () => EkdosiDocument::query()->create(['invoice_id' => $this->b->id, 'mark' => '400001234500005', 'code' => 'ΤΠΥ5', 'state' => EkdosiDocument::ACTIVE]), 'fiscal document from Ekdosi');
        $refused(fn () => DB::table('invoices')->where('id', $this->b->id)->update(['status' => Invoice::STATUS_PAID]), 'not unpaid or a draft');
        // Ekdosi's too (#778): one staff sent there, and an account Ekdosi reads without a request, a draft of it as well
        // (unpaid lines never go into a draft there).
        $refused(fn () => DB::table('invoices')->where('id', $this->b->id)->update(['ekdosi_requested_at' => now()]), 'was sent to Ekdosi');
        $refused(function () {
            config(['settings.ekdosi_enabled' => true]);
            DB::table('clients')->where('id', $this->clientId)->update(['immediate_invoicing' => true]);
            DB::table('invoices')->where('id', $this->b->id)->update(['status' => Invoice::STATUS_DRAFT]);
        }, 'immediate invoicing');
        config(['settings.ekdosi_enabled' => false]);
        // A credit purchase gives its credit from its own money.
        $refused(fn () => DB::table('invoice_items')->where('invoice_id', $this->b->id)->update(['reference_type' => Credit::class, 'reference_id' => null]), 'buys account credit');

        $this->assertSame(0, AdjustmentNote::query()->count());
        $this->assertSame(['2026-10-10', '2026-10-12'], [$this->expires(31), $this->expires(32)]);
    }
}
