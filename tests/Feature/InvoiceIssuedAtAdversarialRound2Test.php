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
 * Adversarial review of INV-17 (PR #367), round 2: the fixes of «fix(invoices): the reviews of #367».
 */
class InvoiceIssuedAtAdversarialRound2Test extends TestCase
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
        config(['settings.mail_disable' => false, 'settings.cronjob_payment_reminder' => 6]);
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

    private function create(string $issued, string $due): Invoice
    {
        $undo = Repeater::fake();
        $this->livewireAs($this->staff)->test(CreateInvoice::class)->fillForm([
            'client_id' => $this->customer->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'send_email' => true, 'created_at' => $issued, 'due_at' => $due,
            'items' => [['price' => '10.00', 'quantity' => 1, 'description' => 'Hosting']],
        ])->call('create')->assertHasNoFormErrors();
        $undo();

        return Invoice::query()->latest('id')->firstOrFail();
    }

    /**
     * R2-1: ADV-2 is closed on the create page only. An invoice made (and mailed) today and then backdated on the EDIT
     * page, which INV-17 now allows while it is unpaid, is no longer «issued today» for PaymentReminders: the cron sends
     * its «due soon» reminder the same day as the «new invoice» mail, the double mail ADV-2 was about.
     * Fix: key the same-day guard on when the invoice went out (e.g. its insert or finalize moment), not on created_at,
     * or mark the reminder in EditInvoice too when it moves created_at of an invoice finalized today.
     */
    public function test_an_invoice_mailed_today_and_backdated_on_edit_is_not_reminded_today(): void
    {
        $invoice = $this->create('2026-10-23', '2026-10-26');

        $undo = Repeater::fake();
        $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->fillForm(['created_at' => '2026-10-20'])->call('save')->assertHasNoFormErrors();
        $undo();
        $this->assertSame('2026-10-20', $invoice->fresh()->created_at->toDateString());

        $this->assertSame(0, (new PaymentReminders)->send(), 'its «new invoice» mail went today: no reminder the same day');
    }

    /**
     * R2-2: the ADV-2 fix over-reaches. An invoice made today with today's date gets its «due soon» reminder from the
     * cron TOMORROW (PaymentReminders only skips the day it was issued). A backdated one made today now gets it NEVER:
     * the row CreateInvoice writes marks it sent for that due date, so tomorrow's run skips it as well. The two mails ADV-2
     * wanted to avoid were on the same day; the fix drops the reminder altogether, and the only thing that differs
     * between the two invoices is the date printed on them.
     * Fix: suppress only today's run (e.g. the guard on the moment it was inserted/finalized), not the reminder itself.
     */
    public function test_a_backdated_invoice_gets_its_due_soon_reminder_the_next_day_like_one_dated_today(): void
    {
        $today = $this->create('2026-10-23', '2026-10-27');
        $backdated = $this->create('2026-10-01', '2026-10-27');
        $this->assertSame(0, (new PaymentReminders)->send(), 'none on the day they were mailed');

        Carbon::setTestNow('2026-10-24 09:00:00');
        (new PaymentReminders)->send();

        $reminded = DB::table('invoice_reminders')->where('kind', 'due')->whereDate('sent_at', '2026-10-24')->pluck('invoice_id')->all();
        $this->assertContains($today->id, $reminded, 'control: the one dated today is reminded the next day');
        $this->assertContains($backdated->id, $reminded, 'the backdated one is reminded the next day too, not never');
    }
}
