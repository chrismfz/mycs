<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Money\MoneyTrail;
use App\Services\Payment\ItemPaymentMethod;
use App\Services\ServiceUpgrade\ChangeCycle;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Third independent review (adversarial, money flow) of «a cycle change now moves the addons with it» (owner 2026-11-08,
 * commits 21e1ee77 and eccfa7ed). Each test fails on the branch as reviewed. Fake data only.
 */
class Rev3KissAddonsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        config(['settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true,
            'settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(Product $product, string $unit, float $price): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => ucfirst($unit) . 'ly',
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $plan->fresh();
    }

    private function service(Product $product, Plan $plan, string $expires, float $price, array $attributes = []): Service
    {
        return Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $plan->id, 'product_id' => $product->id,
            'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => $price, 'quantity' => 1, ...$attributes,
        ]);
    }

    private function line(Invoice $invoice, Service $service, float $price, string $start, string $end): InvoiceItem
    {
        return $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => $price, 'period_start' => $start, 'period_end' => $end]);
    }

    /** A yearly hosting and its yearly addon, both paid to 2027-01-01 on one invoice, and their next renewal open on one. */
    private function family(): array
    {
        Carbon::setTestNow('2026-12-27 10:00:00');
        $hosting = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($hosting, 'year', 120.00);
        $monthly = $this->plan($hosting, 'month', 12.00);
        $ip = Product::factory()->create(['name' => 'Extra IPv4', 'is_addon' => true]);
        $ipYearly = $this->plan($ip, 'year', 30.00);
        $parent = $this->service($hosting, $yearly, '2027-01-01', 120.00);
        $addon = $this->service($ip, $ipYearly, '2027-01-01', 30.00, ['parent_service_id' => $parent->id]);
        $paid = Invoice::factory()->create(['client_id' => $parent->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $this->line($paid, $parent, 120.00, '2026-01-01', '2027-01-01');
        $this->line($paid, $addon, 30.00, '2026-01-01', '2027-01-01');
        DB::table('invoices')->where('id', $paid->id)->update(['status' => Invoice::STATUS_PAID]);
        $renewal = Invoice::factory()->create(['client_id' => $parent->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $this->line($renewal, $parent, 120.00, '2027-01-01', '2028-01-01');
        $this->line($renewal, $addon, 30.00, '2027-01-01', '2028-01-01');

        return [$parent, $addon, $monthly, $renewal];
    }

    /**
     * REV3-KISS-1 (medium; the parent alone had it before, the addons widen it). The refusal reads «no payment on the open
     * renewal» before CancelOpenInvoices takes the customer and invoice locks (apply locks only the services first, and a
     * payment, a credit auto-apply or a cron finish takes customer → invoice, never a service). A payment of the shared
     * renewal (2027-01-01 → 2028-01-01, the parent's and the addon's old year) that commits in that window is found PAID by
     * CancelOpenInvoices::cancel, which returns null («paid in the meantime: nothing to give up on»): apply goes on, moves
     * both to the monthly cycle, credits their unused days and bills a new period from today, while the old-cycle year
     * stays paid (its RenewServiceService runs after the payment's commit, once the service locks are free). Paid twice.
     * Here the payment lands at the moment CancelOpenInvoices first reads the invoice (as a second connection would on
     * MariaDB). Expected: the change is refused (or rolled back) when an invoice it was to cancel was paid meanwhile, e.g.
     * by taking the customer's lock before the refusal's reads.
     */
    public function test_rev3_kiss_1_a_renewal_paid_after_the_refusal_stops_the_change(): void
    {
        [$parent, $addon, $monthly, $renewal] = $this->family();
        $this->assertNull(ChangeCycle::refusal($parent->fresh(), $monthly, ChangeCycle::NOW), 'allowed: nothing paid on the renewal yet');

        $armed = true;
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$armed, $renewal) {
            // RecordPaymentService::lockInvoice's first read: «select client_id from invoices where id = ?» (either quote style).
            if (!$armed || !preg_match('/^select [`"]client_id[`"] from [`"]invoices[`"]/i', $query->sql)) {
                return;
            }
            $armed = false;
            // The customer's payment of the renewal, committed just before the change's invoice lock.
            DB::table('invoices')->where('id', $renewal->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => now()]);
            DB::table('invoice_transactions')->insert(['invoice_id' => $renewal->id, 'amount' => 150.00, 'status' => InvoiceTransactionStatus::Succeeded->value,
                'transaction_id' => 'bank-rev3-1', 'is_credit_transaction' => false, 'created_at' => now(), 'updated_at' => now()]);
        });

        $refused = null;
        try {
            ChangeCycle::apply($parent->fresh(), $monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
        } catch (DisplayException $e) {
            $refused = $e->getMessage();
        }

        $this->assertFalse($armed, 'the payment landed inside the window');
        $this->assertSame(Invoice::STATUS_PAID, $renewal->fresh()->status);
        $this->assertNotNull($refused, 'the old-cycle year was paid meanwhile: no change «now» on top of it');
        $this->assertFalse(InvoiceItem::query()->where('reference_type', Service::class)->whereIn('reference_id', [$parent->id, $addon->id])
            ->whereDate('period_start', '2026-12-27')->exists(), 'no new period billed over the paid old-cycle year');
        $this->assertSame('0.00', number_format((float) Credit::query()->sum('amount'), 2, '.', ''), 'no credit for days the paid year goes on to cover');
    }

    /**
     * REV3-KISS-2 (low). The customer set «Do not charge automatically» on the addon only (#568). The cron bills such an
     * item on an invoice apart (RenewalBilling: key «|k»), so the parent's renewal is still charged to the saved method.
     * The cycle change «now» puts the addon's new period on the parent's invoice: AutoCharge::itemKeptFromIt then holds
     * the whole invoice, the parent's new period too (and an addon with its own payment method makes the ways mixed: no
     * method charges it either). Expected, as the cron: the parent's new invoice holds no line kept from the automatic
     * charge (the addon's goes apart), or the change says so.
     */
    public function test_rev3_kiss_2_an_addon_kept_from_auto_charge_does_not_hold_its_parents_new_invoice(): void
    {
        [$parent, $addon, $monthly, $renewal] = $this->family();
        DB::table('invoices')->where('id', $renewal->id)->update(['status' => Invoice::STATUS_CANCELLED]); // not billed yet
        $addon->forceFill(['no_auto_charge' => true])->save();

        $invoice = ChangeCycle::apply($parent->fresh(), $monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertNotNull($invoice);
        $this->assertTrue($invoice->items()->where('reference_type', Service::class)->where('reference_id', $parent->id)->exists());
        $this->assertFalse(InvoiceItem::query()->where('invoice_id', $invoice->id)->where(fn ($lines) => ItemPaymentMethod::kept($lines))->exists(),
            'the parent\'s new period is not kept from the automatic charge by its addon\'s own «Do not charge automatically»');
    }
}
