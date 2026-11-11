<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\DomainStatus;
use App\Exceptions\CouponUnusableException;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Coupon;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\MergeInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\SplitInvoice;
use App\Services\Invoice\StaffLineEdit;
use App\Services\Invoice\UndoRefund;
use App\Services\Order\CancelOrder;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #793 (sweep 8: Cancel & Refund, refunds on an invoice paid over, coupon uses). Failing tests
 * for what was found; nothing here fixes app code. Invented data only, no network.
 */
class Rev793Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Cache::flush();
        Carbon::setTestNow('2026-01-01 10:00:00');
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Order, 1: Invoice, 2: list<int>} an order of $count services at 10.00 each, its unpaid invoice, its services */
    private function order(int $count = 2): array
    {
        $product = $this->createProduct()->product;
        $product->update(['autosetup' => Product::AUTOSETUP_PAYMENT, 'server_id' => null, 'stock' => 10]);
        $product = $product->fresh();
        $order = (new PlaceOrderService)->place($this->user->client(), 'USD',
            array_fill(0, $count, new OrderLine($product, $product->plans()->first(), price: '10.00')))->order->fresh();

        return [$order, Invoice::query()->findOrFail($order->invoice_id), $order->services()->orderBy('id')->pluck('id')->all()];
    }

    private function lineOf(Invoice $invoice, int $service): int
    {
        return (int) InvoiceItem::query()->where('invoice_id', $invoice->id)->where('reference_type', Service::class)->where('reference_id', $service)->sole()->id;
    }

    private function pay(Invoice $invoice, string $id): void
    {
        (new RecordPaymentService)->handle($invoice->fresh(), null, (float) $invoice->fresh()->total, transactionId: $id);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** Whether staff were told anything that names the invoice. */
    private static function told(array $left, Invoice $invoice): bool
    {
        return collect(array_keys($left))->contains(fn (string $key) => str_contains($key, "#{$invoice->id}"))
            || collect($left)->contains(fn (\Throwable $e) => str_contains($e->getMessage(), "#{$invoice->id}"));
    }

    /**
     * REV-793-1 (claim 3, introduced by this PR). «Undo refund» finds the refund's note by the refund's exact amount
     * (UndoRefund::noteOf). A refund of 7.00 on an invoice paid 4.00 over (line 10.00 lowered to 6.00) now writes a note
     * of 3.00 only, so the undo never finds it. Staff see the mistake, put the line back to 10.00 (it bills 10.00 - 3.00 =
     * 7.00, holds 3.00) and undo the refund: the exact reverse is «the note voided, it bills 10.00 and holds 10.00».
     * Instead the 3.00 note stays active and the undo is refused «3.00 over»; a refund's note is never voided by hand, so
     * the refund cannot be taken back at all. On main the note is 7.00, it is found and voided, and the undo goes through.
     *
     * Should: the undo voids the note the refund wrote, whatever its amount; the invoice bills 10.00 and holds 10.00.
     */
    public function test_rev_793_1_undo_of_a_refund_whose_note_is_smaller_than_the_refund_voids_that_note(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '10.00']);
        $this->pay($invoice, 'bank-1');
        $payment = $invoice->transactions()->sole();
        StaffLineEdit::run($invoice->id, true, fn (Invoice $locked) => $locked->items()->sole()->update(['price' => '6.00']));
        $this->assertSame(-400, RecordPaymentService::toCents($invoice->fresh()->remaining), 'precondition: paid 4.00 over');

        ExtensionHelper::refundLocally($payment, 7.00, notify: false); // a mistake: 4.00 was meant
        StaffLineEdit::run($invoice->id, true, fn (Invoice $locked) => $locked->items()->sole()->update(['price' => '10.00']));

        try {
            (new UndoRefund)->handle($payment->fresh(), UndoRefund::refunds($payment->fresh())->sole()->id);
        } catch (DisplayException $e) {
            $this->fail('the refund cannot be undone, its note (smaller than the refund) is not found and stays: ' . $e->getMessage());
        }

        $fresh = $invoice->fresh();
        $this->assertSame(0, $fresh->adjustmentNotes()->where('is_refund', true)->where('status', AdjustmentNoteStatus::Active->value)->count(), 'the undone refund\'s note is voided');
        $this->assertSame([1000, 1000, 0], [RecordPaymentService::toCents($fresh->total), $fresh->moneyKeptCents(), RecordPaymentService::toCents($fresh->remaining)]);
    }

    /**
     * REV-793-2 (claim 2, the defect of S8-LATEST-FIXES-2 still reachable). The refusal looks only at invoices whose
     * `split_from_id` is the order's invoice and that are not cancelled. A part split off and then MERGED into another
     * open invoice of the customer is left «cancelled, merged into #X» (MergeInvoices), its line living on #X: the
     * refusal does not see it. Order of two services at 10.00: the second is split off and merged into an older open
     * invoice (5.00 of something else); both invoices are paid. «Cancel & Refund» goes ahead, refunds 10.00 of the
     * order's own invoice, cancels both services, and the 10.00 paid for the second service stays on the merged invoice
     * with nobody told.
     *
     * Should: refused as for a split part, or staff told which invoice still holds money of a cancelled service.
     */
    public function test_rev_793_2_a_split_part_merged_into_another_invoice_is_not_kept_silently_by_cancel_and_refund(): void
    {
        $older = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $older->items()->create(['description' => 'Consulting', 'quantity' => 1, 'price' => '5.00']);
        [$order, $own, $services] = $this->order();
        $part = (new SplitInvoice)->handle($own->id, [$this->lineOf($own, $services[1])]);
        (new MergeInvoices)->handle($older->id, [$part->id]);
        $this->assertSame(Invoice::STATUS_CANCELLED, $part->fresh()->status, 'precondition: the split part is «merged into»');
        $this->assertSame($older->id, (int) InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $services[1])->sole()->invoice_id);
        $this->pay($own, 'bank-1');
        $this->pay($older, 'bank-2');

        try {
            $left = CancelOrder::handle($order->fresh(), refund: 'manual');
        } catch (DisplayException $e) {
            $this->assertStringContainsString("#{$older->id}", $e->getMessage());
            $this->assertSame(['active', 'active'], Service::query()->whereKey($services)->orderBy('id')->pluck('status')->all());

            return; // refused whole, naming the invoice: fine
        }

        $kept = Invoice::query()->findOrFail($older->id)->load('transactions')->moneyKeptCents();
        $this->assertTrue($kept <= 500 || self::told($left, $older),
            "service #{$services[1]} is cancelled, invoice #{$older->id} keeps " . number_format($kept / 100, 2) . ' (10.00 of it for that service) and staff were told nothing');
    }

    /**
     * REV-793-3 (claim 2, a split of a split). Only the direct children of the order's invoice are looked at. Order of
     * three services at 10.00 (A, B, C): B and C are split off (#2), then B is split off #2 (#3, `split_from_id` = #2).
     * C is cancelled on its own and its invoice #2 with it. All that is left is #1 (A) and #3 (B), both paid. «Cancel &
     * Refund» finds no part (#2 is cancelled, #3 is not a child of #1), refunds #1, cancels A and B, and #3 keeps 10.00
     * for the cancelled B with nobody told.
     *
     * Should: refused naming #3, or staff told of it.
     */
    public function test_rev_793_3_a_part_split_off_a_split_part_is_not_kept_silently_by_cancel_and_refund(): void
    {
        [$order, $own, $services] = $this->order(3);
        $second = (new SplitInvoice)->handle($own->id, [$this->lineOf($own, $services[1]), $this->lineOf($own, $services[2])]);
        $third = (new SplitInvoice)->handle($second->id, [$this->lineOf($second, $services[1])]);
        $this->assertSame($second->id, (int) $third->split_from_id);
        Service::query()->whereKey($services[2])->update(['status' => Service::STATUS_CANCELLED]);
        $second->update(['status' => Invoice::STATUS_CANCELLED]);
        $this->pay($own, 'bank-1');
        $this->pay($third, 'bank-3');

        try {
            $left = CancelOrder::handle($order->fresh(), refund: 'manual');
        } catch (DisplayException $e) {
            $this->assertStringContainsString("#{$third->id}", $e->getMessage());

            return;
        }

        $kept = Invoice::query()->findOrFail($third->id)->load('transactions')->moneyKeptCents();
        $this->assertSame(Service::STATUS_CANCELLED, Service::query()->findOrFail($services[1])->status);
        $this->assertTrue($kept === 0 || self::told($left, $third),
            "service #{$services[1]} is cancelled, its invoice #{$third->id} (split off a split part) keeps " . number_format($kept / 100, 2) . ' and staff were told nothing');
    }

    /**
     * REV-793-4 (claim 2, low: a false refusal after staff did what the refusal says). The part split off was refunded
     * in full from its page (status «refunded»: it holds nothing and bills nothing). «Cancel & Refund» is still refused
     * «#part bills a part of it», so the order's own invoice can only be refunded by hand too.
     *
     * Should: a refunded part is no longer a part to refuse for (as a cancelled one is not).
     */
    public function test_rev_793_4_a_split_part_refunded_in_full_no_longer_refuses_cancel_and_refund(): void
    {
        [$order, $own, $services] = $this->order();
        $part = (new SplitInvoice)->handle($own->id, [$this->lineOf($own, $services[1])]);
        $this->pay($own, 'bank-1');
        $this->pay($part, 'bank-2');
        ExtensionHelper::refundLocally($part->transactions()->sole(), 10.00, notify: false);
        $this->assertSame(Invoice::STATUS_REFUNDED, $part->fresh()->status);

        try {
            CancelOrder::handle($order->fresh(), refund: 'manual');
        } catch (DisplayException $e) {
            $this->fail('refused for a part already refunded in full: ' . $e->getMessage());
        }

        $this->assertSame(Invoice::STATUS_REFUNDED, $own->fresh()->status);
    }

    /**
     * REV-793-5 (claim 4, introduced by this PR). Coupon::uses says «paid once, it stays a use whatever happened later»,
     * but it reads the invoice's status NOW. An order paid by card with a once-per-client 50% coupon (5.00 for a 10.00
     * plan), delivered, then charged back: the invoice is «unpaid» again; staff cancel the order (the service and its
     * open invoice are cancelled). The use is gone: the same customer orders again with the same once-per-client coupon.
     * On main it is refused «already used».
     *
     * Should: an order that was paid and delivered stays a use after a chargeback.
     */
    public function test_rev_793_5_a_paid_order_charged_back_and_cancelled_still_uses_the_coupon_up(): void
    {
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $product = $this->createProduct()->product;
        $product->update(['autosetup' => Product::AUTOSETUP_PAYMENT, 'server_id' => null]);
        $product = $product->fresh();
        $coupon = Coupon::query()->create(['code' => 'REV793', 'type' => 'percentage', 'value' => 50, 'once_per_client' => true, 'max_uses_per_user' => 1]);
        $place = fn () => (new PlaceOrderService)->place($this->user->client(), 'USD',
            [new OrderLine($product, $product->plans()->firstOrFail()->load('prices'), 1, [])], $coupon->fresh());
        $placed = $place();
        $invoice = Invoice::query()->findOrFail($placed->order->invoice_id);
        $total = (float) $invoice->total;
        ExtensionHelper::addPayment($invoice->id, 'Stripe', $total, transactionId: 'pi_rev793', currency: 'usd');
        $this->assertSame([Invoice::STATUS_PAID, 1], [$invoice->fresh()->status, $coupon->services()->count()], 'precondition: paid, one use');

        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_rev793', 'dp_rev793', $total, 'usd'));
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        CancelOrder::handle($placed->order->fresh());
        $this->assertSame(Service::STATUS_CANCELLED, Service::query()->where('order_id', $placed->order->id)->sole()->status);

        $refused = null;
        try {
            $place();
        } catch (CouponUnusableException $e) {
            $refused = $e->getMessage();
        }

        $this->assertSame(__('You have already used this promotion.'), $refused, 'a once-per-client coupon was taken a second time after a chargeback of the first order');
    }

    /** An order of one service (10.00) and a domain already registered (Active) that belongs to it. */
    private function orderWithRegisteredDomain(): array
    {
        [$order, $own, $services] = $this->order(1);
        $domain = Domain::factory()->create(['client_id' => $this->user->client()->id, 'order_id' => $order->id, 'sld' => 'rev793', 'tld' => 'test',
            'fqdn' => 'rev793.test', 'status' => DomainStatus::Active]);

        return [$order, $own, $domain];
    }

    /**
     * REV-793-6 (claim 1, low). The «registered domain refunded» task is opened whenever «Cancel & Refund» is chosen,
     * also when nothing was refunded: here the order was never paid (0.00 on its invoice). Staff get a task and a notice
     * «the refund of order #N does not undo the registration … keep it (and bill it)» for a refund that never happened;
     * «Cancel Order» on the same order opens none.
     *
     * Should: no «refunded» task when no money went back.
     *
     * Reworded with #801 (owner 2026-11-11): any cancel of an order with a registered domain now opens the one task, so
     * «Cancel Order» and «Cancel & Refund» agree, in ONE text that states nothing about refunds as fact (REV-801-3). What
     * this finding was about still holds and is what is pinned: the task and the notice must not claim a refund that
     * did not happen.
     */
    public function test_rev_793_6_no_refund_is_claimed_when_nothing_was_refunded(): void
    {
        [$order, $own, $domain] = $this->orderWithRegisteredDomain();
        $this->assertSame(0, $own->transactions()->count(), 'precondition: never paid');

        $left = CancelOrder::handle($order->fresh(), refund: 'manual');

        $task = ModuleAction::query()->where('domain_id', $domain->id)->where('action', 'domain_refunded')->sole();
        foreach ([(string) $task->last_error, $left["domain #{$domain->id}"]->getMessage()] as $text) {
            $this->assertStringNotContainsString('the refund of order', $text, 'a refund that never happened is claimed');
            $this->assertStringContainsString("order #{$order->id} was cancelled, and that does not undo the registration (check its invoice for what was refunded)", $text);
        }
    }

    /**
     * REV-793-7 (claim 1, low). The task is created with no look at one already open: «Cancel & Refund» run again on the
     * same order (a second click before the page reloads, a second staff member, the fraud button's twin) opens a second
     * task for the same domain and counts it twice in «domains to check».
     *
     * Should: one open task per domain.
     */
    public function test_rev_793_7_a_second_cancel_and_refund_opens_no_second_task(): void
    {
        [$order, $own, $domain] = $this->orderWithRegisteredDomain();
        $this->pay($own, 'bank-1');

        CancelOrder::handle($order->fresh(), refund: 'manual');
        CancelOrder::handle($order->fresh(), refund: 'manual');

        $this->assertLessThanOrEqual(1, ModuleAction::query()->where('domain_id', $domain->id)->where('action', 'domain_refunded')->where('status', ModuleAction::STATUS_OPEN)->count(),
            'two open tasks for one domain');
    }

    /**
     * REV-793-8 (claim 4, low). «Never billed» is read as «a free trial, delivered»: a cancelled service with NO invoice
     * line is always a use. An order with a 50% coupon lapses unpaid (not a use, as the PR says); staff then delete its
     * cancelled invoice to tidy up. The service has no invoice any more, so it is a use again and the promotion is burnt
     * for an order nobody paid: S8-CLIENT-PORTAL-3 back, by a deletion that has nothing to do with the coupon.
     *
     * Should: deleting the cancelled invoice of an order never paid does not turn it into a use.
     */
    public function test_rev_793_8_deleting_the_cancelled_invoice_of_a_never_paid_order_does_not_use_the_coupon_up(): void
    {
        $this->markTestIncomplete('REV-793-8 accepted (2026-11-11): rare, a staff action, and it errs on the safe side (the coupon stays used).');

        $product = $this->createProduct()->product;
        $coupon = Coupon::query()->create(['code' => 'REV793B', 'type' => 'percentage', 'value' => 50, 'max_uses' => 1, 'once_per_client' => true]);
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD',
            [new OrderLine($product, $product->plans()->firstOrFail()->load('prices'), 1, [])], $coupon);
        CancelOrder::handle($placed->order->fresh());
        $invoice = Invoice::query()->findOrFail($placed->order->invoice_id);
        $this->assertSame([Invoice::STATUS_CANCELLED, 0], [$invoice->status, $coupon->uses()->count()], 'precondition: lapsed unpaid, not a use');

        $invoice->delete();

        $this->assertSame(0, $coupon->uses()->count(), 'the never-paid order became a use when its cancelled invoice was deleted');
    }
}
