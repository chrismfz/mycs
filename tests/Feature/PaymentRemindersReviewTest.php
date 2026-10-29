<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Console\Commands\CronJob;
use App\Enums\InvoiceTransactionStatus;
use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\PaymentReminders;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Independent review of 92c9748 (payment reminders). Each test states what the reviewer expects; a failing one is a
 * finding.
 */
class PaymentRemindersReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        Queue::fake();
        config(['settings.mail_disable' => false, 'settings.cronjob_payment_reminder' => 6, 'settings.cronjob_overdue_reminders' => '1,3',
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $due, array $service = [], string $created = '2026-10-10'): Invoice
    {
        $product = $this->createProduct();
        $user = User::factory()->create();
        $svc = Service::factory()->create(array_merge(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => $due], $service));
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $svc->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => $created]);
        DB::table('invoice_reminders')->where(['invoice_id' => $invoice->id, 'kind' => 'mailed'])->delete(); // mailed that day, not today
        MailFacade::fake();

        return $invoice->fresh();
    }

    /** @return list<string> */
    private function sent(): array
    {
        return MailFacade::queued(Mail::class)->map(fn (Mail $mail) => $mail->templateKey)->values()->all();
    }

    /**
     * R1, revised by PR H: the account's saved method is charged on the due date (AutoCharge), not when the invoice is
     * made. Before the due date «will be charged automatically on <due>» is true.
     */
    public function test_a_billing_agreement_invoice_is_told_it_will_be_charged_on_its_due_date(): void
    {
        $invoice = $this->invoice('2026-10-25');
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        $agreement = BillingAgreement::create(['client_id' => $invoice->client_id, 'gateway_id' => $gateway->id, 'name' => 'PayPal', 'external_reference' => 'VAULT-1', 'type' => 'paypal']);
        $this->assertSame($agreement->id, (int) $invoice->client->fresh()->default_billing_agreement_id, 'precondition: the account\'s method (decision 6)');

        (new PaymentReminders)->send();

        $this->assertSame(['card_payment_due'], $this->sent(), 'the cron charges it on its due date');
    }

    /**
     * R2. The last notice names the day the cron suspends. suspendable() suspends when expires_at < today - Suspend
     * Days, i.e. on due + Suspend Days + 1: the notice says one day earlier.
     */
    public function test_the_last_notice_names_the_day_the_cron_really_suspends(): void
    {
        $invoice = $this->invoice('2026-10-19');
        $serviceId = $invoice->items->first()->reference_id;
        Carbon::setTestNow('2026-10-22 09:00:00');
        (new PaymentReminders)->send();
        $html = MailFacade::queued(Mail::class)->last()->renderedHtml;

        $cron = app(CronJob::class);
        $suspendable = new ReflectionMethod($cron, 'suspendable');
        $firstDay = null;
        foreach (['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29'] as $day) {
            Carbon::setTestNow("{$day} 09:00:00");
            if ($suspendable->invoke($cron)->whereKey($serviceId)->exists()) {
                $firstDay = Carbon::parse($day)->format('d/m/Y');
                break;
            }
        }

        $this->assertSame('28/10/2026', $firstDay);
        $this->assertStringContainsString($firstDay, $html, 'the notice names the day the cron suspends');
    }

    /** R3. A held service (suspend_hold_until) is not suspended by the cron: the last notice must not threaten a date. */
    public function test_a_held_service_is_not_threatened_with_a_suspension_date(): void
    {
        $this->invoice('2026-10-17', ['suspend_hold_until' => '2026-12-31']);

        (new PaymentReminders)->send(); // 3 days late: the last notice

        $html = MailFacade::queued(Mail::class)->last()->renderedHtml;
        $this->assertStringNotContainsString('25/10/2026', $html, 'held until 31/12: the cron will not suspend it on 25/10');
    }

    /**
     * R4. Staff move the due date later (an extension): the new due date gets its overdue notice. The dedupe key
     * (invoice_id, kind, days) ignores the due date, so the customer is never told again.
     */
    public function test_a_due_date_moved_later_gets_its_overdue_notice_again(): void
    {
        $invoice = $this->invoice('2026-10-19');
        (new PaymentReminders)->send(); // day 1
        $invoice->update(['due_at' => '2026-10-30']); // staff gave ten more days

        Carbon::setTestNow('2026-10-31 09:00:00');
        $this->assertSame(1, (new PaymentReminders)->send(), 'one day late on its new due date');
    }

    /** R4b. Same for the reminder before the due date. */
    public function test_a_due_date_moved_later_gets_its_reminder_again(): void
    {
        $invoice = $this->invoice('2026-10-25');
        (new PaymentReminders)->send();
        $invoice->update(['due_at' => '2026-11-10']);

        Carbon::setTestNow('2026-11-05 09:00:00');
        $this->assertSame(1, (new PaymentReminders)->send());
    }

    /**
     * R5. A partly paid invoice: the notice asks for what is left, not the total (the customer pays the total again
     * and the difference becomes an overpayment/credit).
     */
    public function test_a_partly_paid_invoice_is_reminded_of_what_is_left(): void
    {
        $invoice = $this->invoice('2026-10-19');
        $invoice->transactions()->create(['amount' => 4, 'status' => InvoiceTransactionStatus::Succeeded]);

        (new PaymentReminders)->send();

        $html = MailFacade::queued(Mail::class)->last()->renderedHtml;
        $this->assertStringContainsString('6.00', $html, 'remaining 6.00 of 10.00');
    }

    /**
     * R6 (question). An «add funds» invoice (Credit line, due the day it is made) that the customer abandoned at the
     * gateway gets overdue notices, the last one «This is our last notice». Nothing is owed: it is an offer to buy credit.
     */
    public function test_an_abandoned_credit_deposit_gets_no_overdue_notices(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-19']);
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Credit deposit', 'quantity' => 1, 'price' => 50]);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => '2026-10-19']);
        DB::table('invoice_reminders')->where(['invoice_id' => $invoice->id, 'kind' => 'mailed'])->delete(); // mailed that day, not today
        MailFacade::fake();

        $this->assertSame(0, (new PaymentReminders)->send());
    }

    /** R7. «Send Email» → Overdue Notice on the due day itself says «0 day(s) overdue» (due_at is midnight: isPast()). */
    public function test_send_email_on_the_due_day_is_not_zero_days_overdue(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->admin()->create();
        $invoice = $this->invoice('2026-10-20');

        // Fixed: on the due day the overdue notice is not offered.
        $this->livewireAs($owner)->test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('sendEmail', ['template' => 'invoice_overdue'])
            ->assertHasActionErrors(['template']);

        $this->assertSame([], $this->sent());
    }

    /** R8. Queries per run grow with the invoices in the window (no eager loading), also for those already reminded. */
    public function test_a_run_with_many_invoices_already_reminded_does_not_query_per_invoice(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->invoice('2026-10-25');
        }
        (new PaymentReminders)->send();

        DB::enableQueryLog();
        (new PaymentReminders)->send(); // all reminded already: nothing to do
        $queries = count(DB::getQueryLog());

        $this->assertLessThan(20, $queries, "{$queries} queries for 20 invoices already reminded");
    }
}
