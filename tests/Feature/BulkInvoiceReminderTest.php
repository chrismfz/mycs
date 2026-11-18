<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\ShowInvoices;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Mail\Mail;
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

/** «Send reminder» on the invoice lists (owner 2026-11-18): WHMCS's bulk «Send Reminder», as «Send Email» sends it. */
class BulkInvoiceReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        Queue::fake();
        config(['settings.mail_disable' => false, 'settings.cronjob_payment_reminder' => 6, 'settings.cronjob_overdue_reminders' => '1,3']);
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

        $sent = $this->sent();
        sort($sent);
        $this->assertSame(['invoice_overdue', 'invoice_payment_reminder'], $sent);

        // Counted as sent: the cron does not mail the same reminder again today.
        $this->assertTrue(DB::table('invoice_reminders')->where(['invoice_id' => $upcoming->id, 'kind' => 'due'])->exists());
        $this->assertSame([1, 3], DB::table('invoice_reminders')->where(['invoice_id' => $overdue->id, 'kind' => 'overdue'])->orderBy('days')->pluck('days')->map(fn ($d) => (int) $d)->all());
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
}
