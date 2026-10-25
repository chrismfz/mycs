<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Import\WhmcsSecondFactor;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial review of #455 (feat/client-group-flags), written without the author's context: per-account and per-group
 * «no automatic suspension or termination», «no overdue reminders» and «separate invoices». Each test is a defect found:
 * it fails on the PR's head.
 */
class ClientDunningFlagsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MailFacade::fake();
        Queue::fake();
        foreach (['credits_enabled' => [false, 'boolean'], 'default_currency' => ['USD', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 7,
            'settings.cronjob_suspend_enabled' => true, 'settings.cronjob_terminate_enabled' => true,
            'settings.cronjob_dunning_days' => [1, 2, 3, 4, 5, 6, 7], 'settings.cronjob_dunning_from' => '00:00',
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An account in a group exempt from automatic suspension and termination (its own flag off). */
    private function exemptMember(): Client
    {
        $client = User::factory()->create()->client();
        $group = ClientGroup::query()->create(['name' => 'Exempt ' . uniqid(), 'discount_percent' => 0, 'dunning_exempt' => true]);
        $client->forceFill(['client_group_id' => $group->id])->save();

        return $client->fresh();
    }

    private function suspendedService(Client $client, string $expires, string $suspendedAt): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => $suspendedAt, 'suspend_reason' => 'Overdue on Payment',
            'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1]);
    }

    private function renewalInvoice(Service $service, string $start, string $end, float $price = 10.0): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $start]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => $price, 'period_start' => $start, 'period_end' => $end]);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => Carbon::parse($start)->subWeek()]);

        return $invoice->fresh();
    }

    /**
     * ADV-455-1 (Medium). Service::stillOverdue() (the «a payment does not unsuspend it, the next run would suspend it
     * again» rule, Δ3a) does not know the exemption. A service suspended for non-payment before its account was moved
     * into an exempt group (or before the account's own flag was set) is paid by its customer for one period; it is still
     * past Suspend Days, so the payment leaves it suspended and staff are told «an unsuspension alone is undone by the next
     * run», which is no longer true: the cron never suspends it again. An exempt customer who pays stays offline.
     */
    public function test_a_payment_unsuspends_a_service_of_an_exempt_account_even_if_still_past_suspend_days(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $service = $this->suspendedService($this->exemptMember(), '2026-08-20', '2026-08-23 00:05:00');
        $invoice = $this->renewalInvoice($service, '2026-08-20', '2026-09-20');

        (new RecordPaymentService)->handle($invoice, null, 10.0, transactionId: 'wire-exempt');

        $this->assertSame('2026-09-20', $service->fresh()->expires_at->toDateString(), 'precondition: the period is applied');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status,
            'exempt: the cron never suspends it again, so the payment should lift the suspension for non-payment');
    }

    /**
     * ADV-455-2 (Low-Medium). RenewalBilling's `$reissued` guard («never past Termination Days, when the same run ends it»)
     * assumes the run terminates the service; CronJob::terminable() now skips an exempt account's. A service suspended for
     * non-payment, past Termination Days, of an account that became exempt, whose overdue renewal staff re-issue («Cancel
     * Invoice» → «Re-issue», a wrong price): not terminated (exempt) and not billed (the guard). The customer has nothing
     * to pay and stays suspended indefinitely. Same shape as R2-1 of #434 (the hold), which was fixed for the hold only.
     */
    public function test_a_reissued_renewal_of_an_exempt_service_past_termination_days_is_billed_again(): void
    {
        Carbon::setTestNow('2026-10-26 11:00:00');
        $service = $this->suspendedService($this->exemptMember(), '2026-09-01', '2026-09-04 00:05:00');
        $invoice = $this->renewalInvoice($service, '2026-09-01', '2026-10-01', 12.0);
        // Staff: «Cancel Invoice» → «Re-issue» (the price was wrong).
        $invoice->keep_periods_billable = true;
        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'wrong price']);

        Carbon::setTestNow('2026-10-27 00:05:00');
        $this->artisan('app:cron-job');

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'precondition: exempt, not terminated');
        $open = Invoice::query()->where('status', Invoice::STATUS_UNPAID)
            ->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)->where('reference_id', $service->id))->count();
        $this->assertSame(1, $open, 'staff re-issued the renewal of an exempt, suspended service, yet it has nothing to pay');
    }

    /**
     * ADV-455-3 (Medium, cut-over). docs/importer.md now says the 18 clients' `overideduenotices` map to
     * clients.no_overdue_reminders and that «the importer takes them [the group flags] from tblclientgroups». The
     * importer reads neither: no column of tblclients beyond taxexempt/separateinvoices/disableautocc, no tblclientgroups,
     * no client group at all. After the cut-over the 18 clients get overdue notices again, and the 3 exempt groups' members
     * are suspended and terminated by the first run, unless staff redo it by hand (nothing tells them to).
     */
    public function test_the_importer_carries_the_whmcs_overdue_notices_and_group_flags(): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $whmcs->exec('CREATE TABLE tblusers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, password TEXT,
            second_factor TEXT, second_factor_config TEXT, email_verified_at TEXT, created_at TEXT, updated_at TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT,
            tax_id TEXT, phonenumber TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT,
            taxexempt INT, separateinvoices INT, disableautocc INT, overideduenotices INT, groupid INT, credit REAL, currency INT,
            created_at TEXT, updated_at TEXT)');
        $whmcs->exec('CREATE TABLE tblusers_clients (id INTEGER PRIMARY KEY, auth_user_id INT, client_id INT, owner INT, permissions TEXT,
            last_login TEXT, created_at TEXT, updated_at TEXT)');
        $whmcs->exec('CREATE TABLE tblclientgroups (id INTEGER PRIMARY KEY, groupname TEXT, groupcolour TEXT, discountpercent REAL,
            susptermexempt TEXT, separateinvoices TEXT)');
        $whmcs->exec("INSERT INTO tblclientgroups VALUES (4, 'Partners', '#ff0000', 0, 'on', 'on')");
        $user = $whmcs->prepare('INSERT INTO tblusers VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $user->execute([7, 'Ada', 'Owner', 'ada@login.test', Hash::make('Whmcs-Pass-1'), '', null, null, '2024-01-01 00:00:00', '2025-01-01 00:00:00']);
        $whmcs->exec("INSERT INTO tblclients VALUES (300, 'Ada', 'Owner', 'Ada Ltd', 'billing@company.test', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'GR',
            0, 0, 0, 1, 4, 0, 1, '2024-01-01 00:00:00', '2025-01-01 00:00:00')");
        $whmcs->exec("INSERT INTO tblusers_clients VALUES (1, 7, 300, 1, NULL, NULL, '2024-01-01 00:00:00', NULL)");

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->secondFactor = new WhmcsSecondFactor(null);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'importUsers'))->invoke($command);

        $client = Client::query()->findOrFail(300);
        $this->assertTrue((bool) $client->no_overdue_reminders, 'WHMCS «Disable overdue notices» (overideduenotices) not carried');
        $this->assertTrue($client->dunningExempt(), 'its WHMCS group is exempt from suspend/terminate (susptermexempt): not carried');
        $this->assertTrue($client->separatesInvoices(), 'its WHMCS group has «Separate invoices»: not carried');
    }
}
