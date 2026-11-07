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
     * F-RENEWAL-CYCLE-1. Parent due Jan 1, unpaid; its addon has its own date, Jan 20 (as WHMCS imports one). The parent is
     * suspended Jan 10 and the addon with it (reason «parent», no dunning date). The addon's renewal falls due while it is
     * suspended with its parent: not billed (suspended, never paid since a dunning suspension). The customer pays the
     * parent Feb 5: the parent comes back, the addon is past its own Suspend Days, so it stays suspended «Overdue on
     * Payment», with no invoice ever made for it. Then nothing bills it, and Termination Days on it is terminated.
     *
     * Expected: the addon is billed for its period at some point (something to pay before it is suspended/terminated
     * for non-payment). Actual: never billed, and terminated.
     */
    public function test_f1_an_addon_with_its_own_date_suspended_with_its_parent_is_billed_before_being_terminated(): void
    {
        [$parent, $addon, $invoice] = $this->f1Setup();

        // Feb 5: the customer pays the parent, then the addon's own invoice.
        (new RecordPaymentService)->handle($invoice->fresh(), null, $invoice->fresh()->remaining, transactionId: 'f1-parent');
        $this->assertSame(Service::STATUS_ACTIVE, $parent->fresh()->status, 'the parent is back');
        $this->assertSame(Service::STATUS_SUSPENDED, $addon->fresh()->status, 'the addon stays suspended, overdue on its own');
        $this->assertNotNull($addon->fresh()->dunning_suspended_at);
        $own = $this->addonLines($addon)->first()->invoice;
        (new RecordPaymentService)->handle($own->fresh(), null, $own->fresh()->remaining, transactionId: 'f1-addon');
        $this->assertSame(Service::STATUS_ACTIVE, $addon->fresh()->status, 'its own invoice paid, it is back');
        $this->assertSame('2027-02-20', $addon->fresh()->expires_at->toDateString());

        $this->cronUntil('2027-03-25');

        $this->assertNotSame(Service::STATUS_TERMINATED, $addon->fresh()->status,
            'an addon never invoiced is terminated for non-payment, though the customer paid all they were billed');
    }

    /** F-RENEWAL-CYCLE-1, unpaid: the addon's own invoice left unpaid, it is terminated for that invoice, which it had. */
    public function test_f1_an_addon_billed_with_its_parent_suspended_and_left_unpaid_is_terminated_for_its_own_invoice(): void
    {
        [$parent, $addon, $invoice] = $this->f1Setup();
        (new RecordPaymentService)->handle($invoice->fresh(), null, $invoice->fresh()->remaining, transactionId: 'f1-parent');
        $this->assertSame(Service::STATUS_SUSPENDED, $addon->fresh()->status);
        $this->assertSame('Overdue on Payment', $addon->fresh()->suspend_reason);

        $this->cronUntil('2027-03-25');

        $this->assertSame(Service::STATUS_TERMINATED, $addon->fresh()->status, 'past Termination Days on its own unpaid invoice');
        $this->assertSame('2027-01-20', $this->addonLines($addon)->first()->period_start->toDateString(), 'billed for the period it was ended for');
    }

    /**
     * Parent due Jan 1 and unpaid, its addon on its own date Jan 20; the cron to Feb 4: both suspended, the addon with its
     * parent, and its Jan 20 period billed on its own date all the same (docs/addons.md: each addon renews on its own).
     *
     * @return array{Service, Service, Invoice}
     */
    private function f1Setup(): array
    {
        $parent = $this->service('2027-01-01 00:00:00');
        $addon = $this->service('2027-01-20 00:00:00', ['parent_service_id' => $parent->id], addon: true);
        $invoice = Invoice::factory()->create(['client_id' => $parent->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => '2027-01-01', 'created_at' => '2026-12-25 09:00:00']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $parent->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10.00, 'period_start' => '2027-01-01', 'period_end' => '2027-02-01']);

        $this->cronUntil('2027-02-04');
        $this->assertSame(Service::STATUS_SUSPENDED, $parent->fresh()->status, 'the parent is suspended for its unpaid invoice');
        $this->assertSame(Service::STATUS_SUSPENDED, $addon->fresh()->status, 'the addon with it');
        $this->assertSame(Service::SUSPEND_REASON_PARENT, $addon->fresh()->suspend_reason);
        $lines = $this->addonLines($addon);
        $this->assertCount(1, $lines, 'its Jan 20 renewal is billed once, while suspended with its parent');
        $this->assertSame('2027-01-20', $lines->first()->period_start->toDateString());
        $this->assertSame(Invoice::STATUS_UNPAID, $lines->first()->invoice->status);

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
