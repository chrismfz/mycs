<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use App\Services\Money\UnbilledServices;
use App\Services\Service\DunningRules;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Independent code review of #783 (`service_unbilled`, App\Services\Money\UnbilledServices), 2026-11-10. Each test is a
 * service that runs without being billed and is not told (a false negative), or one told that should not be (a false
 * positive). They fail on the PR's head; fixtures are invented.
 */
class MoneyCheckUnbilledReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Plan $plan;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Cache::flush();
        Carbon::setTestNow('2026-11-10 10:00:00');
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.cronjob_payment_reminder' => 6, 'settings.cronjob_overdue_reminders' => '1,3',
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true, 'settings.cronjob_invoice' => 7,
            'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
        $created = $this->createProduct();
        Price::query()->where('plan_id', $created->plan->id)->update(['price' => 10.00]);
        $this->plan = $created->plan;
        $this->productId = (int) $created->product->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $attributes */
    private function service(?string $due, array $attributes = []): Service
    {
        return Service::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'plan_id' => $this->plan->id, 'product_id' => $this->productId,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => $due, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1]);
    }

    private function renewal(Service $service, string $from, string $status = Invoice::STATUS_UNPAID): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => $status, 'currency_code' => 'USD', 'due_at' => $from]);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10,
            'period_start' => $from, 'period_end' => Carbon::parse($from)->addMonthNoOverflow()->toDateString()]);

        return $invoice->fresh();
    }

    /** @return array<int, list<string>> service => the kinds found for it */
    private function kinds(): array
    {
        $kinds = [];
        foreach ((new UnbilledServices)->find() as $finding) {
            $kinds[$finding['service']][] = $finding['kind'];
        }

        return $kinds;
    }

    private function cronUntil(string $day): void
    {
        while (now()->toDateString() < $day) {
            Carbon::setTestNow(now()->addDay());
            $this->artisan('app:cron-job')->assertExitCode(0);
        }
    }

    /**
     * REV-783-1 (false negative). A held service («Do not suspend until», here a year ahead) whose renewal staff
     * cancelled: its period is given up, so the cron never bills it again (RenewalBilling), and the hold keeps the
     * dunning away. It runs free for the whole hold and the rule says nothing: kind 3 reads the dunning's «unheld»
     * predicate, but a hold stops a suspension, not a bill. The same service without the hold is told from day one.
     */
    public function test_rev_783_1_a_held_service_whose_period_was_given_up_runs_free_and_is_not_told(): void
    {
        $held = $this->service('2026-11-01', ['suspend_hold_until' => '2027-11-01']);
        $plain = $this->service('2026-11-01');
        foreach ([$held, $plain] as $service) {
            $this->renewal($service, '2026-11-01')->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Goodwill']);
            $this->assertNotNull(InvoiceItem::query()->where('reference_id', $service->id)->sole()->forgone_at, 'precondition: given up');
        }

        // A month of cron runs: nothing is billed for either, and the held one is never suspended.
        $this->cronUntil('2026-12-10');
        $this->assertSame(2, InvoiceItem::query()->where('reference_type', Service::class)->count(), 'precondition: the cron billed nothing more');
        $this->assertSame(Service::STATUS_ACTIVE, $held->fresh()->status, 'precondition: held, so it still runs');
        $this->assertFalse(DunningRules::suspendable(now())->whereKey($held->id)->exists());

        $this->assertSame([UnbilledServices::PAST_DUE], $this->kinds()[$held->id] ?? [],
            'past due 39 days, its period given up, never billed again, not suspended for a year: nobody is told');
    }

    /**
     * REV-783-2 (false negative). An upgrade paid and done (the service is on the bigger product, at its price), then
     * its payment deleted: the invoice owes again and the service keeps the package. The rule reads only lines whose
     * reference is the service; an upgrade's line refers to the ServiceUpgrade, so nothing is told. It is the PR's own
     * scenario («paid, applied, the payment deleted») for the other thing a payment gives a service.
     */
    public function test_rev_783_2_an_upgrade_done_and_its_payment_deleted_is_not_told(): void
    {
        $big = Product::factory()->create(['name' => 'Big', 'server_id' => null, 'stock' => null]);
        $bigPlan = Plan::factory()->create(['priceable_id' => $big->id, 'priceable_type' => Product::class, 'name' => $this->plan->name,
            'billing_unit' => $this->plan->billing_unit, 'billing_period' => $this->plan->billing_period, 'type' => $this->plan->type]);
        Price::factory()->create(['plan_id' => $bigPlan->id, 'price' => '30.00', 'setup_fee' => 0, 'currency_code' => 'USD']);
        $service = $this->service('2026-12-01');
        $paid = $this->renewal($service, '2026-11-01');
        (new RecordPaymentService)->recordByStaff($paid, null, '10.00', 'bank-0');
        $this->assertSame([], $this->kinds(), 'precondition: an ordinary paid service');

        $up = PlaceUpgrade::handle($service->fresh(), $big->fresh(), [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::INVOICED, $up->outcome, 'precondition: the difference is invoiced');
        $owed = number_format((float) $up->invoice->fresh()->total, 2, '.', '');
        $payment = (new RecordPaymentService)->recordByStaff($up->invoice->fresh(), null, $owed, 'bank-up');
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $up->upgrade->fresh()->status, 'precondition: paid, the upgrade is done');
        $this->assertSame((int) $big->id, (int) $service->fresh()->product_id);

        (new RecordPaymentService)->deleteTransaction($payment);

        $this->assertSame(Invoice::STATUS_UNPAID, $up->invoice->fresh()->status, 'precondition: the upgrade\'s invoice owes again');
        $this->assertSame([(int) $big->id, Service::STATUS_ACTIVE], [(int) $service->fresh()->product_id, $service->fresh()->status], 'precondition: it keeps the package');
        $this->assertSame([], array_values(array_diff(array_column((new MoneyCheck)->run(), 'rule'), ['service_unbilled'])), 'precondition: no other rule tells it');
        $this->assertArrayHasKey($service->id, $this->kinds(), 'it runs on a package whose invoice is unpaid again, and nobody is told');
    }

    /**
     * REV-783-3 (false negative, low). «Nothing asks for the money» counts a DRAFT as asking. A draft is never sent or
     * reminded, and it stops the cron from billing the service (RenewalBilling: an open invoice). With Enable
     * Suspension off (the very case kind 3 names) the service runs past due for good and is never told. The cron's own
     * «past due with no open invoice» notice (BIL-5) counts only an unpaid invoice as one.
     */
    public function test_rev_783_3_a_forgotten_draft_asks_nobody_and_hides_a_service_that_is_never_billed(): void
    {
        config(['settings.cronjob_suspend_enabled' => false]);
        $service = $this->service('2026-09-01');
        $draft = $this->renewal($service, '2026-09-01', Invoice::STATUS_DRAFT);
        $twin = $this->service('2026-09-01'); // the same, with no draft: the cron bills it

        $this->cronUntil('2026-11-13');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertSame(Invoice::STATUS_DRAFT, $draft->fresh()->status, 'precondition: still a draft');
        $this->assertSame(1, InvoiceItem::query()->where('reference_id', $service->id)->count(), 'precondition: the cron bills nothing while the draft is there');
        $this->assertSame(0, DB::table('invoice_reminders')->where('invoice_id', $draft->id)->count(), 'precondition: a draft reminds nobody');

        $kinds = $this->kinds();
        $this->assertTrue($twin->renewalInvoices()->where('invoices.status', Invoice::STATUS_UNPAID)->exists(), 'precondition: the twin with no draft was billed by the cron');
        $this->assertSame([UnbilledServices::PAST_DUE], $kinds[$service->id] ?? [], '73 days past due, active, its only invoice a draft nobody received');
    }

    /**
     * REV-783-4 (false positive). The rule's own advice, followed: the payment of a renewal is deleted; staff move the
     * due date back and cancel the invoice to bill it again («Re-issue»); the cron bills the same period on a new
     * invoice and the customer pays it. The service now runs on a PAID period, but the cancelled invoice's line still
     * says applied with the same end, so the rule tells it for the whole period: «nothing pays for the period».
     */
    public function test_rev_783_4_a_period_billed_again_and_paid_is_still_told_as_unpaid(): void
    {
        $service = $this->service('2026-11-15');
        $first = $this->renewal($service, '2026-11-15');
        $payment = (new RecordPaymentService)->recordByStaff($first, null, '10.00', 'bank-1');
        $this->assertSame('2026-12-15', $service->fresh()->expires_at->toDateString());
        (new RecordPaymentService)->deleteTransaction($payment);
        $this->assertSame([$service->id => [UnbilledServices::PERIOD_NOT_PAID]], $this->kinds(), 'precondition: told, rightly');

        // «…or move the due date back to 2026-11-15», and the invoice re-issued.
        $service->fresh()->update(['expires_at' => '2026-11-15']);
        $first = $first->fresh();
        $first->keep_periods_billable = true;
        $first->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Re-issue']);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $second = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)->where('invoice_id', '!=', $first->id)->sole();
        $this->assertSame(['2026-11-15', '2026-12-15'], [$second->period_start->toDateString(), $second->period_end->toDateString()], 'precondition: the same period, billed again');
        (new RecordPaymentService)->recordByStaff($second->invoice, null, '10.00', 'bank-2');
        $this->assertSame([Invoice::STATUS_PAID, '2026-12-15'], [$second->invoice->fresh()->status, $service->fresh()->expires_at->toDateString()], 'precondition: paid, renewed');

        $this->assertSame([], $this->kinds(), 'its period is paid for by the second invoice');
    }
}
