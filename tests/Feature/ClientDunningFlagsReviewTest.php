<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Admin\ClientSummary;
use App\Services\Import\WhmcsSecondFactor;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Independent review of #455 (dunning_exempt, no_overdue_reminders, separate_invoices on clients and groups): the places
 * these behaviours live that the PR did not reach.
 */
class ClientDunningFlagsReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.cronjob_order_suspend' => 8, 'settings.cronjob_order_terminate' => 60, 'settings.cronjob_invoice' => 7,
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function exemptService(bool $viaGroup): Service
    {
        $client = User::factory()->create()->client();
        if ($viaGroup) {
            $client->forceFill(['client_group_id' => ClientGroup::query()->create(['name' => 'Exempt', 'discount_percent' => 0, 'dunning_exempt' => true])->id]);
        } else {
            $client->forceFill(['dunning_exempt' => true]);
        }
        $client->save();
        $product = $this->createProduct();

        // Suspended for non-payment before staff exempted the account (or its group), two periods behind.
        return Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-08-29', 'currency_code' => 'USD', 'price' => 10.00,
            'quantity' => 1, 'expires_at' => '2026-08-20']);
    }

    private function openInvoice(Service $service, string $start, string $end): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => $start]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10, 'period_start' => $start, 'period_end' => $end]);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => Carbon::parse($start)->subWeek()]);

        return $invoice->fresh();
    }

    /**
     * Service::stillOverdue() (used by RenewServiceService::activate) keeps a paid service suspended because «the next
     * run would suspend it again». For an exempt account or group the cron never will, so the payment must unsuspend it.
     */
    public function test_a_payment_unsuspends_the_service_of_an_exempt_account_even_if_still_behind(): void
    {
        foreach ([false, true] as $viaGroup) {
            $service = $this->exemptService($viaGroup);
            $first = $this->openInvoice($service, '2026-08-20', '2026-09-20');
            $this->openInvoice($service, '2026-09-20', '2026-10-20');

            (new RecordPaymentService)->handle($first, null, 10.0, transactionId: 'wire-' . (int) $viaGroup);

            $this->assertSame('2026-09-20', $service->fresh()->expires_at->toDateString());
            $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status,
                ($viaGroup ? 'group' : 'account') . ' exempt: the cron never suspends it again, so the payment unsuspends it');
        }
        Queue::assertPushed(UnsuspendJob::class, 2);
    }

    /** docs/importer.md now says WHMCS's `overideduenotices` lands in clients.no_overdue_reminders: the importer never reads it. */
    public function test_the_importer_carries_whmcs_disable_overdue_notices(): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $whmcs->exec('CREATE TABLE tblusers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, password TEXT,
            second_factor TEXT, second_factor_config TEXT, email_verified_at TEXT, created_at TEXT, updated_at TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT,
            tax_id TEXT, phonenumber TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT,
            taxexempt INT, separateinvoices INT, disableautocc INT, overideduenotices INT, credit REAL, currency INT, created_at TEXT, updated_at TEXT)');
        $whmcs->exec('CREATE TABLE tblusers_clients (id INTEGER PRIMARY KEY, auth_user_id INT, client_id INT, owner INT, permissions TEXT,
            last_login TEXT, created_at TEXT, updated_at TEXT)');
        $whmcs->prepare('INSERT INTO tblusers VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([7, 'Ada', 'Owner', 'ada@login.test', Hash::make('Whmcs-Pass-1'), '', null, null, '2024-01-01 00:00:00', '2025-01-01 00:00:00']);
        $whmcs->exec("INSERT INTO tblclients VALUES (300, 'Ada', 'Owner', '', 'billing@company.test', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'GR',
            0, 0, 0, 1, 0, 1, '2024-01-01 00:00:00', '2025-01-01 00:00:00')");
        $whmcs->exec("INSERT INTO tblusers_clients VALUES (1, 7, 300, 1, NULL, NULL, '2024-01-01 00:00:00', NULL)");

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->secondFactor = new WhmcsSecondFactor(null);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'importUsers'))->invoke($command);

        $this->assertTrue(Client::query()->findOrFail(300)->no_overdue_reminders, 'WHMCS «Disable overdue notices» kept');
    }

    /** The client's Summary lists its yes/no billing settings that differ from the default: the two new ones are missing. */
    public function test_the_summary_shows_the_new_flags(): void
    {
        $client = User::factory()->create()->client();
        $client->forceFill(['dunning_exempt' => true, 'no_overdue_reminders' => true])->save();
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);

        $settings = ClientSummary::for($client->fresh(), $staff)['settings'];

        $this->assertTrue($settings['dunning_exempt'] ?? null, 'staff see the account is never suspended by the cron');
        $this->assertTrue($settings['no_overdue_reminders'] ?? null, 'staff see it gets no overdue reminders');
    }
}
