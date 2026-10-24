<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Support\Retention;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Adversarial review of #434 (fix/sweep-billing-chain): failing tests for what the review found. */
class SweepFixesAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MailFacade::fake();
        Queue::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 7,
            'settings.credits_enabled' => false, 'settings.cronjob_dunning_days' => [1, 2, 3, 4, 5, 6, 7], 'settings.cronjob_dunning_from' => '00:00',
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * A service WHMCS suspended for non-payment («Overdue on Payment»: the importer sets dunning_suspended_at), whose
     * WHMCS invoice staff cancelled there. The importer writes rows directly: the line has no period and is never marked
     * forgone. With nothing at its paid-until date, `$reissued` is true.
     *
     * @return array{Service, User}
     */
    private function importedSuspended(string $expires = '2026-09-01'): array
    {
        Carbon::setTestNow('2026-10-26 00:05:00');
        $product = $this->createProduct();
        $user = User::factory()->create();
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'suspended', 'dunning_suspended_at' => '2026-09-10 00:00:00', 'suspend_reason' => 'Overdue on Payment',
            'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1]);
        // As ImportFromWhmcs::importInvoices / importInvoiceItems write them (DB::table, no observer, no period).
        $invoiceId = DB::table('invoices')->insertGetId(['client_id' => $user->client()->id, 'status' => Invoice::STATUS_CANCELLED,
            'currency_code' => 'USD', 'due_at' => $expires, 'created_at' => '2026-08-25 00:00:00', 'updated_at' => '2026-08-25 00:00:00']);
        DB::table('invoice_items')->insert(['invoice_id' => $invoiceId, 'description' => 'Hosting (01/09/2026 - 30/09/2026)', 'price' => 10.00,
            'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id, 'created_at' => now(), 'updated_at' => now()]);

        return [$service->fresh(), $user];
    }

    /**
     * FINDING 1 (Medium). Decision A2: a suspended service is not billed again. The new `$reissued` branch reads «no live
     * line at its paid-until date» as «staff re-issued it», which is also every service imported suspended from WHMCS
     * whose overdue invoice was cancelled there (or never generated). The first daily run after the cut-over (or after
     * this deploy) bills each of them an old overdue period they were given up on, emails it and pays it from credit.
     * origin/main: not billed (green); this branch: billed (red).
     */
    public function test_an_imported_suspended_service_whose_whmcs_invoice_was_cancelled_is_not_billed_again(): void
    {
        config(['settings.cronjob_terminate_enabled' => false]); // else the same run terminates it and cancels what it billed
        [$service] = $this->importedSuspended();

        $this->artisan('app:cron-job');

        $billed = Invoice::query()->whereIn('status', Invoice::OPEN_STATUSES)
            ->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)->where('reference_id', $service->id))->count();
        $this->assertSame(0, $billed, 'a service suspended in WHMCS with its invoice cancelled there was billed again by the cron (A2)');
    }

    /**
     * FINDING 1c (Low-Medium, consequence). With termination on (the default) and the service past Termination Days, the
     * same daily run first bills the overdue period (`$reissued`), emails it, then terminates the service and cancels that
     * invoice: an invoice issued (a number, unless proforma) and its cancellation credit note, for a service ended minutes
     * later. Applies equally to a service whose renewal staff re-issued and that is past Termination Days.
     */
    public function test_the_run_that_terminates_a_service_did_not_first_bill_it_a_new_invoice(): void
    {
        [$service] = $this->importedSuspended();

        $this->artisan('app:cron-job');

        $this->assertNotSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'precondition: terminated by this run');
        $made = Invoice::query()->where('created_at', '>=', '2026-10-26 00:00:00')
            ->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)->where('reference_id', $service->id))->count();
        $this->assertSame(0, $made, 'the run billed (and emailed) a new invoice for a service it terminated minutes later');
    }

    /**
     * FINDING 1b (Medium, consequence). The same service, long suspended with termination off (a company that keeps
     * suspended accounts), and a customer with credit: the cron bills the oldest overdue period, credit pays it on
     * finish, `$paidSinceSuspended` then bills the next one the following night, and so on. The customer's credit is
     * spent month by month on periods of a service that stays suspended (still overdue after each one).
     */
    public function test_credit_is_not_drained_into_periods_of_a_service_that_stays_suspended(): void
    {
        config(['settings.credits_enabled' => true, 'settings.cronjob_terminate_enabled' => false]);
        [$service, $user] = $this->importedSuspended('2026-05-01');
        $user->client()->credits()->create(['currency_code' => 'USD', 'amount' => 100]);

        foreach (range(0, 3) as $day) {
            Carbon::setTestNow(Carbon::parse('2026-10-26 00:05:00')->addDays($day));
            $this->artisan('app:cron-job');
        }

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'fixture: still suspended (overdue since May)');
        $paid = Invoice::query()->where('status', Invoice::STATUS_PAID)
            ->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)->where('reference_id', $service->id))->count();
        $this->assertSame(0, $paid, "the cron spent the customer's credit on {$paid} period(s) of a service that stays suspended");
    }

    /**
     * FINDING 2 (Low). `toldOfSuspension()` reads the email log, which the cron prunes (cronjob_delete_email_logs,
     * default 90 days; #410). A service suspended (and told) more than 90 days ago, unsuspended by staff with «Send
     * Notification»: no mail, and staff are told «The customer was never told of this suspension», which is false.
     * The same for every service imported suspended from WHMCS (no MyCS log at all).
     */
    public function test_staff_unsuspend_still_mails_after_the_suspension_mail_was_pruned_from_the_log(): void
    {
        config(['settings.cronjob_terminate_enabled' => false]);
        Carbon::setTestNow('2026-10-26 10:00:00');
        $product = $this->createProduct();
        $user = User::factory()->create();
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'suspended', 'dunning_suspended_at' => '2026-07-01 00:05:00', 'suspend_reason' => 'Overdue on Payment',
            'expires_at' => '2026-06-28', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1]);
        $told = EmailLog::query()->create(['client_id' => $user->client()->id, 'subject' => 'Service suspended', 'to' => $user->email, 'body' => 'suspended',
            'template_key' => 'server_suspended', 'related_type' => Service::class, 'related_id' => $service->id]);
        $told->forceFill(['created_at' => '2026-07-01 00:06:00'])->save();
        $this->assertTrue($service->toldOfSuspension(), 'precondition: told on 01/07');

        Retention::pruneBefore('email_logs', now()->subDays(90)); // the cron's email_logs_deleted step, 90 days by default

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner ' . uniqid(), 'permissions' => ['*']])->id]);
        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changeStatus', ['action' => 'unsuspend', 'sendNotification' => true]);

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'precondition: unsuspended');
        $this->assertSame(1, EmailLog::query()->where('template_key', 'server_unsuspended')->where('related_id', $service->id)->count(),
            'told of the suspension in July, yet no «unsuspended» mail: its log was pruned after 90 days');
    }
}
