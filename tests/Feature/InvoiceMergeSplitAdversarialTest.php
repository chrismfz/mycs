<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Price;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\MergeInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\SplitInvoice;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\ServiceUpgrade\CancelPendingUpgrades;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of PR E (staff merge and split of unpaid invoices), against 6f5c442. E-ADV-4, 5b, 6, 7, 7b fail
 * there and pass once fixed. E-ADV-1, 1b, 2, 3, 5 were found on f13ddcc (they fail there) and are fixed by the code
 * review commit 2a1c6d3 (CR-1/2/4/5): kept as end-to-end regressions. The rest are attacks that held.
 */
class InvoiceMergeSplitAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        Mail::fake();
        $this->setting('credits_enabled', true, 'boolean');
        $this->setting('credits_auto_use', false, 'boolean');
        $this->setting('default_currency', 'USD', 'string');
        config(['settings.cronjob_invoice' => 7]);
        $this->user = User::factory()->create();
        $this->user->client()->update(['separate_invoices' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function setting(string $key, mixed $value, string $kind): void
    {
        Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
        config(["settings.{$key}" => $value]);
        Cache::forget('settings.rows');
    }

    private function product(float $price = 10.00): object
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => $price]);

        return $product;
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->product();

        return Service::factory()->create($attributes + [
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-25',
        ]);
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /** @return Collection<int, Invoice> */
    private function renewalInvoices(?Service $of = null): Collection
    {
        return Invoice::query()->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)
            ->when($of, fn ($q) => $q->where('reference_id', $of->id)))->with('items')->orderBy('id')->get();
    }

    /** @return array{0: Invoice, 1: Invoice} two renewal invoices of the customer, due the 25th and the 26th */
    private function two(): array
    {
        $this->service();
        $this->service(['expires_at' => '2026-10-26', 'price' => 15.00]);
        $this->cron();
        [$a, $b] = $this->renewalInvoices()->all();

        return [$a, $b];
    }

    private function manualInvoice(float $price, string $due = '2026-10-25'): Invoice
    {
        $invoice = Invoice::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD', 'due_at' => $due, 'status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->create(['description' => 'Manual work', 'price' => $price, 'quantity' => 1]);

        return $invoice;
    }

    /** @return array{0: Service, 1: ServiceUpgrade, 2: Invoice} a pending upgrade (10 → 20 a month) on its own unpaid invoice */
    private function pendingUpgrade(): array
    {
        $service = $this->service(['expires_at' => '2026-11-15']);
        $target = $this->product(20.00);
        $invoice = Invoice::query()->create(['client_id' => $service->client_id, 'currency_code' => 'USD', 'due_at' => '2026-10-27', 'status' => Invoice::STATUS_UNPAID]);
        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id,
            'status' => ServiceUpgrade::STATUS_PENDING, 'invoice_id' => $invoice->id]);
        $invoice->items()->create(['reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class, 'description' => 'Upgrade',
            'quantity' => 1, 'price' => $upgrade->calculatePrice()->price]);

        return [$service, $upgrade, $invoice->fresh()];
    }

    private function upgradeInvoices(ServiceUpgrade $upgrade): int
    {
        return Invoice::query()->whereHas('items', fn ($q) => $q->where('reference_type', ServiceUpgrade::class)->where('reference_id', $upgrade->id))->count();
    }

    // ------------------------------------------------------------------------------------------------ findings

    /**
     * E-ADV-1 (high; fixed by CR-1). An upgrade's line merged onto another invoice: service_upgrades.invoice_id still names the
     * source. Paid, ServiceUpgradeService::shortfall() sums the upgrade's lines on the SOURCE (none now), so it decides
     * the whole upgrade is still owed and bills it a second time on a new invoice.
     */
    public function test_e_adv_1_paying_a_merged_upgrade_does_not_bill_it_again(): void
    {
        [, $upgrade, $upgradeInvoice] = $this->pendingUpgrade();
        $other = $this->manualInvoice(5.00);

        $target = (new MergeInvoices)->handle($other->id, [$upgradeInvoice->id]);
        (new RecordPaymentService)->handle($target->fresh(), null, $target->fresh()->total, transactionId: 'wire-up');

        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
        $this->assertSame(1, $this->upgradeInvoices($upgrade), 'the upgrade, paid in full on the merged invoice, was billed again (shortfall)');
    }

    /** E-ADV-1b (high; fixed by CR-1). The same through split: the upgrade's line split off to a new invoice, then paid. */
    public function test_e_adv_1b_paying_a_split_off_upgrade_does_not_bill_it_again(): void
    {
        [, $upgrade, $upgradeInvoice] = $this->pendingUpgrade();
        $upgradeInvoice->items()->create(['description' => 'Manual work', 'price' => 5, 'quantity' => 1]);
        $line = $upgradeInvoice->items()->where('reference_type', ServiceUpgrade::class)->sole();

        $new = (new SplitInvoice)->handle($upgradeInvoice->id, [$line->id]);
        (new RecordPaymentService)->handle($new->fresh(), null, $new->fresh()->total, transactionId: 'wire-split-up');

        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
        $this->assertSame(1, $this->upgradeInvoices($upgrade), 'the upgrade, paid in full on the split invoice, was billed again (shortfall)');
    }

    /**
     * E-ADV-2 (high; fixed by CR-2). A pending upgrade given up on (expired, its service ended) after its line was merged away:
     * CancelPendingUpgrades looks at service_upgrades.invoice_id (the cancelled source), cancels the upgrade, and
     * leaves its line billing on the merged invoice. The customer pays for an upgrade that is never applied.
     */
    public function test_e_adv_2_an_upgrade_given_up_on_is_not_left_billing_on_the_merged_invoice(): void
    {
        [, $upgrade, $upgradeInvoice] = $this->pendingUpgrade();
        $other = $this->manualInvoice(5.00);
        $target = (new MergeInvoices)->handle($other->id, [$upgradeInvoice->id]);

        [$cancelled] = CancelPendingUpgrades::one($upgrade->id, 'expired');

        $billed = InvoiceItem::query()->where('reference_type', ServiceUpgrade::class)->where('reference_id', $upgrade->id)
            ->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_UNPAID))->count();
        $this->assertFalse($cancelled && $billed > 0, "upgrade cancelled, but invoice #{$target->id} still bills it");
    }

    /**
     * E-ADV-3 (medium; fixed by CR-4). An order's invoice merged away: «Cancel & Refund» refunds orders.invoice_id, the
     * empty merged one, so nothing was refunded and nothing reported. Now an order's invoice is not merged.
     */
    public function test_an_orders_invoice_is_not_merged_away(): void
    {
        $product = $this->product()->product;
        $place = fn () => (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')]);
        $first = $place();
        $second = $place();

        $this->expectException(DisplayException::class);
        (new MergeInvoices)->handle($first->invoice->id, [$second->invoice->id]);
    }

    /**
     * E-ADV-4 (medium). Immutable invoices on: an issued invoice is not edited (the policy allows update only on a draft;
     * Split is hidden). The Merge bulk action authorises on the class (`can('update', Invoice::class)`), which ignores
     * immutability, and rewrites issued invoices.
     */
    public function test_e_adv_4_merge_respects_immutable_invoices(): void
    {
        [$a, $b] = $this->two();
        config(['settings.immutable_invoices_enabled' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->admin()->create();

        try {
            $this->livewireAs($staff)->test(ListInvoices::class)
                ->selectTableRecords([$a->id, $b->id])
                ->callAction(TestAction::make('merge')->table()->bulk(), data: ['target' => $a->id]);
        } catch (\Throwable) {
        }

        $this->assertNull($b->fresh()->merged_into_id, 'issued invoices merged while invoices are immutable');
        $this->assertSame(1, $b->fresh()->items()->count());
    }

    /**
     * E-ADV-5 (medium, fixed by CR-5; only with «suspend only with an overdue invoice»). That rule needs an overdue invoice ISSUED
     * before Suspend Days (invoices.created_at). Split puts the line on a brand-new invoice: the service is not suspended
     * for another Suspend Days, though nothing was paid. (Merging an old overdue invoice into a newer one does the same.)
     */
    public function test_e_adv_5_split_does_not_delay_a_suspension(): void
    {
        $this->user->client()->update(['separate_invoices' => false]);
        Carbon::setTestNow('2026-10-18 10:00:00');
        $a = $this->service();
        $b = $this->service();
        $this->cron(); // one grouped invoice, due the 25th, two lines
        $invoice = $this->renewalInvoices()->sole();
        $this->assertCount(2, $invoice->items);

        Carbon::setTestNow('2026-11-02 10:00:00');
        (new SplitInvoice)->handle($invoice->id, [$invoice->items->firstWhere('reference_id', $b->id)->id]);

        config(['settings.cronjob_suspend_requires_invoice' => true, 'settings.cronjob_order_suspend' => 8]);
        Carbon::setTestNow('2026-11-03 10:00:00'); // 9 days overdue
        $this->cron();

        $this->assertSame(Service::STATUS_SUSPENDED, $a->fresh()->status);
        $this->assertSame(Service::STATUS_SUSPENDED, $b->fresh()->status, 'the line split off yesterday escapes the suspension');
    }

    /**
     * E-ADV-5b (low). Same rule: merging the overdue invoice into a newer one (staff pick the target) restarts the count
     * from the newer invoice's issue date. Fix: the target takes the earliest created_at of the merged invoices, as it
     * takes the earliest due date (or refuse a newer target).
     */
    public function test_e_adv_5b_merge_into_a_newer_invoice_does_not_delay_a_suspension(): void
    {
        Carbon::setTestNow('2026-10-18 10:00:00');
        $service = $this->service();
        $this->cron();
        $overdue = $this->renewalInvoices()->sole();

        Carbon::setTestNow('2026-11-02 10:00:00');
        $newer = $this->manualInvoice(5.00, '2026-11-09');
        (new MergeInvoices)->handle($newer->id, [$overdue->id]);

        config(['settings.cronjob_suspend_requires_invoice' => true, 'settings.cronjob_order_suspend' => 8]);
        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->cron();

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'merged into a newer invoice, it escapes the suspension');
    }

    /**
     * E-ADV-6 (medium). A payment the customer started on an invoice before staff merged it (a checkout, a bank
     * transfer with its number) lands on the merged, cancelled invoice: it becomes account credit and, with automatic
     * credit off, the invoice that now bills those lines stays unpaid in full. The customer paid and is dunned/suspended.
     */
    public function test_e_adv_6_a_late_payment_on_a_merged_invoice_reaches_the_invoice_it_was_merged_into(): void
    {
        [$a, $b] = $this->two();
        (new MergeInvoices)->handle($a->id, [$b->id]);

        (new RecordPaymentService)->handle($b->id, null, 15.00, transactionId: 'checkout-started-before-merge');

        $this->assertSame('10.00', number_format((float) $a->fresh()->remaining, 2, '.', ''),
            'the 15.00 paid for the merged invoice did not reach the invoice its lines are on');
    }

    /**
     * E-ADV-7 (medium). Split checks only that a line stays, not what each part comes to. A discount line (a promotion,
     * a goodwill line: a negative price) split off, or left alone, makes an unpaid invoice of a negative (or zero) total:
     * nothing can pay it, it is never settled, and the other part bills the customer without their discount.
     */
    public function test_e_adv_7_split_does_not_leave_an_invoice_of_nothing_or_less(): void
    {
        $invoice = $this->manualInvoice(10.00);
        $discount = $invoice->items()->create(['description' => 'Promotion', 'price' => -3, 'quantity' => 1]);

        try {
            $new = (new SplitInvoice)->handle($invoice->id, [$discount->id]);
        } catch (DisplayException) {
            $new = null;
        }

        $this->assertTrue($new === null || ((float) $new->total > 0 && (float) $invoice->fresh()->total > 0),
            'split made invoice #' . $new?->id . ' of ' . $new?->total . ' (the other now bills ' . $invoice->fresh()->total . ')');
    }

    /** E-ADV-7b (medium). The same with a zero line (a free addon): an unpaid invoice of 0 stays open, overdue, dunned. */
    public function test_e_adv_7b_split_does_not_leave_an_open_invoice_of_zero(): void
    {
        $invoice = $this->manualInvoice(10.00);
        $free = $invoice->items()->create(['description' => 'Free addon', 'price' => 0, 'quantity' => 1]);

        try {
            $new = (new SplitInvoice)->handle($invoice->id, [$free->id]);
        } catch (DisplayException) {
            $new = null;
        }

        $this->assertTrue($new === null || $new->fresh()->status !== Invoice::STATUS_UNPAID, 'an unpaid invoice of 0.00 was left open');
    }

    // ------------------------------------------------------------------------------------------------ held

    /** Held: staff without «update invoices» can neither merge (list) nor split (invoice page). */
    public function test_staff_without_update_permission_cannot_merge_or_split(): void
    {
        [$a, $b] = $this->two();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->withPermissions(['admin.invoices.viewAny'])->create();

        try {
            $this->livewireAs($staff)->test(ListInvoices::class)
                ->selectTableRecords([$a->id, $b->id])
                ->callAction(TestAction::make('merge')->table()->bulk(), data: ['target' => $a->id]);
        } catch (\Throwable) {
        }
        $this->assertNull($b->fresh()->merged_into_id);

        $c = $this->manualInvoice(3.00);
        $c->items()->create(['description' => 'More work', 'price' => 4, 'quantity' => 1]);
        try {
            $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $c->id])
                ->callAction('split', data: ['lines' => [(string) $c->items()->orderBy('id')->value('id')]]);
        } catch (\Throwable) {
        }
        $this->assertSame(0, Invoice::query()->where('split_from_id', $c->id)->count());
    }

    /** Held: a merged invoice is not reopened by recording a payment on it «with reopen». */
    public function test_a_merged_invoice_is_not_reopened_by_a_staff_payment(): void
    {
        [$a, $b] = $this->two();
        (new MergeInvoices)->handle($a->id, [$b->id]);

        try {
            (new RecordPaymentService)->recordByStaff($b->fresh(), null, 15.00, 'wire-x', reopen: true);
            $this->fail('reopened a merged invoice');
        } catch (DisplayException) {
        }
        $this->assertSame(Invoice::STATUS_CANCELLED, $b->fresh()->status);
        $this->assertSame(0, $b->fresh()->transactions()->count());
    }

    /** Held: merging an invoice into itself, or only with itself named twice, is refused and changes nothing. */
    public function test_merge_into_itself_is_refused(): void
    {
        [$a] = $this->two();

        $this->expectException(DisplayException::class);
        try {
            (new MergeInvoices)->handle($a->id, [$a->id, $a->id]);
        } finally {
            $this->assertSame(Invoice::STATUS_UNPAID, $a->fresh()->status);
        }
    }

    /** Held: a processing payment (SEPA) on a source refuses the merge. */
    public function test_a_processing_payment_refuses_the_merge(): void
    {
        [$a, $b] = $this->two();
        (new RecordPaymentService)->handle($b, null, 15.00, transactionId: 'sepa-1', status: InvoiceTransactionStatus::Processing);

        $this->expectException(DisplayException::class);
        (new MergeInvoices)->handle($a->id, [$b->id]);
    }

    /** Held: a cancelled (not merged) invoice, or one the renewal run is finishing, is not merged. */
    public function test_cancelled_or_finishing_invoices_are_refused(): void
    {
        [$a, $b] = $this->two();
        $c = $this->manualInvoice(3.00);
        $c->update(['status' => Invoice::STATUS_CANCELLED]);
        $b->forceFill(['finish_pending_at' => now()])->saveQuietly();

        foreach ([$c, $b] as $source) {
            try {
                (new MergeInvoices)->handle($a->id, [$source->id]);
                $this->fail("merged invoice #{$source->id}");
            } catch (DisplayException) {
            }
        }
        $this->assertSame(1, $a->items()->count());
    }

    /** Held: a credit purchase is neither merged nor split. */
    public function test_a_credit_purchase_is_not_merged(): void
    {
        [$a] = $this->two();
        $buy = Invoice::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD', 'due_at' => '2026-10-25', 'status' => Invoice::STATUS_UNPAID]);
        $buy->items()->create(['description' => 'Account credit', 'price' => 50, 'quantity' => 1, 'reference_type' => Credit::class]);

        $this->expectException(DisplayException::class);
        (new MergeInvoices)->handle($a->id, [$buy->id]);
    }
}
