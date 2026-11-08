<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Second independent review (code + adversarial) of «a cycle change now moves the addons with it» (owner 2026-11-08,
 * commit 21e1ee77). Each test fails on the branch as reviewed. Fake data only.
 */
class Rev2KissAddonsTest extends TestCase
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

    /**
     * REV2-KISS-1 (high). The parent and its addon renew on one invoice (that is the rule). The next renewal of both,
     * 2027-01-01 → 2028-01-01 (120.00 + 30.00), is billed and 40.00 of the customer's credit sits on it (allowed: credit
     * only, on an invoice shared with other services). Staff change the parent's cycle «now»: ChangeCycle cancels the
     * parent's part first (CancelOpenInvoices, partial: the addon's line is «another service»), and the credit on it, more
     * than the 30.00 left, pays the addon's old-cycle renewal: the invoice is PAID, the addon renewed a year ahead on its old
     * cycle. Then the addon's own pass finds no open invoice, and the addon is billed its new period too. Expected: the
     * addon follows, so its old-cycle renewal is cancelled with the parent's and the credit goes back.
     */
    public function test_rev2_kiss_1_the_shared_renewal_with_credit_is_not_paid_for_the_following_addon(): void
    {
        Carbon::setTestNow('2026-12-27 10:00:00');
        $hosting = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($hosting, 'year', 120.00);
        $monthly = $this->plan($hosting, 'month', 12.00);
        $ip = Product::factory()->create(['name' => 'Extra IPv4', 'is_addon' => true]);
        $ipYearly = $this->plan($ip, 'year', 30.00);
        $parent = $this->service($hosting, $yearly, '2027-01-01', 120.00);
        $addon = $this->service($ip, $ipYearly, '2027-01-01', 30.00, ['parent_service_id' => $parent->id]);
        foreach ([[$parent, 120.00], [$addon, 30.00]] as [$service, $price]) {
            $paid = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
            $this->line($paid, $service, $price, '2026-01-01', '2027-01-01');
            DB::table('invoices')->where('id', $paid->id)->update(['status' => Invoice::STATUS_PAID]);
        }
        $renewal = Invoice::factory()->create(['client_id' => $parent->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $this->line($renewal, $parent, 120.00, '2027-01-01', '2028-01-01');
        $this->line($renewal, $addon, 30.00, '2027-01-01', '2028-01-01');
        (new CreditLedger)->add((int) $parent->client_id, 'USD', 4000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'test');
        (new RecordPaymentService)->payWithCredit($renewal->id, upToCents: 4000);
        $this->assertSame(Invoice::STATUS_UNPAID, $renewal->fresh()->status, 'partly paid by credit');
        $this->assertNull(ChangeCycle::refusal($parent->fresh(), $monthly, ChangeCycle::NOW), 'the branch allows it');

        ChangeCycle::apply($parent->fresh(), $monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertFalse(InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $addon->id)
            ->whereDate('period_start', '2027-01-01')->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_PAID))->exists(),
            'the addon\'s old-cycle year is not paid by the credit: it follows its parent, its old renewal goes');
        $this->assertNotSame(Invoice::STATUS_PAID, $renewal->fresh()->status);
    }
}
