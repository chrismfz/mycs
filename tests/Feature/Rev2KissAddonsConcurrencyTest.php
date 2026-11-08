<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Second independent review of commit 21e1ee77 (a cycle change «now» moves the addons with it), MariaDB only: the
 * snapshot a transaction reads in. Fake data only.
 */
class Rev2KissAddonsConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Snapshot test requires MariaDB/MySQL (REPEATABLE READ).');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateDatabaseTables());
        Queue::fake();
        Mail::fake();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
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

    /**
     * REV2-KISS-2 (medium). ChangeCycle::apply now opens its transaction with a plain read (`value('parent_service_id')`)
     * before it locks the service. On MariaDB (REPEATABLE READ) that first consistent read fixes the transaction's snapshot:
     * every unlocked read after the lock (refusal's «a renewal is paid ahead», «an open invoice has a payment», the paid
     * lines the credit is counted from, the open invoices it cancels) sees the database as it was before the lock, not
     * what a payment committed while it waited. Before 21e1ee77 the first statement was the locking read, so the snapshot
     * came after the lock. Here a payment of the next renewal (2027-01-01 → 2028-01-01) commits right after that first
     * read: the branch does not see it, changes the cycle «now» and leaves a paid year of the old cycle behind (B1:
     * refused when read after the lock). Expected: refused, «A renewal is paid ahead».
     */
    public function test_rev2_kiss_2_a_payment_committed_before_the_lock_is_seen_by_the_cycle_change(): void
    {
        Carbon::setTestNow('2026-12-27 10:00:00');
        $user = User::factory()->create();
        $hosting = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($hosting, 'year', 120.00);
        $monthly = $this->plan($hosting, 'month', 12.00);
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $yearly->id, 'product_id' => $hosting->id,
            'status' => 'active', 'expires_at' => '2027-01-01', 'currency_code' => 'USD', 'price' => 120.00, 'quantity' => 1]);
        $paid = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $paid->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 120.00, 'period_start' => '2026-01-01', 'period_end' => '2027-01-01']);
        DB::table('invoices')->where('id', $paid->id)->update(['status' => Invoice::STATUS_PAID]);
        $renewal = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $renewal->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 120.00, 'period_start' => '2027-01-01', 'period_end' => '2028-01-01']);
        $this->assertNull(ChangeCycle::refusal($service->fresh(), $monthly, ChangeCycle::NOW));

        // Another process: the renewal's payment commits once the change's transaction has made its first read.
        config(['database.connections.rev2_other' => config('database.connections.' . config('database.default'))]);
        $done = false;
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$done, $renewal) {
            if ($done || $query->connectionName === 'rev2_other' || DB::transactionLevel() === 0) {
                return;
            }
            $done = true;
            DB::connection('rev2_other')->table('invoices')->where('id', $renewal->id)->update(['status' => Invoice::STATUS_PAID]);
        });

        try {
            ChangeCycle::apply($service->fresh(), $monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
            $refused = null;
        } catch (DisplayException $e) {
            $refused = $e->getMessage();
        } finally {
            DB::purge('rev2_other');
        }

        $this->assertTrue($done, 'the payment committed inside the window');
        $this->assertSame(Invoice::STATUS_PAID, $renewal->fresh()->status);
        $this->assertSame(__('A renewal is paid ahead: change it from the next renewal.'), $refused,
            'the paid renewal committed before the lock is seen: no change «now» over a paid year of the old cycle');
        $this->assertSame((int) $yearly->id, (int) $service->fresh()->plan_id);
        $this->assertFalse(InvoiceItem::query()->where('reference_id', $service->id)->whereDate('period_start', '2026-12-27')->exists());
    }
}
