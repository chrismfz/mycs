<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Classes\Settings;
use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\PaymentReminders;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of the payment reminders (Σ4, commit 92c9748). Failing tests are findings; test_holds_* are
 * attacks that held.
 */
class PaymentRemindersAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        Queue::fake();
        config(['settings.mail_disable' => false, 'settings.cronjob_payment_reminder' => 6, 'settings.cronjob_overdue_reminders' => '1,3',
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true, 'settings.cronjob_suspend_requires_invoice' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $due, array $service = [], string $created = '2026-10-10', float $price = 10): Invoice
    {
        $product = $this->createProduct();
        $user = User::factory()->create();
        $svc = Service::factory()->create(array_merge(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => $price, 'quantity' => 1, 'expires_at' => $due], $service));
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $svc->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => $price]);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => $created]);
        MailFacade::fake();

        return $invoice->fresh();
    }

    /** @return list<string> */
    private function sent(): array
    {
        return MailFacade::queued(Mail::class)->map(fn (Mail $mail) => $mail->templateKey)->values()->all();
    }

    private function owner(): User
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return User::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
    }

    // ---------------------------------------------------------------- findings

    /**
     * A1: the last notice names the day BEFORE the cron suspends. suspendable() takes expires_at < today - Suspend
     * Days (strict), so a service due 19/10 with 8 days is still active on 27/10 and suspended on 28/10.
     */
    public function test_a1_the_suspension_date_in_the_last_notice_is_the_day_the_cron_suspends(): void
    {
        $invoice = $this->invoice('2026-10-19');
        $service = $invoice->items->first()->reference;
        Carbon::setTestNow('2026-10-22 09:00:00');
        (new PaymentReminders)->send(); // day 3: the last notice
        $told = MailFacade::queued(Mail::class)->last()->renderedHtml;

        // Find the day the real cron suspends it.
        $suspendedOn = null;
        foreach (['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29'] as $day) {
            Carbon::setTestNow($day . ' 09:00:00');
            $this->artisan('app:cron-job');
            if ($service->fresh()->status === Service::STATUS_SUSPENDED) {
                $suspendedOn = Carbon::parse($day)->format('d/m/Y');
                break;
            }
        }

        $this->assertNotNull($suspendedOn);
        $this->assertStringContainsString($suspendedOn, $told, "the cron suspends on {$suspendedOn}; the customer was told another day");
    }

    /**
     * A2: a service with a saved payment method (billing agreement) is charged ONCE, when the cron creates its invoice
     * (CronJob::billRenewal). If that charge failed the invoice stays unpaid and nothing charges it again, yet the
     * reminder is «card_payment_due»: «will be charged automatically on <due date>. There is nothing you need to do.»
     */
    public function test_a2_a_billing_agreement_invoice_left_unpaid_is_not_told_it_will_be_charged(): void
    {
        config(['settings.cronjob_invoice' => 7, 'settings.credits_auto_use' => false]);
        Carbon::setTestNow('2026-10-18 09:00:00');
        $product = $this->createProduct();
        $user = User::factory()->create();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        $agreement = BillingAgreement::create(['client_id' => $user->client()->id, 'gateway_id' => $gateway->id, 'name' => 'PayPal', 'external_reference' => 'VAULT-A2', 'type' => 'paypal']);
        Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-10-25', 'billing_agreement_id' => $agreement->id]);
        MailFacade::fake();

        $this->artisan('app:cron-job'); // bills it and tries the agreement once: the charge fails (gateway not set up)
        $invoice = Invoice::query()->where('client_id', $user->client()->id)->sole();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status, 'precondition: the one automatic charge failed');
        MailFacade::fake();

        Carbon::setTestNow('2026-10-19 09:00:00');
        (new PaymentReminders)->send();

        $this->assertSame(['invoice_payment_reminder'], $this->sent(), 'nothing will charge it again: the customer must be asked to pay');
    }

    /** A3: subscription_id '' (an empty string, as the cron itself treats it: `(string) subscription_id !== ''`) is no subscription. */
    public function test_a3_an_empty_subscription_id_is_not_paid_automatically(): void
    {
        $this->invoice('2026-10-25', ['subscription_id' => '']);

        (new PaymentReminders)->send();

        $this->assertSame(['invoice_payment_reminder'], $this->sent());
    }

    /**
     * A4: a missed run catches up with the latest day reached, but the mail says that day, not how late it is: an
     * invoice 4 days late is told «2 day(s) overdue».
     */
    public function test_a4_a_caught_up_notice_says_how_late_the_invoice_really_is(): void
    {
        config(['settings.cronjob_overdue_reminders' => '1,2']);
        $this->invoice('2026-10-16'); // 4 days late; runs on days 1, 2, 3 missed

        (new PaymentReminders)->send();

        $this->assertSame(['invoice_overdue'], $this->sent());
        $this->assertStringContainsString('4 day(s) overdue', MailFacade::queued(Mail::class)->first()->renderedHtml);
    }

    /**
     * A5: an «add funds» invoice the customer abandoned (due now, a voluntary deposit, nothing is suspended for it) is
     * dunned as «Overdue», a template whose mail cannot be turned off (mail_enabled force).
     */
    public function test_a5_an_abandoned_credit_deposit_invoice_gets_no_overdue_notice(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-19']);
        $invoice->items()->create(['description' => 'Credit deposit', 'quantity' => 1, 'price' => 50, 'reference_type' => Credit::class]);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => '2026-10-19']);
        MailFacade::fake();

        (new PaymentReminders)->send();

        $this->assertSame([], $this->sent());
    }

    /** A6: a hold (suspend_hold_until) keeps the cron from suspending, yet the last notice names a suspension date. */
    public function test_a6_a_held_service_is_not_told_a_suspension_date(): void
    {
        $this->invoice('2026-10-17', ['suspend_hold_until' => '2026-12-31']); // day 3: the last notice

        (new PaymentReminders)->send();

        $html = MailFacade::queued(Mail::class)->first()->renderedHtml;
        $this->assertStringNotContainsString('suspended on', $html, 'the service is held until 31/12: no suspension on the date told');
    }

    /**
     * A7: the batch is read once and each invoice is mailed from that stale row (PDF per invoice: a large run lasts
     * minutes). An invoice cancelled by staff meanwhile still gets its overdue notice: status is not read again.
     */
    public function test_a7_an_invoice_cancelled_during_the_run_is_not_reminded(): void
    {
        $invoice = $this->invoice('2026-10-19');
        $done = false;
        Invoice::retrieved(function (Invoice $retrieved) use ($invoice, &$done) {
            if (!$done && $retrieved->id === $invoice->id) {
                $done = true; // staff cancel it right after the step read it
                DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_CANCELLED]);
            }
        });

        (new PaymentReminders)->send();

        $this->assertSame([], $this->sent());
    }

    /**
     * A8: «Send Email» is authorized by `update`, and with Immutable Invoices on `update` is false for every issued
     * invoice, while the action is visible only on issued ones: staff (even the Owner) can never send it.
     */
    public function test_a8_send_email_works_with_immutable_invoices(): void
    {
        config(['settings.immutable_invoices_enabled' => true]);
        $owner = $this->owner();
        $invoice = $this->invoice('2026-10-25');

        try {
            Livewire::actingAs($owner)->test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
                ->callAction('sendEmail', ['template' => 'invoice_modified']);
        } catch (\Throwable) {
            // forbidden: counted below
        }

        $this->assertSame(['invoice_modified'], $this->sent());
    }

    /** A9: staff's «Send Email» of a reminder is not recorded: the cron sends the same notice again the same day. */
    public function test_a9_a_reminder_sent_by_staff_is_not_sent_again_by_the_cron(): void
    {
        $owner = $this->owner();
        $invoice = $this->invoice('2026-10-19'); // 1 day late

        Livewire::actingAs($owner)->test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('sendEmail', ['template' => 'invoice_overdue'])->assertHasNoActionErrors();
        (new PaymentReminders)->send();

        $this->assertSame(['invoice_overdue'], $this->sent());
    }

    /** A10: a partly paid invoice's notices state the full total, never what is left to pay. */
    public function test_a10_a_partly_paid_invoice_is_told_what_is_left_to_pay(): void
    {
        $invoice = $this->invoice('2026-10-19', price: 10);
        $invoice->transactions()->create(['amount' => 6]);

        (new PaymentReminders)->send();

        $this->assertSame(['invoice_overdue'], $this->sent());
        $this->assertStringContainsString('4.00', MailFacade::queued(Mail::class)->first()->renderedHtml);
    }

    /**
     * A11: a huge day in the setting passes its validation (`\d+`, no upper bound); intval() saturates at PHP_INT_MAX and
     * max($days) + CATCH_UP_DAYS overflows to a float: the overdue window is empty and EVERY overdue notice silently stops.
     */
    public function test_a11_a_huge_overdue_day_does_not_stop_the_others(): void
    {
        config(['settings.cronjob_overdue_reminders' => '1,99999999999999999999']);
        $rule = collect(Settings::settings()['cronjob'])->firstWhere('name', 'cronjob_overdue_reminders')['validation'];
        // Fixed: the setting refuses it (at most 3 digits); were it stored anyway, a day over a year is ignored.
        $this->assertFalse(Validator::make(['v' => '1,99999999999999999999'], ['v' => $rule])->passes());
        $this->invoice('2026-10-19');

        try {
            (new PaymentReminders)->send();
        } catch (\Throwable $e) {
            $this->fail('the step threw: ' . $e->getMessage());
        }

        $this->assertSame(['invoice_overdue'], $this->sent(), 'day 1 is still due its notice');
    }

    // ---------------------------------------------------------------- holds

    public function test_holds_two_runs_and_a_failed_send_do_not_double_mail(): void
    {
        $this->invoice('2026-10-19');
        (new PaymentReminders)->send();
        (new PaymentReminders)->send();
        Carbon::setTestNow('2026-10-20 23:59:59');
        (new PaymentReminders)->send();

        $this->assertSame(['invoice_overdue'], $this->sent());
    }

    public function test_holds_a_due_date_moved_does_not_send_the_reminder_again(): void
    {
        $invoice = $this->invoice('2026-10-25');
        (new PaymentReminders)->send();
        $invoice->update(['due_at' => '2026-10-24']);
        (new PaymentReminders)->send();

        $this->assertSame(['invoice_payment_reminder'], $this->sent());
    }

    public function test_holds_paid_in_full_but_still_unpaid_status_is_not_reminded(): void
    {
        $invoice = $this->invoice('2026-10-19');
        DB::table('invoice_transactions')->insert(['invoice_id' => $invoice->id, 'amount' => 10, 'status' => 'succeeded', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(0, (new PaymentReminders)->send());
    }

    public function test_holds_draft_refunded_and_cancelled_are_not_reminded(): void
    {
        foreach ([Invoice::STATUS_DRAFT, Invoice::STATUS_REFUNDED, Invoice::STATUS_CANCELLED] as $status) {
            $invoice = $this->invoice('2026-10-19');
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status]);
        }

        $this->assertSame(0, (new PaymentReminders)->send());
    }

    public function test_holds_imported_invoices_long_overdue_are_not_mass_mailed_on_deploy(): void
    {
        config(['settings.cronjob_overdue_reminders' => '1,2']);
        foreach (['2026-10-15', '2026-09-30', '2026-01-01', '2024-05-05'] as $due) {
            $this->invoice($due, created: '2024-01-01');
        }

        $this->assertSame(0, (new PaymentReminders)->send());
    }

    public function test_holds_a_removed_payment_method_or_cancelled_subscription_is_not_paid_automatically(): void
    {
        $invoice = $this->invoice('2026-10-25', ['subscription_id' => 'sub_x']);
        $service = $invoice->items->first()->reference;
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        $agreement = BillingAgreement::create(['client_id' => $service->client_id, 'gateway_id' => $gateway->id, 'name' => 'PayPal', 'external_reference' => 'VAULT-H', 'type' => 'paypal']);
        $service->update(['subscription_id' => null, 'billing_agreement_id' => $agreement->id]); // subscription cancelled
        $agreement->delete(); // method removed

        $this->assertFalse(PaymentReminders::chargedOnItsOwn($invoice->fresh()));
    }

    public function test_holds_odd_setting_values(): void
    {
        $rule = collect(Settings::settings()['cronjob'])->firstWhere('name', 'cronjob_overdue_reminders')['validation'];
        foreach (['-1', '1,,2', 'a', '1;2'] as $bad) {
            $this->assertFalse(Validator::make(['v' => $bad], ['v' => $rule])->passes(), $bad);
        }
        foreach (['0' => [], '-1' => [], '1,,2' => [1, 2], ' 3 , 1 , 3 ' => [1, 3]] as $value => $days) {
            config(['settings.cronjob_overdue_reminders' => (string) $value]);
            $this->assertSame($days, PaymentReminders::overdueDays(), (string) $value);
        }
        config(['settings.cronjob_payment_reminder' => -1]);
        $this->invoice('2026-10-25');
        $this->assertSame(0, (new PaymentReminders)->send());
    }

    public function test_holds_staff_without_invoice_update_rights_cannot_send_email(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $viewer = User::factory()->create(['role_id' => Role::create(['name' => 'Viewer', 'permissions' => ['admin.invoices.viewAny', 'admin.invoices.view']])->id]);
        $invoice = $this->invoice('2026-10-25');

        try {
            Livewire::actingAs($viewer)->test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
                ->callAction('sendEmail', ['template' => 'invoice_overdue']);
        } catch (\Throwable) {
            // forbidden
        }

        $this->assertSame([], $this->sent());
    }

    public function test_holds_credit_applied_by_the_same_cron_run_stops_the_notice(): void
    {
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        $invoice = $this->invoice('2026-10-19');
        Credit::create(['client_id' => $invoice->client_id, 'currency_code' => 'USD', 'amount' => 50]);

        $this->artisan('app:cron-job');

        $this->assertNotContains('invoice_overdue', $this->sent());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'precondition: the credit paid it');
    }
}
