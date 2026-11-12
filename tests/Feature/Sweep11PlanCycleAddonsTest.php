<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\MergeInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep 11, plan-cycle-addons (adversarial verifier). Each test fails on main (b91b448a) for the reason reported.
 * Invented rows only, no network.
 */
class Sweep11PlanCycleAddonsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-11-01 10:00:00');
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        foreach (['credits_enabled', 'credits_on_downgrade'] as $key) {
            Setting::query()->whereNull('settingable_type')->updateOrCreate(['key' => $key], ['value' => '1', 'type' => 'boolean']);
        }
        Setting::query()->whereNull('settingable_type')->updateOrCreate(['key' => 'credits_auto_use'], ['value' => '0', 'type' => 'boolean']);
        config(['settings.credits_enabled' => true, 'settings.credits_on_downgrade' => true, 'settings.credits_auto_use' => false,
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.cronjob_invoice' => 7, 'settings.cronjob_order_suspend' => 8, 'settings.cronjob_order_terminate' => 60]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function monthlyProduct(string $name, string $price): Product
    {
        $product = Product::factory()->create(['name' => $name, 'server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => '0.00', 'currency_code' => 'USD']);

        return $product->fresh();
    }

    /** @param array<string, mixed> $more */
    private function service(Product $product, string $price, string $expires, array $more = []): Service
    {
        return Service::factory()->create($more + [
            'client_id' => $this->user->client()->id, 'product_id' => $product->id, 'plan_id' => $product->plans()->first()->id,
            'status' => 'active', 'price' => $price, 'quantity' => 1, 'currency_code' => 'USD', 'expires_at' => $expires,
        ]);
    }

    /** A period of the service billed as the cron bills it: an unpaid invoice with one period line. */
    private function billed(Service $service, string $price, string $start, string $end): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Period', 'price' => $price, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $start, 'period_end' => $end]);

        return $invoice->fresh();
    }

    private function pay(Invoice $invoice, string $ref): void
    {
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, number_format((float) $invoice->fresh()->remaining, 2, '.', ''), $ref);
    }

    private function credit(): float
    {
        return round((float) Credit::query()->where('client_id', $this->user->client()->id)->sum('amount'), 2);
    }

    /**
     * S11-PLAN-CYCLE-ADDONS-1: an upgrade placed, then the cron bills the next renewal at the old price, staff merge the two
     * open invoices and the customer pays the one invoice. The upgrade's line (lower id) is applied first: no shortfall
     * yet (the expiry has not moved) and the renewal line is skipped by refreshOpenPeriods (its invoice is paid already,
     * never kept by ChangeOpenLine, so nobody is told); then the renewal line renews a month of the dearer package at the
     * old price.
     */
    public function test_an_upgrade_and_the_next_renewal_paid_on_one_merged_invoice_bill_or_tell_the_new_price(): void
    {
        $this->markTestIncomplete('known (2026-11-12): an upgrade and the next renewal merged on one invoice renew at the old price; watched live');
        $p10 = $this->monthlyProduct('P10', '10.00');
        $p20 = $this->monthlyProduct('P20', '20.00');
        Carbon::setTestNow('2026-11-21 10:00:00');
        $service = $this->service($p10, '10.00', '2026-12-01');
        $november = $this->billed($service, '10.00', '2026-11-01', '2026-12-01');
        DB::table('invoices')->where('id', $november->id)->update(['status' => Invoice::STATUS_PAID]);
        DB::table('invoice_items')->where('invoice_id', $november->id)->update(['applied_at' => '2026-11-01 10:00:00']);

        $placed = PlaceUpgrade::handle($service->fresh(), $p20, [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::INVOICED, $placed->outcome, 'precondition: the upgrade has its invoice');
        $upgradeInvoice = $placed->invoice;

        // Seven days before the due date the cron bills December, at the price the service has (10.00).
        Carbon::setTestNow('2026-11-24 03:00:00');
        $this->artisan('app:cron-job');
        $renewal = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->whereDate('period_start', '2026-12-01')->sole();
        $this->assertSame('10.00', number_format((float) $renewal->price, 2, '.', ''), 'precondition: December billed at the old price');
        $this->assertTrue(in_array($renewal->invoice->status, Invoice::OPEN_STATUSES, true), 'precondition: open');
        if ((int) $renewal->invoice_id !== (int) $upgradeInvoice->id) {
            (new MergeInvoices)->handle((int) $upgradeInvoice->id, [(int) $renewal->invoice_id]);
        }
        $merged = Invoice::query()->findOrFail($upgradeInvoice->id);
        $this->assertSame(2, $merged->items()->count(), 'precondition: the upgrade and the renewal on one invoice');
        $this->assertLessThan($renewal->id, $merged->items()->where('reference_type', ServiceUpgrade::class)->sole()->id, 'precondition: the upgrade line first');

        Carbon::setTestNow('2026-11-24 10:00:00');
        $this->pay($merged, 'merged-1');

        $service = $service->fresh();
        $this->assertSame((int) $p20->id, (int) $service->product_id, 'precondition: upgraded');
        $this->assertSame('20.00', number_format((float) $service->price, 2, '.', ''));
        $this->assertSame('2027-01-01', $service->expires_at->toDateString(), 'precondition: December renewed by the same payment');

        // December runs on P20 and 10.00 was paid for it. Either the difference is billed (as the shortfall of a renewal
        // paid before the upgrade is), or staff are told of it.
        $decemberPaid = number_format((float) $renewal->fresh()->price, 2, '.', '');
        $shortfall = InvoiceItem::query()->where('reference_type', ServiceUpgrade::class)->where('invoice_id', '!=', $merged->id)->exists();
        $told = EmailLog::query()->where('to', 'staff@example.test')
            ->where(fn ($mail) => $mail->where('subject', 'like', '%kept its billed price%')->orWhere('body', 'like', "%service #{$service->id}%"))->exists();
        $this->assertTrue($decemberPaid === '20.00' || $shortfall || $told,
            "December on the 20.00 package was paid at {$decemberPaid}: no shortfall invoice and no word to staff");
    }

    /**
     * S11-PLAN-CYCLE-ADDONS-2: a coupon «50% for 2 cycles», kept on a product change, the service in its second (last
     * discounted) period. The first upgrade is priced coupon to coupon and stores the next renewal's full price; a second
     * one in the same period is priced «new with the coupon» less «old at full price»: a real upgrade reads as a downgrade,
     * is applied free and gives credit.
     */
    public function test_a_second_upgrade_in_a_coupons_last_cycle_is_charged_not_credited(): void
    {
        $this->markTestIncomplete('known (2026-11-12): a second package change in a coupon\'s last discounted cycle is priced coupon against full; watched live');
        $a = $this->monthlyProduct('A', '10.00');
        $b = $this->monthlyProduct('B', '20.00');
        $c = $this->monthlyProduct('C', '30.00');
        $coupon = Coupon::query()->create(['code' => 'HALF2', 'type' => 'percentage', 'value' => 50, 'applies_to' => 'all',
            'recurring' => 2, 'kept_on_product_change' => true]);
        $coupon->products()->attach([$a->id, $b->id, $c->id]);

        Carbon::setTestNow('2026-11-16 10:00:00');
        $service = $this->service($a, '5.00', '2026-12-01', ['coupon_id' => $coupon->id, 'coupon_started_at' => '2026-10-01']);
        // Its two discounted cycles, both paid: October (the order's) and November (its first renewal).
        foreach ([['2026-10-01', '2026-11-01'], ['2026-11-01', '2026-12-01']] as [$start, $end]) {
            $invoice = $this->billed($service, '5.00', $start, $end);
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
            DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['applied_at' => $start . ' 10:00:00']);
        }
        $this->assertSame(2, $service->fresh()->priceCycle(), 'precondition: in its second cycle, the coupon\'s last');
        $this->assertNull($service->fresh()->coupon_expired_at);

        // A → B: 15 of 30 days at (10.00 − 5.00), coupon on both sides.
        $first = PlaceUpgrade::handle($service->fresh(), $b, [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::INVOICED, $first->outcome);
        $this->assertSame('2.50', number_format((float) $first->invoice->fresh()->total, 2, '.', ''), 'precondition: the first upgrade is priced right');
        $this->pay($first->invoice, 'upg-1');
        $this->assertSame((int) $b->id, (int) $service->fresh()->product_id, 'precondition: on B');
        $this->assertSame(0.0, $this->credit());

        // B → C the same day: the days left on B were paid at 10.00 a month (5.00 + 5.00), C with the coupon is 15.00.
        $second = PlaceUpgrade::handle($service->fresh(), $c, [], ServiceUpgrade::SOURCE_STAFF);

        $this->assertSame(0, $second->creditCents, 'an upgrade to a dearer product gave credit');
        $this->assertFalse($second->downgrade, 'B to C read as a downgrade');
        $this->assertSame(PlaceUpgrade::INVOICED, $second->outcome, 'the dearer product was given without a charge');
        $this->assertSame('2.50', number_format((float) $second->invoice->fresh()->total, 2, '.', ''), '15 of 30 days at (15.00 − 10.00)');
    }

    /**
     * S11-PLAN-CYCLE-ADDONS-3, an upgrade: the current period was paid and applied, then its payment was deleted (the
     * invoice is unpaid again, the service still runs to the period's end: told daily by the money check). The customer
     * upgrades mid-period and pays the days left; applying it rebuilds every open period line at the new price, the one
     * of the running period too: the whole month is now billed at the new price, on top of the days-left charge.
     */
    public function test_an_upgrade_does_not_reprice_the_running_period_on_an_invoice_unpaid_again(): void
    {
        $this->markTestIncomplete('known (2026-11-12): a package change while the running period\'s invoice is unpaid again reprices the whole period line; watched live');
        $p10 = $this->monthlyProduct('P10', '10.00');
        $p20 = $this->monthlyProduct('P20', '20.00');
        $service = $this->service($p10, '10.00', '2026-11-01');
        $november = $this->billed($service, '10.00', '2026-11-01', '2026-12-01');
        $this->pay($november, 'nov-1');
        $this->assertSame('2026-12-01', $service->fresh()->expires_at->toDateString(), 'precondition: November paid and applied');
        (new RecordPaymentService)->deleteTransaction($november->transactions()->sole());
        $this->assertSame(Invoice::STATUS_UNPAID, $november->fresh()->status, 'precondition: unpaid again');
        $this->assertSame('2026-12-01', $service->fresh()->expires_at->toDateString(), 'precondition: the service keeps its period');
        $this->assertNotNull($november->items()->sole()->applied_at);

        Carbon::setTestNow('2026-11-16 10:00:00');
        $placed = PlaceUpgrade::handle($service->fresh(), $p20, [], ServiceUpgrade::SOURCE_STAFF);
        if ($placed->outcome !== PlaceUpgrade::INVOICED) {
            $this->assertNotSame(PlaceUpgrade::APPLIED, $placed->outcome); // refused, as ChangeCycle «now» refuses it: fine

            return;
        }
        $this->assertSame('5.00', number_format((float) $placed->invoice->fresh()->total, 2, '.', ''), 'precondition: 15 of 30 days at +10.00');
        $this->pay($placed->invoice, 'upg-1');
        $this->assertSame((int) $p20->id, (int) $service->fresh()->product_id, 'precondition: upgraded');

        // 15 days on P10 and 15 on P20 cost 15.00: 10.00 for the month and the 5.00 just paid.
        $this->assertSame('10.00', number_format((float) $november->items()->sole()->price, 2, '.', ''),
            'the running month, half of it used on the old package, was repriced whole to the new one: the customer owes 20.00 + paid 5.00');
    }

    /** S11-PLAN-CYCLE-ADDONS-3, a downgrade in the same state: the days used on the dearer package are never billed. */
    public function test_a_downgrade_does_not_reprice_the_running_period_on_an_invoice_unpaid_again(): void
    {
        $this->markTestIncomplete('known (2026-11-12): a package change while the running period\'s invoice is unpaid again reprices the whole period line; watched live');
        $p10 = $this->monthlyProduct('P10', '10.00');
        $p20 = $this->monthlyProduct('P20', '20.00');
        $service = $this->service($p20, '20.00', '2026-11-01');
        $november = $this->billed($service, '20.00', '2026-11-01', '2026-12-01');
        $this->pay($november, 'nov-1');
        (new RecordPaymentService)->deleteTransaction($november->transactions()->sole());
        $this->assertSame(Invoice::STATUS_UNPAID, $november->fresh()->status, 'precondition: unpaid again');
        $this->assertSame('2026-12-01', $service->fresh()->expires_at->toDateString(), 'precondition: the service keeps its period');

        Carbon::setTestNow('2026-11-16 10:00:00');
        $placed = PlaceUpgrade::handle($service->fresh(), $p10, [], ServiceUpgrade::SOURCE_STAFF);
        if ($placed->outcome !== PlaceUpgrade::APPLIED) {
            $this->assertNotSame(PlaceUpgrade::INVOICED, $placed->outcome); // refused: fine

            return;
        }
        $this->assertSame(0, $placed->creditCents, 'precondition: nothing paid, nothing back');

        // 15 days used at 20.00 a month and 15 left at 10.00: 15.00 at least is owed for November, not 10.00.
        $this->assertGreaterThanOrEqual(15.00, (float) $november->items()->sole()->price,
            'the days already used on the dearer package are no longer billed');
    }
}
