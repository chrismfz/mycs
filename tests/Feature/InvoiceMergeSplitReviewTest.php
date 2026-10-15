<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Price;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\MergeInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\SplitInvoice;
use App\Services\ServiceUpgrade\CancelPendingUpgrades;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Code review of PR E (merge/split): the findings CR-1…CR-8, as tests. */
class InvoiceMergeSplitReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $due = '2026-10-25', array $line = []): Invoice
    {
        $invoice = Invoice::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD', 'due_at' => $due, 'status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->create($line + ['price' => 5, 'quantity' => 1, 'description' => 'Misc']);

        return $invoice;
    }

    private function service(string $status = Service::STATUS_ACTIVE): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-11-05']);
    }

    /** @return array{0: ServiceUpgrade, 1: Invoice, 2: Invoice} */
    private function upgradeSetup(): array
    {
        $service = $this->service();
        $to = $this->createProduct();
        Price::query()->where('plan_id', $to->plan->id)->update(['price' => 40.00]);
        $upInvoice = Invoice::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD', 'due_at' => '2026-10-27', 'status' => Invoice::STATUS_UNPAID]);
        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'product_id' => $to->product->id, 'plan_id' => $to->plan->id, 'invoice_id' => $upInvoice->id]);
        $price = $upgrade->calculatePrice()->price;
        $this->assertGreaterThan(0, (float) $price);
        $upInvoice->items()->create(['reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id, 'price' => $price, 'quantity' => 1, 'description' => 'Upgrade']);

        // A renewal on the other invoice: a loose line there would leave the upgrade's line to staff (CancelOpenInvoices).
        return [$upgrade, $upInvoice, $this->invoice(line: ['reference_type' => Service::class, 'reference_id' => $this->service()->id, 'price' => 10])];
    }

    /** CR-1: the upgrade follows its line; paid on the target, it is not billed again. */
    public function test_merged_upgrade_paid_on_target_is_not_billed_again(): void
    {
        [$upgrade, $upInvoice, $other] = $this->upgradeSetup();
        $target = (new MergeInvoices)->handle($other->id, [$upInvoice->id]);
        $this->assertSame($target->id, (int) $upgrade->fresh()->invoice_id);
        $invoicesBefore = Invoice::query()->count();

        (new RecordPaymentService)->handle($target->fresh(), null, (float) $target->fresh()->total, transactionId: 'wire-up');

        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
        $this->assertSame($invoicesBefore, Invoice::query()->count(), 'no shortfall invoice for its full price');
    }

    /** CR-2: an expired upgrade takes its line off the invoice it was merged into. */
    public function test_cancelling_a_merged_pending_upgrade_takes_its_line_off_the_target(): void
    {
        [$upgrade, $upInvoice, $other] = $this->upgradeSetup();
        $target = (new MergeInvoices)->handle($other->id, [$upInvoice->id]);

        CancelPendingUpgrades::one($upgrade->id, 'upgrade_expired');

        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status);
        $this->assertSame(0, InvoiceItem::query()->where('invoice_id', $target->id)->where('reference_type', ServiceUpgrade::class)->count());
    }

    /** CR-1 for split: the upgrade follows its line to the new invoice. */
    public function test_a_split_upgrade_line_takes_its_upgrade_along(): void
    {
        [$upgrade, $upInvoice] = $this->upgradeSetup();
        $upInvoice->items()->create(['price' => 3, 'quantity' => 1, 'description' => 'Extra']);
        $line = $upInvoice->items()->where('reference_type', ServiceUpgrade::class)->value('id');

        $new = (new SplitInvoice)->handle($upInvoice->id, [(int) $line]);

        $this->assertSame($new->id, (int) $upgrade->fresh()->invoice_id);
    }

    /** CR-3: the lines of an ended service (left open for staff) do not move onto a payable invoice. */
    public function test_merge_with_an_invoice_of_an_ended_service_is_refused(): void
    {
        $ended = $this->service();
        $a = $this->invoice(line: ['reference_type' => Service::class, 'reference_id' => $ended->id, 'price' => 10]);
        $b = $this->invoice(line: ['reference_type' => Service::class, 'reference_id' => $this->service()->id, 'price' => 10]);
        Service::query()->whereKey($ended->id)->update(['status' => Service::STATUS_CANCELLED]);

        $this->expectException(DisplayException::class);
        (new MergeInvoices)->handle($b->id, [$a->id]);
    }

    /** CR-4: an order's own invoice (what «Cancel & Refund» refunds) is not merged or split. */
    public function test_an_orders_invoice_is_not_merged(): void
    {
        $a = $this->invoice();
        $b = $this->invoice();
        $order = Order::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD']);
        $order->forceFill(['invoice_id' => $b->id])->save();

        $this->expectException(DisplayException::class);
        (new MergeInvoices)->handle($a->id, [$b->id]);
    }

    /** CR-5: split keeps the issue date, so a suspension that waits for an invoice of a given age is not delayed. */
    public function test_a_split_invoice_keeps_the_issue_date(): void
    {
        $a = $this->invoice();
        $a->items()->create(['price' => 3, 'quantity' => 1, 'description' => 'Extra']);
        Carbon::setTestNow('2026-10-22 10:00:00');

        $new = (new SplitInvoice)->handle($a->id, [(int) $a->items()->orderByDesc('id')->value('id')]);

        $this->assertSame($a->created_at->toDateTimeString(), $new->created_at->toDateTimeString());
    }

    /** CR-7: neither side of a merge is deleted. */
    public function test_a_merged_invoice_and_its_target_are_not_deleted(): void
    {
        $a = $this->invoice();
        $b = $this->invoice();
        (new MergeInvoices)->handle($a->id, [$b->id]);

        foreach ([$a, $b] as $invoice) {
            try {
                $invoice->fresh()->delete();
                $this->fail('a merged invoice was deleted');
            } catch (DisplayException) {
            }
        }
        $this->assertSame(2, Invoice::query()->whereKey([$a->id, $b->id])->count());
    }

    /** CR-8: an invoice without a snapshot splits into one without a snapshot, not the customer's details today. */
    public function test_a_split_of_an_invoice_without_a_snapshot_takes_none(): void
    {
        $a = $this->invoice();
        $a->items()->create(['price' => 3, 'quantity' => 1, 'description' => 'Extra']);
        $a->snapshot()->delete();

        $new = (new SplitInvoice)->handle($a->id, [(int) $a->items()->orderByDesc('id')->value('id')]);

        $this->assertNull($new->snapshot()->first());
    }
}
