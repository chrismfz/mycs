<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\EditPayment;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use App\Services\Money\UnbilledServices;
use App\Services\Service\DunningRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * The money check's `service_unbilled` (owner 2026-11-10, docs/money-trail.md §5): a service that runs without being
 * billed is told to staff. One test per kind of finding and per near-miss that must stay quiet. The scenario the owner
 * asked about (paid, renewed, the payment deleted) goes through the real services and the cron, and pins what happens
 * today, for docs/staff-freedom.md.
 */
class MoneyCheckUnbilledServicesTest extends TestCase
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

    /** An open renewal invoice of the service for the month from $from, due then. */
    private function renewal(Service $service, string $from): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $from]);
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

    /** @return list<string> the details the money check gives for a service */
    private function details(Service $service): array
    {
        return array_values(array_column(array_filter((new MoneyCheck)->run(['service_unbilled']),
            fn (array $finding) => $finding['subject'] === "service #{$service->id}"), 'detail'));
    }

    private function stripe(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        foreach (['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => 'whsec_test'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        (new Stripe(['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => 'whsec_test']))->boot();
        Http::fake();
    }

    private function cronUntil(string $day): void
    {
        while (now()->toDateString() < $day) {
            Carbon::setTestNow(now()->addDay());
            $this->artisan('app:cron-job')->assertExitCode(0);
        }
    }

    // ---- kind 1: no due date

    public function test_a_recurring_service_with_no_due_date_is_told(): void
    {
        $service = $this->service(null);

        $this->assertSame([$service->id => [UnbilledServices::NO_DUE_DATE]], $this->kinds());
        $this->assertSame(['recurring service with no due date (never billed, never suspended): set its next due date'], $this->details($service));
        $this->assertSame('service_unbilled', (new MoneyCheck)->run()[0]['rule'], 'one of the daily rules');
    }

    public function test_no_due_date_is_nothing_on_a_one_time_or_free_plan_nor_on_a_service_that_does_not_run(): void
    {
        foreach (['one-time', 'free'] as $type) {
            $plan = Plan::factory()->create(['priceable_id' => $this->productId, 'priceable_type' => $this->plan->priceable_type, 'name' => $type, 'type' => $type]);
            $this->service(null, ['plan_id' => $plan->id]);
        }
        foreach ([Service::STATUS_PENDING, Service::STATUS_SUSPENDED, Service::STATUS_CANCELLED, Service::STATUS_TERMINATED] as $status) {
            $this->service(null, ['status' => $status]);
        }
        // An account the cron never suspends (its own setting): left out of the rule.
        $exempt = User::factory()->create();
        $exempt->client()->update(['dunning_exempt' => true]);
        $this->service(null, ['client_id' => $exempt->client()->id]);
        // At 0.00 there is nothing to bill: a WHMCS «Free Account» is imported so (no date, its 0.00 kept).
        $this->service(null, ['price' => 0, 'price_locked' => true]);

        $this->assertSame([], $this->kinds());
    }

    // ---- kind 2: a period given by an invoice that is not paid

    /**
     * THE scenario (owner 2026-11-10): the customer pays, the service renews, staff delete the payment. Through the cron,
     * the payments service and the dunning rules, with the facts as they are today.
     */
    public function test_a_service_renewed_and_its_payment_deleted_is_told_until_it_is_suspended(): void
    {
        Carbon::setTestNow('2026-10-08 10:00:00');
        $service = $this->service('2026-10-15');
        $this->artisan('app:cron-job')->assertExitCode(0); // 7 days before: its renewal is invoiced
        $line = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)->sole();
        $invoice = $line->invoice;
        $this->assertSame(['2026-10-15', '2026-11-15', '2026-10-15'], [$line->period_start->toDateString(), $line->period_end->toDateString(), $invoice->due_at->toDateString()]);

        Carbon::setTestNow('2026-10-10 10:00:00');
        $payment = (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'bank-1');
        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString(), 'paid: renewed');
        $this->assertSame([], $this->kinds());

        Carbon::setTestNow('2026-10-12 10:00:00');
        (new RecordPaymentService)->deleteTransaction($payment);

        // Fact 1: the invoice is unpaid again and owes all of it; its line stays applied.
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(1000, RecordPaymentService::toCents($invoice->fresh()->remaining));
        $this->assertNotNull($line->fresh()->applied_at);
        // Fact 2: the service keeps the period it was given.
        $this->assertSame([Service::STATUS_ACTIVE, '2026-11-15'], [$service->fresh()->status, $service->fresh()->expires_at->toDateString()]);
        // Told from that day, with the invoice, what it owes and since when, and what to do.
        $told = ["runs to 2026-11-15 on invoice #{$invoice->id}, which is unpaid again since 2026-10-12 (owes 10.00 USD): collect it, or move the due date back to 2026-10-15"];
        $this->assertSame($told, $this->details($service));
        $this->assertSame(['service_unbilled'], array_column((new MoneyCheck)->run(), 'rule'), 'the only finding: the money itself adds up');

        // Fact 3: the reminders go by the invoice's own due date (2026-10-15): before it, then 1 and 3 days after.
        $this->cronUntil('2026-11-14');
        $this->assertSame([['due', 0], ['overdue', 1], ['overdue', 3]], DB::table('invoice_reminders')->where('invoice_id', $invoice->id)
            ->where('kind', '!=', 'mailed')->orderBy('id')->get()->map(fn ($row) => [$row->kind, (int) $row->days])->all());
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'a month past the invoice\'s due date, never suspended: the suspension counts from the new date');
        $this->assertSame($told, $this->details($service), 'the same words every day: mailed once, then weekly');

        // Fact 4: the dunning suspends it Suspend Days after the NEW due date: 2026-11-15 + 8 days, on the 24th.
        $this->assertFalse(DunningRules::suspendable(Carbon::parse('2026-11-23'))->whereKey($service->id)->exists());
        $this->assertTrue(DunningRules::suspendable(Carbon::parse('2026-11-24'))->whereKey($service->id)->exists());
        $this->cronUntil('2026-11-23');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertSame($told, $this->details($service), 'still told past the new due date, while it runs');
        $this->cronUntil('2026-11-24');
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
        $this->assertNotNull($service->fresh()->dunning_suspended_at);
        $this->assertSame([], $this->details($service), 'suspended: it runs no more, the dunning has it');

        // Fact 5: while that invoice is open the cron issues no next invoice (the period from 2026-11-15 is never billed).
        $this->cronUntil('2026-11-27');
        $this->assertSame([$invoice->id], Invoice::query()->pluck('id')->all());
        $this->assertSame(1, InvoiceItem::query()->where('reference_id', $service->id)->where('reference_type', Service::class)->count());
    }

    /** Deleted after the reminder days have passed: no reminder goes at all, only the money check tells. */
    public function test_a_payment_deleted_late_gets_no_reminder_at_all(): void
    {
        Carbon::setTestNow('2026-10-08 10:00:00');
        $service = $this->service('2026-10-15');
        $invoice = $this->renewal($service, '2026-10-15');
        Carbon::setTestNow('2026-10-10 10:00:00');
        $payment = (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'bank-1');
        Carbon::setTestNow('2026-10-30 10:00:00');
        (new RecordPaymentService)->deleteTransaction($payment);

        $this->cronUntil('2026-11-14');

        $this->assertSame(0, DB::table('invoice_reminders')->where('invoice_id', $invoice->id)->where('kind', '!=', 'mailed')->count());
        $this->assertSame(["runs to 2026-11-15 on invoice #{$invoice->id}, which is unpaid again since 2026-10-30 (owes 10.00 USD): collect it, or move the due date back to 2026-10-15"],
            $this->details($service));
    }

    public function test_staff_moving_the_due_date_back_or_the_customer_paying_again_ends_it(): void
    {
        $service = $this->service('2026-11-15');
        $invoice = $this->renewal($service, '2026-11-15');
        $payment = (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'bank-1');
        (new RecordPaymentService)->deleteTransaction($payment);
        $this->assertSame([$service->id => [UnbilledServices::PERIOD_NOT_PAID]], $this->kinds());

        // Moved back: an ordinary service with an open invoice due on its date.
        $service->fresh()->update(['expires_at' => '2026-11-15']);
        $this->assertSame([], $this->kinds());

        // Or paid again (the date as it was): the line is not applied twice.
        $service->fresh()->update(['expires_at' => '2026-12-15']);
        $this->assertSame([$service->id => [UnbilledServices::PERIOD_NOT_PAID]], $this->kinds());
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '10.00', 'bank-2');
        $this->assertSame([], $this->kinds());
        $this->assertSame('2026-12-15', $service->fresh()->expires_at->toDateString());
    }

    public function test_the_same_after_a_chargeback(): void
    {
        $this->stripe();
        $service = $this->service('2026-11-15');
        $invoice = $this->renewal($service, '2026-11-15');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, transactionId: 'pi_1', currency: 'usd');
        $this->assertSame('2026-12-15', $service->fresh()->expires_at->toDateString());
        $this->assertSame([], $this->kinds());

        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_1', 'dp_1', 10.00, 'usd'));

        $this->assertSame([Invoice::STATUS_UNPAID, '2026-12-15'], [$invoice->fresh()->status, $service->fresh()->expires_at->toDateString()]);
        $this->assertSame(["runs to 2026-12-15 on invoice #{$invoice->id}, which is unpaid again since 2026-11-20 (owes 10.00 USD): collect it, or move the due date back to 2026-11-15"],
            $this->details($service));
    }

    /** #781's «Edit» lowering a payment: delete and record again, the invoice open for the rest, the period kept. */
    public function test_the_same_after_a_payment_is_edited_down(): void
    {
        $service = $this->service('2026-11-15');
        $invoice = $this->renewal($service, '2026-11-15');
        $payment = (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'bank-1');
        $this->assertSame('2026-12-15', $service->fresh()->expires_at->toDateString());

        Carbon::setTestNow('2026-11-12 10:00:00');
        $this->actingAs(Staff::factory()->admin()->create());
        (new EditPayment)->handle($payment, ['amount' => '4.00']);

        $this->assertSame([Invoice::STATUS_UNPAID, '2026-12-15'], [$invoice->fresh()->status, $service->fresh()->expires_at->toDateString()]);
        $this->assertSame(["runs to 2026-12-15 on invoice #{$invoice->id}, which is unpaid again since 2026-11-12 (owes 6.00 USD): collect it, or move the due date back to 2026-11-15"],
            $this->details($service));
    }

    public function test_an_invoice_staff_marked_paid_by_hand_is_their_word(): void
    {
        $service = $this->service('2026-11-15');
        $invoice = $this->renewal($service, '2026-11-15');
        $this->actingAs(Staff::factory()->admin()->create());
        $invoice->paid_without_payment = true;
        $invoice->update(['status' => Invoice::STATUS_PAID]);

        $this->assertNotNull($invoice->fresh()->paid_by_staff_at);
        $this->assertSame('2026-12-15', $service->fresh()->expires_at->toDateString(), 'renewed with no money');
        $this->assertSame([], $this->kinds());
        $this->assertSame(['paid_balance'], array_column((new MoneyCheck)->run(), 'rule'), 'shown where it always was');
    }

    public function test_a_cancelled_or_refunded_invoice_that_gave_the_period(): void
    {
        $service = $this->service('2026-12-15');
        $invoice = $this->renewal($service, '2026-11-15');
        DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['applied_at' => now()]);
        foreach ([Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED] as $status) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status]);

            $this->assertSame(["runs to 2026-12-15 on invoice #{$invoice->id}, which is {$status}: nothing pays for the period from 2026-11-15: bill it again, or move the due date back"],
                $this->details($service));
        }
    }

    public function test_an_old_unpaid_period_of_a_service_renewed_since_is_not_told_again(): void
    {
        // Applied in September, unpaid again; October and November were paid since: an old debt, not what it runs on.
        $service = $this->service('2026-12-15');
        $old = $this->renewal($service, '2026-09-15');
        DB::table('invoice_items')->where('invoice_id', $old->id)->update(['applied_at' => '2026-09-14 10:00:00']);

        $this->assertSame([], $this->kinds());
    }

    public function test_another_customers_invoice_and_a_charge_line_are_not_its_period(): void
    {
        $service = $this->service('2026-12-15');
        $invoice = $this->renewal($service, '2026-11-15');
        DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['applied_at' => now(), 'kind' => InvoiceItem::KIND_CHARGE]);
        $this->assertSame([], $this->kinds(), 'a charge of it bills no period');

        DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['kind' => null]);
        DB::table('invoices')->where('id', $invoice->id)->update(['client_id' => User::factory()->create()->client()->id]);
        $this->assertSame([], $this->kinds(), 'the service moved: its first customer\'s invoice is not its bill');
    }

    // ---- kind 3: past due, nothing asks for the money

    public function test_a_period_given_up_is_told_from_the_first_day_past_due(): void
    {
        $service = $this->service('2026-11-09');
        $invoice = $this->renewal($service, '2026-11-09');
        $this->assertSame([], $this->kinds(), 'an ordinary overdue customer: the invoice asks');

        // Staff cancel the renewal: its period is given up, the cron never bills it again.
        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Goodwill']);
        $this->assertNotNull($invoice->items()->sole()->forgone_at);

        $this->assertSame(["past due since 2026-11-09, still active, with no open invoice: its period from 2026-11-09 was given up (invoice #{$invoice->id}, cancelled): re-issue it, or move the due date"],
            $this->details($service));
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(1, InvoiceItem::query()->where('reference_id', $service->id)->count(), 'the cron bills nothing for it');
        $this->assertSame([$service->id => [UnbilledServices::PAST_DUE]], $this->kinds());

        // Reopened: it asks again.
        $invoice->fresh()->update(['status' => Invoice::STATUS_UNPAID]);
        $this->assertSame([], $this->kinds());
    }

    public function test_past_due_with_no_invoice_is_told_only_after_the_cron_should_have_suspended_it(): void
    {
        // Suspend Days 8, and 2 more: due before 2026-10-31.
        $waiting = $this->service('2026-10-31');
        $late = $this->service('2026-10-30');

        $this->assertSame([$late->id => [UnbilledServices::PAST_DUE]], $this->kinds());
        $this->assertSame(['past due since 2026-10-30, still active, with no open invoice: nothing asks for the money (not billed, not suspended): bill it (Invoice selected items), or set its due date'],
            $this->details($late));
        $this->assertSame([], $this->details($waiting));
    }

    public function test_the_ordinary_overdue_customer_and_the_suspended_service_are_not_told(): void
    {
        // Overdue inside Suspend Days, and far past them, with an open invoice: the invoice asks, the dunning follows.
        $this->renewal($this->service('2026-11-05'), '2026-11-05');
        $this->renewal($this->service('2026-09-05'), '2026-09-05');
        // Suspended for non-payment, with and without an invoice: the dunning's, then the termination's.
        $this->service('2026-09-05', ['status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-09-14 00:00:00']);
        // Suspended by staff.
        $this->service('2026-09-05', ['status' => Service::STATUS_SUSPENDED]);
        // Held (Do Not Suspend Until), through today too.
        $this->service('2026-09-05', ['suspend_hold_until' => '2026-12-31']);
        $this->service('2026-09-05', ['suspend_hold_until' => '2026-11-10']);
        // A cancellation request: it ends on its date.
        ServiceCancellation::query()->create(['service_id' => $this->service('2026-09-05')->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);
        // One-time and free: no period to pay.
        foreach (['one-time', 'free'] as $type) {
            $plan = Plan::factory()->create(['priceable_id' => $this->productId, 'priceable_type' => $this->plan->priceable_type, 'name' => $type, 'type' => $type]);
            $this->service('2026-09-05', ['plan_id' => $plan->id]);
        }
        // An account exempt from the cron's suspensions.
        $exempt = User::factory()->create();
        $exempt->client()->update(['dunning_exempt' => true]);
        $this->service('2026-09-05', ['client_id' => $exempt->client()->id]);
        // Paid, its line still to be applied (BIL-9): the renewal is on its way.
        $paid = $this->renewal($this->service('2026-09-05'), '2026-09-05');
        DB::table('invoices')->where('id', $paid->id)->update(['status' => Invoice::STATUS_PAID, 'apply_pending_since' => now()]);

        $this->assertSame([], $this->kinds());
    }

    public function test_a_hold_that_ended_is_told_again(): void
    {
        $service = $this->service('2026-09-05', ['suspend_hold_until' => '2026-11-09']);

        $this->assertSame([$service->id => [UnbilledServices::PAST_DUE]], $this->kinds());
    }

    // ---- the addon of an ended service

    public function test_an_active_addon_of_an_ended_service_is_told(): void
    {
        $parent = $this->service('2026-12-01', ['status' => Service::STATUS_TERMINATED]);
        $addon = $this->service('2026-12-01', ['parent_service_id' => $parent->id]);
        $this->service('2026-12-01', ['parent_service_id' => $this->service('2026-12-01')->id]); // of a live one

        $this->assertSame([$addon->id => [UnbilledServices::ADDON_OF_ENDED]], $this->kinds());
        $this->assertSame(["active addon of service #{$parent->id}, which is terminated: end it too, or it runs on its own"], $this->details($addon));
    }

    // ---- how it reaches staff, and what it costs

    public function test_staff_are_mailed_once_while_the_finding_stays_the_same(): void
    {
        $service = $this->service('2026-11-15');
        $payment = (new RecordPaymentService)->recordByStaff($this->renewal($service, '2026-11-15'), null, '10.00', 'bank-1');
        (new RecordPaymentService)->deleteTransaction($payment);
        $this->service(null);
        $this->service('2026-10-01');

        $this->artisan('mycs:money-check')->assertExitCode(1);
        $first = (new MoneyCheck)->run();
        $this->assertCount(3, $first);
        $mails = fn (): int => DB::table('email_logs')->where('subject', 'like', 'Money check:%')->count();
        $this->assertSame(1, $mails());

        // Three days on: the same subjects and the same words, so the same fingerprint and no second mail.
        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->assertSame($first, (new MoneyCheck)->run());
        $this->artisan('mycs:money-check')->assertExitCode(1);
        $this->assertSame(1, $mails());

        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->artisan('mycs:money-check')->assertExitCode(1);
        $this->assertSame(2, $mails(), 'a week later, still there');
    }

    /** A rehearsal's check (docs/cutover-runbook.md): the one rule, no mail, the counts and ids by kind. */
    public function test_the_command_runs_the_rule_alone_and_prints_counts_and_ids_by_kind(): void
    {
        $none = $this->service(null);
        $other = $this->service(null);
        $late = $this->service('2026-10-01');

        $this->artisan('mycs:money-check', ['--rule' => ['service_unbilled']])
            ->expectsOutputToContain("service_unbilled no_due_date: 2 (services {$none->id}, {$other->id})")
            ->expectsOutputToContain("service_unbilled past_due: 1 (services {$late->id})")
            ->assertExitCode(1);
        $this->assertSame(0, DB::table('email_logs')->where('subject', 'like', 'Money check:%')->count(), 'one rule asked for: no mail');
    }

    public function test_two_hundred_services_cost_a_fixed_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            (new UnbilledServices)->find();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $few = $count();

        foreach (range(1, 50) as $i) {
            $this->service(null);
            $this->service('2026-10-01');
            $given = $this->service('2026-11-09');
            DB::table('invoices')->where('id', $this->renewal($given, '2026-11-09')->id)->update(['status' => Invoice::STATUS_CANCELLED]);
            DB::table('invoice_items')->where('reference_id', $given->id)->update(['forgone_at' => now()]);
            $unpaid = $this->service('2026-12-15');
            DB::table('invoice_items')->where('invoice_id', $this->renewal($unpaid, '2026-11-15')->id)->update(['applied_at' => now()]);
        }
        $this->assertSame(200, Service::query()->count());
        $found = (new UnbilledServices)->find();
        $this->assertCount(200, $found);
        $this->assertSame(50, count(array_filter($found, fn (array $f) => $f['kind'] === UnbilledServices::PERIOD_NOT_PAID)));

        $many = $count();
        $this->assertLessThanOrEqual(16, $many, 'a handful of queries, whatever the number of services');
        $this->assertLessThanOrEqual($few + 9, $many, 'no query per service: only the eager loads of what was found');
    }
}
