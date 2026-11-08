<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial verification, flow f-renewal-cycle (sweep 2). Fake data only; the real cron (`app:cron-job`) day by day.
 */
class Sweep2FRenewalCycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-01-09 09:00:00');
        Queue::fake();
        Mail::fake();
        // The defaults, written out: Suspend Days 8, Termination Days 60, Invoice Generation 7, «requires invoice» off.
        config(['settings.cronjob_order_suspend' => 8, 'settings.cronjob_order_terminate' => 60, 'settings.cronjob_terminate_min_suspended_days' => 1,
            'settings.cronjob_invoice' => 7, 'settings.cronjob_suspend_requires_invoice' => false,
            'settings.cronjob_suspend_enabled' => true, 'settings.cronjob_terminate_enabled' => true]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $expires, array $attributes = [], bool $addon = false): Service
    {
        $product = $this->createProduct();
        if ($addon) {
            $product->product->update(['name' => 'Extra IPv4', 'is_addon' => true]);
        }

        return Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => $addon ? 3.00 : 10.00, 'quantity' => 1,
            ...$attributes,
        ]);
    }

    private function cronUntil(string $last): void
    {
        while (Carbon::today()->lessThanOrEqualTo(Carbon::parse($last))) {
            $this->artisan('app:cron-job')->assertExitCode(0);
            Carbon::setTestNow(Carbon::now()->addDay());
        }
    }

    /** The addon's invoice lines (any invoice, any status). */
    private function addonLines(Service $addon)
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $addon->id)->get();
    }

    /**
     * F-RENEWAL-CYCLE-1, under the rule that replaced its fix (owner 2026-11-08, docs/addons.md): an addon shares its
     * parent's due date, so it is on its parent's invoice. Suspended with its parent, it is not billed on its own; the
     * customer pays that invoice, and both come back together, paid to the same day, and renew on one invoice again.
     * (Sweep 2 had the addon on its own date, Jan 20, and billed it while suspended: #723, removed with the own date.)
     */
    public function test_f1_an_addon_suspended_with_its_parent_follows_it_on_its_invoice(): void
    {
        [$parent, $addon, $invoice] = $this->f1Setup();

        // Feb 5: the customer pays the invoice of both.
        (new RecordPaymentService)->handle($invoice->fresh(), null, $invoice->fresh()->remaining, transactionId: 'f1');
        $this->assertSame(Service::STATUS_ACTIVE, $parent->fresh()->status, 'the parent is back');
        $this->assertSame(Service::STATUS_ACTIVE, $addon->fresh()->status, 'the addon with it: its period was on the same invoice');
        $this->assertSame($parent->fresh()->expires_at->toDateString(), $addon->fresh()->expires_at->toDateString(), 'paid to the same day');

        $this->cronUntil('2027-02-06');

        $next = $this->addonLines($addon)->firstWhere(fn ($line) => $line->period_start->toDateString() === '2027-02-01');
        $this->assertNotNull($next, 'its next period is billed');
        $this->assertTrue($next->invoice->items()->where('reference_id', $parent->id)->exists(), 'on its parent\'s invoice');
        $this->assertNotSame(Service::STATUS_TERMINATED, $addon->fresh()->status);
    }

    /** F-RENEWAL-CYCLE-1, unpaid: the invoice of both left unpaid, the addon ends with its parent, billed only on its invoice. */
    public function test_f1_an_addon_suspended_with_its_unpaid_parent_ends_with_it(): void
    {
        [$parent, $addon, $invoice] = $this->f1Setup();

        $this->cronUntil('2027-03-25');

        $this->assertSame(Service::STATUS_TERMINATED, $parent->fresh()->status, 'past Termination Days on its unpaid invoice');
        $this->assertSame(Service::STATUS_TERMINATED, $addon->fresh()->status, 'the addon with it');
        $this->assertSame([], $this->addonLines($addon)->where('invoice_id', '!=', $invoice->id)->pluck('invoice_id')->all(),
            'billed only on its parent\'s invoice, never on its own while suspended');
    }

    /**
     * Parent and addon both due Jan 1 and unpaid, on one invoice (as the cron bills them); the cron to Feb 4: the parent
     * suspended for it, the addon with it (reason «parent»), and nothing more billed for the addon.
     *
     * @return array{Service, Service, Invoice}
     */
    private function f1Setup(): array
    {
        $parent = $this->service('2027-01-01 00:00:00');
        $addon = $this->service('2027-01-01 00:00:00', ['parent_service_id' => $parent->id], addon: true);
        $invoice = Invoice::factory()->create(['client_id' => $parent->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => '2027-01-01', 'created_at' => '2026-12-25 09:00:00']);
        foreach ([[$parent, 10.00], [$addon, 3.00]] as [$service, $price]) {
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
                'quantity' => 1, 'price' => $price, 'period_start' => '2027-01-01', 'period_end' => '2027-02-01']);
        }

        $this->cronUntil('2027-02-04');
        $this->assertSame(Service::STATUS_SUSPENDED, $parent->fresh()->status, 'the parent is suspended for its unpaid invoice');
        $this->assertSame(Service::STATUS_SUSPENDED, $addon->fresh()->status, 'the addon with it');
        $this->assertSame(Service::SUSPEND_REASON_PARENT, $addon->fresh()->suspend_reason);
        $this->assertCount(1, $this->addonLines($addon), 'not billed on its own while suspended with its parent');

        return [$parent, $addon, $invoice];
    }

    /**
     * F-RENEWAL-CYCLE-2. A period-less open line of the service (as the importer gives every WHMCS «Hosting» line), due
     * months before its expiry (staff had moved WHMCS's nextduedate past it): the renewal at the expiry is never billed,
     * and the service is suspended past Suspend Days with no invoice for that period.
     */
    public function test_f2_a_stale_period_less_open_line_blocks_the_renewal_at_the_expiry(): void
    {
        // Run it with SWEEP2_F2=1 once the owner decides.
        if (!getenv('SWEEP2_F2')) {
            $this->markTestSkipped('F-RENEWAL-CYCLE-2 left open (low): an open period-less imported line counts as the renewal billed, by design '
                . '(ImportFromWhmcs::serviceLineReference, CronJob::periodUnbilled); stale WHMCS invoices are a pre-cut-over cleanup, the owner decides.');
        }

        Carbon::setTestNow('2027-01-05 09:00:00');
        $service = $this->service('2027-01-15 00:00:00');
        $stale = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => '2026-06-15', 'created_at' => '2026-06-08 09:00:00']);
        $stale->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Hosting (WHMCS)',
            'quantity' => 1, 'price' => 10.00, 'period_start' => null, 'period_end' => null, 'kind' => null]);

        $this->cronUntil('2027-01-25');

        $periodLines = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->whereNotNull('period_start')->count();
        $this->assertGreaterThan(0, $periodLines, 'the Jan 15 renewal is invoiced');
    }
}
