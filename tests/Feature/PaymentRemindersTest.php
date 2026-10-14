<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Classes\Settings;
use App\Enums\InvoiceTransactionStatus;
use App\Mail\Mail;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Payment reminders (audit sweep Σ4): «Invoice Unpaid Reminder» days before the due date (or «payment due» for one
 * paid automatically), one overdue notice on each day the company sets, each once; and staff's «Send Email».
 */
class PaymentRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        Queue::fake(); // server jobs
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
        $svc = Service::factory()->create(array_merge(['user_id' => $user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => $due], $service));
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $svc->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => $created]);
        MailFacade::fake(); // forget the «new invoice» mail

        return $invoice->fresh();
    }

    /** @return list<string> */
    private function sent(): array
    {
        return MailFacade::queued(Mail::class)->map(fn (Mail $mail) => $mail->templateKey)->values()->all();
    }

    public function test_an_unpaid_invoice_is_reminded_days_before_its_due_date_once(): void
    {
        $invoice = $this->invoice('2026-10-25');

        $this->assertSame(1, (new PaymentReminders)->send());
        $this->assertSame(0, (new PaymentReminders)->send(), 'a second run sends nothing again');
        $this->artisan('app:cron-job');

        $this->assertSame(['invoice_payment_reminder'], $this->sent());
        $this->assertTrue(DB::table('invoice_reminders')->where(['invoice_id' => $invoice->id, 'kind' => 'due'])->exists());
    }

    public function test_not_on_the_day_it_was_issued_nor_too_early(): void
    {
        $this->invoice('2026-10-25', created: '2026-10-20'); // issued today: its own mail just went
        $this->invoice('2026-10-30'); // 10 days away

        $this->assertSame(0, (new PaymentReminders)->send());
    }

    public function test_an_invoice_paid_automatically_gets_the_payment_due_notice(): void
    {
        $this->invoice('2026-10-25', ['subscription_id' => 'sub_1']);

        (new PaymentReminders)->send();

        $this->assertSame(['card_payment_due'], $this->sent());
    }

    public function test_overdue_notices_on_each_day_and_the_last_says_when_it_is_suspended(): void
    {
        $this->invoice('2026-10-19'); // 1 day late today

        (new PaymentReminders)->send();
        Carbon::setTestNow('2026-10-21 09:00:00');
        (new PaymentReminders)->send(); // 2 days: no notice day
        Carbon::setTestNow('2026-10-22 09:00:00');
        (new PaymentReminders)->send(); // 3 days: the last

        $this->assertSame(['invoice_overdue', 'invoice_overdue'], $this->sent());
        $last = MailFacade::queued(Mail::class)->last();
        $this->assertStringContainsString('3 day(s) overdue', $last->renderedHtml);
        $this->assertStringContainsString('last notice', $last->renderedHtml);
        $this->assertStringContainsString('27/10/2026', $last->renderedHtml, 'suspended Suspend Days (8) after the due date');
        $this->assertStringNotContainsString('last notice', MailFacade::queued(Mail::class)->first()->renderedHtml);
    }

    public function test_a_missed_run_sends_only_the_latest_notice(): void
    {
        $invoice = $this->invoice('2026-10-17'); // 3 days late today: day 1 was missed

        (new PaymentReminders)->send();
        (new PaymentReminders)->send();

        $this->assertSame(['invoice_overdue'], $this->sent());
        $this->assertSame([1, 3], DB::table('invoice_reminders')->where('invoice_id', $invoice->id)->orderBy('days')->pluck('days')->map(fn ($day) => (int) $day)->all());
    }

    public function test_an_old_invoice_is_not_reminded_of_a_day_long_past(): void
    {
        $this->invoice('2026-09-01'); // imported, long overdue

        $this->assertSame(0, (new PaymentReminders)->send());
    }

    public function test_paid_cancelled_draft_or_paying_invoices_are_not_reminded(): void
    {
        $paid = $this->invoice('2026-10-19');
        $paid->transactions()->create(['amount' => 10]);
        $cancelled = $this->invoice('2026-10-19');
        $cancelled->update(['status' => Invoice::STATUS_CANCELLED]);
        $paying = $this->invoice('2026-10-25');
        $paying->transactions()->create(['amount' => 10, 'status' => InvoiceTransactionStatus::Processing]);

        $this->assertSame(0, (new PaymentReminders)->send());
    }

    public function test_zero_and_empty_turn_them_off(): void
    {
        config(['settings.cronjob_payment_reminder' => 0, 'settings.cronjob_overdue_reminders' => '']);
        $this->invoice('2026-10-25');
        $this->invoice('2026-10-19');

        $this->assertSame(0, (new PaymentReminders)->send());
        $this->assertSame([], PaymentReminders::overdueDays());
        config(['settings.cronjob_overdue_reminders' => ' 5, 1,1 ']);
        $this->assertSame([1, 5], PaymentReminders::overdueDays());
    }

    public function test_the_defaults_are_the_pilots(): void
    {
        $defaults = collect(Settings::settings()['cronjob'])->pluck('default', 'name');

        $this->assertSame(6, $defaults['cronjob_payment_reminder']);
        $this->assertSame('1,2', $defaults['cronjob_overdue_reminders']);
    }

    public function test_staff_send_the_invoice_modified_email(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = User::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $invoice = $this->invoice('2026-10-25');

        Livewire::actingAs($owner)->test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('sendEmail', ['template' => 'invoice_modified'])
            ->assertHasNoActionErrors();

        $this->assertSame(['invoice_modified'], $this->sent());
    }
}
