<?php

namespace Tests\Feature;

use App\Admin\Actions\SendRemindersAction;
use App\Admin\Resources\ClientResource\Pages\ShowInvoices;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Enums\InvoiceTransactionStatus;
use App\Mail\Mail;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Role;
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
use Tests\TestCase;

/** «Send reminder» on the invoice lists (owner 2026-11-18): WHMCS's bulk «Send Reminder», by the cron's rules (PaymentReminders::sendNow), with the review's cases. */
class BulkInvoiceReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        Queue::fake();
        config(['settings.mail_disable' => false, 'settings.cronjob_payment_reminder' => 6, 'settings.cronjob_overdue_reminders' => '1,3',
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $due, string $status = 'unpaid'): Invoice
    {
        $product = $this->createProduct();
        $svc = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => $due]);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $svc->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => '2026-10-10']);
        DB::table('invoice_reminders')->where(['invoice_id' => $invoice->id, 'kind' => PaymentReminders::MAILED])->delete(); // mailed that day, not today
        if ($status !== 'unpaid') {
            $this->statusFixture($invoice->id, $status);
        }

        return $invoice->fresh();
    }

    /** @return list<string> */
    private function sent(): array
    {
        return MailFacade::queued(Mail::class)->map(fn (Mail $mail) => $mail->templateKey)->values()->all();
    }

    private function staff(array $permissions = ['*']): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Reminds ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    public function test_each_unpaid_invoice_gets_its_reminder_or_overdue_notice_and_the_others_are_skipped(): void
    {
        $upcoming = $this->invoice('2026-10-25');
        $overdue = $this->invoice('2026-10-17');
        $paid = $this->invoice('2026-10-25', 'paid');
        $cancelled = $this->invoice('2026-10-25', 'cancelled');
        MailFacade::fake();

        $this->livewireAs($this->staff())->test(ListInvoices::class)
            ->callTableBulkAction('sendReminder', [$upcoming, $overdue, $paid, $cancelled])
            ->assertHasNoTableBulkActionErrors()
            ->assertNotified('Reminders queued: 2');
        $this->assertTrue(DB::table('invoice_reminders')->where(['invoice_id' => $overdue->id, 'kind' => 'staff'])->exists());

        $sent = $this->sent();
        sort($sent);
        $this->assertSame(['invoice_overdue', 'invoice_payment_reminder'], $sent);

        // Counted as sent: the cron does not mail the same reminder again today.
        $this->assertTrue(DB::table('invoice_reminders')->where(['invoice_id' => $upcoming->id, 'kind' => 'due'])->exists());
        $this->assertSame([1, 3], DB::table('invoice_reminders')->where(['invoice_id' => $overdue->id, 'kind' => 'overdue'])->orderBy('days')->pluck('days')->map(fn ($d) => (int) $d)->all(), 'day 3 reached: the last notice');
        MailFacade::fake();
        (new PaymentReminders)->send();
        $this->assertSame([], $this->sent(), 'the cron sends nothing more today');
    }

    public function test_the_clients_invoices_tab_sends_them_too(): void
    {
        $invoice = $this->invoice('2026-10-25');
        MailFacade::fake();

        $this->livewireAs($this->staff())->test(ShowInvoices::class, ['record' => $this->user->client()->id])
            ->callTableBulkAction('sendReminder', [$invoice])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(['invoice_payment_reminder'], $this->sent());
    }

    public function test_staff_who_may_not_change_invoices_do_not_get_it(): void
    {
        $this->invoice('2026-10-25');

        $this->livewireAs($this->staff(['admin.invoices.viewAny', 'admin.invoices.view']))->test(ListInvoices::class)
            ->assertTableBulkActionHidden('sendReminder');
    }

    private function bulk(array $invoices): void
    {
        $this->livewireAs($this->staff())->test(ListInvoices::class)->callTableBulkAction('sendReminder', $invoices);
    }

    public function test_rev_closed_account_not_mailed(): void
    {
        $invoice = $this->invoice('2026-10-17');
        $this->user->client()->update(['closed_at' => now()]);
        MailFacade::fake();
        $this->bulk([$invoice->fresh()]);
        $this->assertSame([], $this->sent());
    }

    public function test_rev_add_funds_not_mailed(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-17']);
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Credit deposit', 'quantity' => 1, 'price' => 50]);
        MailFacade::fake();
        $this->bulk([$invoice->fresh()]);
        $this->assertSame([], $this->sent());
    }

    public function test_rev_processing_not_mailed(): void
    {
        $invoice = $this->invoice('2026-10-17');
        $invoice->transactions()->create(['amount' => 10, 'status' => InvoiceTransactionStatus::Processing]);
        MailFacade::fake();
        $this->bulk([$invoice->fresh()]);
        $this->assertSame([], $this->sent());
    }

    public function test_rev_no_overdue_reminders_respected(): void
    {
        $invoice = $this->invoice('2026-10-17');
        $this->user->client()->update(['no_overdue_reminders' => true]);
        MailFacade::fake();
        $this->bulk([$invoice->fresh()]);
        $this->assertSame([], $this->sent());
    }

    public function test_rev_far_future_bulk_suppresses_the_real_reminder(): void
    {
        $invoice = $this->invoice('2026-11-30');
        MailFacade::fake();
        $this->bulk([$invoice]);
        Carbon::setTestNow('2026-11-25 09:00:00');
        MailFacade::fake();
        (new PaymentReminders)->send();
        $this->assertSame(['invoice_payment_reminder'], $this->sent(), 'cron reminder 5 days before due');
    }

    /** Revised: on the last overdue day the bulk mail is the cron's last notice, with the day it suspends. */
    public function test_rev_bulk_on_last_day_sends_the_last_notice_with_its_suspension_date(): void
    {
        $invoice = $this->invoice('2026-10-17'); // 3 days late = last day (1,3)
        $suspendOn = PaymentReminders::suspendOn($invoice);
        $this->assertNotNull($suspendOn);
        MailFacade::fake();
        $this->bulk([$invoice]);
        $this->assertSame(['invoice_overdue'], $this->sent());
        $this->assertStringContainsString($suspendOn, MailFacade::queued(Mail::class)->last()->renderedHtml);
    }

    public function test_rev_mail_disabled_not_recorded(): void
    {
        config(['settings.mail_disable' => true]);
        $invoice = $this->invoice('2026-10-25');
        $this->bulk([$invoice]);
        $this->assertFalse(DB::table('invoice_reminders')->where('invoice_id', $invoice->id)->where('kind', '!=', PaymentReminders::MAILED)->exists());
    }

    public function test_a_second_press_the_same_day_sends_nothing_again(): void
    {
        $invoice = $this->invoice('2026-11-30');
        MailFacade::fake();
        $this->bulk([$invoice]);
        $this->bulk([$invoice]);
        $this->assertSame(['invoice_payment_reminder'], $this->sent());
    }

    public function test_an_invoice_mailed_today_is_not_reminded_the_same_day(): void
    {
        $invoice = $this->invoice('2026-10-25');
        PaymentReminders::mailedToday($invoice);
        MailFacade::fake();
        $this->bulk([$invoice]);
        $this->assertSame([], $this->sent());
    }

    public function test_more_than_the_cap_is_refused(): void
    {
        $invoices = [];
        for ($i = 0; $i <= SendRemindersAction::MAX; $i++) {
            $invoices[] = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-25']);
        }
        MailFacade::fake();
        $this->bulk($invoices);
        $this->assertSame([], $this->sent());
    }

    public function test_rev2_cron_notice_this_morning_then_bulk_does_not_mail_it_again(): void
    {
        $invoice = $this->invoice('2026-10-19'); // 1 day late: the cron's day-1 notice today
        MailFacade::fake();
        (new PaymentReminders)->send();
        $this->assertSame(['invoice_overdue'], $this->sent(), 'the cron mailed day 1');
        MailFacade::fake();
        $this->bulk([$invoice]);
        $this->assertSame([], $this->sent());
    }

    public function test_rev2_cron_due_soon_this_morning_then_bulk_does_not_mail_it_again(): void
    {
        Carbon::setTestNow('2026-10-19 09:00:00');
        $invoice = $this->invoice('2026-10-25');
        Carbon::setTestNow('2026-10-20 09:00:00');
        MailFacade::fake();
        (new PaymentReminders)->send();
        $this->assertSame(['invoice_payment_reminder'], $this->sent());
        MailFacade::fake();
        $this->bulk([$invoice]);
        $this->assertSame([], $this->sent());
    }

    /** Revised: long past the last day, a plain overdue notice, not «last notice» on every press. */
    public function test_rev2_long_past_last_day_is_not_the_last_notice(): void
    {
        $invoice = $this->invoice('2026-09-20'); // 30 days late
        MailFacade::fake();
        $this->bulk([$invoice]);
        $this->assertSame(['invoice_overdue'], $this->sent());
        $this->assertStringNotContainsString('last notice', MailFacade::queued(Mail::class)->last()->renderedHtml);
    }
}
