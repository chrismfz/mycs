<?php

namespace Tests\Feature;

use App\Models\Credit;
use App\Models\CronStat;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\DunningForecast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial area review 3 of 6: suspension and termination (DunningRules, the cron's suspend/terminate steps, holds,
 * AddonCascade, the safety-net switches, DunningForecast, CronSummary). Each test goes through the real cron
 * (`app:cron-job`) or the real service and fails on adv/areas as reviewed. Fake data only.
 */
class AdvArea3Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 09:00:00'); // a Wednesday
        Queue::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_terminate_min_suspended_days' => 1,
            'settings.cronjob_invoice' => 7, 'settings.cronjob_suspend_requires_invoice' => false]);
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

    private function openInvoiceFor(string $due, Service ...$services): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $services[0]->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => $due, 'created_at' => Carbon::parse($due)->subDays(7)]);
        foreach ($services as $service) {
            $start = Carbon::parse($service->expires_at);
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
                'quantity' => 1, 'price' => $service->price, 'period_start' => $start, 'period_end' => $start->copy()->addMonth()]);
        }

        return $invoice;
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /**
     * ADV-A3-1. Staff set «Do Not Suspend Until» on a VM (the customer promised to pay next week). Its addon (an extra
     * IPv4, the same billing day, on the same overdue invoice) is not held on its own. Addons follow their parent
     * (docs/addons.md, AddonCascade): suspended with it, back with it, ended with it.
     *
     * Expected: the addon is not suspended while its parent is held (its machine is the parent's; staff held the
     * customer). Actual: DunningRules::suspendable asks of each service on its own, so the cron suspends the addon the
     * day it is due, under its held parent (a task for staff to cut the IP off the VM they just held), and the forecast
     * lists it as due.
     */
    public function test_adv_a3_1_an_addon_of_a_held_parent_is_not_suspended(): void
    {
        $parent = $this->service('2026-11-01 00:00:00', ['suspend_hold_until' => '2026-11-18']);
        $addon = $this->service('2026-11-01 00:00:00', ['parent_service_id' => $parent->id], addon: true);
        $this->openInvoiceFor('2026-11-01', $parent, $addon);

        $forecast = DunningForecast::compute(Carbon::today());
        $this->cron();

        $this->assertSame(Service::STATUS_ACTIVE, $parent->fresh()->status, 'the parent is held');
        $this->assertSame(Service::STATUS_ACTIVE, $addon->fresh()->status, 'the addon of a held parent is held with it');
        $this->assertNotContains($addon->id, $forecast['now']['suspend'], 'the forecast does not list the addon of a held parent');
    }

    /**
     * ADV-A3-2. The same, two weeks on: the addon was suspended on its own under its held parent (ADV-A3-1), and its
     * parent's hold is moved on (the customer pays in instalments). Past Termination Days the cron terminates the addon
     * alone, cancelling its line on the shared invoice, while its parent runs under staff's hold.
     *
     * Expected: an addon is ended with its parent, never on its own while the parent is held. Actual: terminated.
     */
    public function test_adv_a3_2_an_addon_of_a_held_parent_is_not_terminated(): void
    {
        $parent = $this->service('2026-10-20 00:00:00', ['suspend_hold_until' => '2026-11-30']);
        $addon = $this->service('2026-10-20 00:00:00', ['parent_service_id' => $parent->id], addon: true);
        $this->openInvoiceFor('2026-10-20', $parent, $addon);
        $addon->forceFill(['status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-10-23 09:00:00', 'suspend_reason' => 'Overdue on Payment'])->save();

        $this->cron();

        $this->assertSame(Service::STATUS_ACTIVE, $parent->fresh()->status);
        $this->assertNotSame(Service::STATUS_TERMINATED, $addon->fresh()->status, 'not ended on its own while its parent is held');
    }

    /**
     * ADV-A3-3. A service renewed by a gateway subscription (PayPal/Stripe, subscription_id set) whose subscription did not
     * pay this period (the card failed, the subscription was cancelled at the gateway). The customer has account credit
     * that covers the whole renewal (an overpayment, a refund to credit, a subscription sale that became credit because
     * its amount did not match). The cron applies credit «once a day before suspensions, so a customer with credit is not
     * suspended while it sits unused» (CreditAutoApply), and a subscription's own renewal may be paid by credit «when the
     * credit covers it all» (CreditAutoApply::toSubscriptionRenewal).
     *
     * Expected: the daily sweep pays the overdue renewal from the credit, the service is renewed, not suspended. Actual:
     * the sweep goes through toInvoice(), whose eligible() refuses any invoice of a service with a subscription, so the
     * credit sits unused and the service is suspended (and later terminated) for an invoice its credit covers.
     */
    public function test_adv_a3_3_credit_covering_a_subscription_renewal_is_used_before_suspending(): void
    {
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        $service = $this->service('2026-11-01 00:00:00', ['subscription_id' => 'I-SUBSCRIPTION1']);
        $invoice = $this->openInvoiceFor('2026-11-01', $service);
        Credit::query()->create(['client_id' => $service->client_id, 'currency_code' => 'USD', 'amount' => '50.00']);

        $this->cron();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'the credit covering it pays it');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'not suspended while credit covering it sits unused');
    }

    /**
     * ADV-A3-4 (the same root as ADV-A3-1, by staff's own procedure). A VM and its addon are suspended for non-payment
     * (the addon with it). Staff follow what the cron's own mail says: «to give it time: set Do Not Suspend Until and
     * unsuspend it». The VM comes back; its addon is brought back by AddonCascade::bringBack, which asks
     * stillOverdue() of the addon alone, with its own (empty) hold.
     *
     * Expected: the addon comes back with its held parent. Actual: it stays suspended, now «Overdue on Payment» with a
     * dunning mark (terminable on its own after a day) and a suspension task for staff on the VM they just restored.
     */
    public function test_adv_a3_4_staff_hold_and_unsuspend_of_a_parent_brings_its_addon_back(): void
    {
        $parent = $this->service('2026-11-01 00:00:00');
        $addon = $this->service('2026-11-01 00:00:00', ['parent_service_id' => $parent->id], addon: true);
        $this->openInvoiceFor('2026-11-01', $parent, $addon);
        $this->cron();
        $this->assertSame(Service::STATUS_SUSPENDED, $parent->fresh()->status);
        $this->assertSame(Service::SUSPEND_REASON_PARENT, $addon->fresh()->suspend_reason, 'suspended with its parent');

        $parent = $parent->fresh();
        $parent->update(['suspend_hold_until' => '2026-11-25', 'status' => Service::STATUS_ACTIVE]);

        $this->assertSame(Service::STATUS_ACTIVE, $addon->fresh()->status, 'back with its held parent');
    }

    /**
     * ADV-A3-5. The daily summary with «Working days only» (as the runbook suggests for after the first days): Friday's
     * mail lists what is due now, Saturday and Sunday; none goes out at the weekend; Monday's goes out at «Send at», after
     * the Time of Day (the runbook's advice), when Monday's suspensions are done already. A service due on Monday (or
     * one due at the weekend that the dunning window moves to Monday) is in no mail before it is suspended, which is what
     * the mail is for («what is suspended tomorrow or the day after, so we can tell them first»).
     *
     * Expected: Friday's mail looks ahead to the next working day's mail (Monday). Actual: only to Sunday.
     */
    public function test_adv_a3_5_working_days_summary_on_friday_foretells_mondays_suspensions(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false, 'settings.system_email_address' => 'ops@example.test',
            'settings.cron_summary_mode' => 'daily', 'settings.cron_summary_workdays' => true]);
        Carbon::setTestNow('2026-11-13 10:00:00'); // Friday
        CronStat::create(['key' => 'invoices_created', 'value' => 0, 'date' => '2026-11-12']);
        $service = $this->service('2026-11-13 00:00:00'); // Suspend Days 2: suspended on Monday 16/11
        $this->openInvoiceFor('2026-11-13', $service);

        $this->artisan('app:cron-summary')->assertExitCode(0);

        $mail = EmailLog::query()->where('template_key', 'system')->latest('id')->first();
        $this->assertNotNull($mail);
        $this->assertStringContainsString('#' . $service->id . '</a>', $mail->body, 'Monday\'s suspension is in Friday\'s mail, the last before it');
    }
}
