<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\PaymentReminders;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of INV-17 (PR #367): «Issued At» settable by staff on create and edit.
 */
class InvoiceIssuedAtAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 15:30:00');
        Mail::fake();
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $this->customer = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * ADV-1: the new «due date not before the issue date» rule also runs on an edit that does not touch either date.
     * The app itself issues unpaid invoices due before the day they are made (RenewalBilling bills a period that already
     * started with due_at = its start; MoveToClient keeps the old due date on a new invoice; WHMCS's late-generated
     * invoices are imported the same way). Staff can no longer save such an invoice at all (a line's description, a
     * cancellation) without moving one of its dates, which changes dunning or the invoice's year.
     * Fix: check the rule only when created_at or due_at changed from the stored values (or only on create).
     */
    public function test_an_unpaid_invoice_issued_after_its_due_date_still_saves_untouched(): void
    {
        $invoice = Invoice::query()->create(['client_id' => $this->customer->client()->id, 'currency_code' => 'USD',
            'due_at' => '2026-10-15', 'status' => Invoice::STATUS_UNPAID]); // billed late: issued today, due a week ago
        $invoice->items()->create(['description' => 'Hosting', 'price' => 10, 'quantity' => 1]);

        $undo = Repeater::fake();
        $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->call('save')->assertHasNoFormErrors();
        $undo();
    }

    /**
     * ADV-2: PaymentReminders sends no «due soon» reminder on the day an invoice is issued, because its own «new
     * invoice» mail went that day (`whereDate('created_at', '<', $today)`). A new invoice backdated by staff is not
     * «issued today» any more, so the cron sends the reminder the same day as the new-invoice mail: two mails for the
     * same invoice on the day it is made.
     * Fix: the guard needs the moment the invoice really went out (e.g. record the creation mail / a `due` reminder row
     * when a backdated invoice is created with its email, or key the guard on the insert time rather than created_at).
     */
    public function test_a_backdated_new_invoice_is_not_reminded_the_day_its_own_mail_went(): void
    {
        config(['settings.mail_disable' => false, 'settings.cronjob_payment_reminder' => 6]);

        $undo = Repeater::fake();
        $this->livewireAs($this->staff)->test(CreateInvoice::class)->fillForm([
            'client_id' => $this->customer->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'send_email' => true, 'created_at' => '2026-10-01', 'due_at' => '2026-10-26',
            'items' => [['price' => '10.00', 'quantity' => 1, 'description' => 'Hosting']],
        ])->call('create')->assertHasNoFormErrors();
        $undo();
        $this->assertSame('2026-10-01', Invoice::query()->sole()->created_at->toDateString());

        $this->assertSame(0, (new PaymentReminders)->send(), 'its «new invoice» mail went today: no reminder the same day');
    }
}
