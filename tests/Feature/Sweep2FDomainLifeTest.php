<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Contracts\ChangesOwner;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainOwnerChange;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainLifecycle;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainOwnerChanges;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\Ordering\DomainOrderFulfilment;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial verification, flow f-domain-life (sweep 2): a .gr holder change that finds its domain busy, and WHMCS's
 * «Pending Registration» / «Pending Transfer» domains (already sent to the registrar there) on import. Example names only.
 */
class Sweep2FDomainLifeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * F-DOMAIN-LIFE-1: send() claims the change (due → sending) by a query, then, the domain busy, «puts it back» to due
     * through end() on the model read before the claim. That model's original status is already «due» and its sent_at
     * null, so nothing is dirty, save() writes nothing, and the row stays «sending»: never sent again by the cron (due()),
     * and later turned «unknown» by stuck() although the registry was never asked.
     */
    public function test_f_domain_life_1_a_paid_owner_change_that_finds_the_domain_busy_goes_back_to_due(): void
    {
        Queue::fake();
        Storage::fake(DomainOwnerChanges::DISK);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->storeSettings(['default_currency' => 'EUR', 'domain_gr_owner_change_price' => '24.80']);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $client = User::factory()->create()->clients()->firstOrFail();
        $holder = $client->ensurePrimaryContact();
        $renamed = $client->contacts()->create(['first_name' => 'Maria', 'last_name' => 'Example', 'company_name' => 'Example IKE',
            'email' => 'maria@example.test', 'country' => 'GR']);
        $domain = Domain::factory()->create(['client_id' => $client->id, 'sld' => 'example-busy', 'tld' => 'gr', 'fqdn' => 'example-busy.gr',
            'currency_code' => 'EUR', 'registrant_contact_id' => $holder->id, 'registrant_handle' => 'c60_old']);
        $asked = 0;
        $mock = Mockery::mock(DomainManagementService::class, [app(DomainRegistrarFactory::class), app(DomainSyncService::class), app(DomainLifecycle::class)])
            ->makePartial();
        $mock->shouldReceive('changesOwner')->andReturn(true);
        // As DomainManagementService::call() throws when another write holds Cache::lock("domain-registrar:{id}").
        $mock->shouldReceive('changeOwner')->andReturnUsing(function (Domain $domain) use (&$asked) {
            $asked++;

            throw new RuntimeException(__('domains.manage.busy', ['domain' => $domain->fqdn]));
        });
        $this->app->instance(DomainManagementService::class, $mock);

        $change = app(DomainOwnerChanges::class)->request($domain, $renamed, ChangesOwner::NAME, $staff, null, null, false, false);
        $invoice = $change->line()->firstOrFail()->invoice;
        (new RecordPaymentService)->handle($invoice, null, (string) $invoice->total, transactionId: 'tx-busy');
        $this->assertSame(DomainOwnerChange::STATUS_DUE, $change->fresh()->status, 'precondition: paid, due');

        $returned = app(DomainOwnerChanges::class)->send($change->id);

        $this->assertSame(1, $asked, 'precondition: the busy path was taken');
        $this->assertSame(DomainOwnerChange::STATUS_DUE, $returned, 'precondition: send() says it is due again');
        $row = DomainOwnerChange::query()->findOrFail($change->id);
        $this->assertSame(DomainOwnerChange::STATUS_DUE, $row->status,
            'the registry never asked (busy): the row should be due again for the cron, not left «sending»');
        $this->assertNull($row->sent_at, 'not sent: sent_at cleared');
        $this->assertTrue(DomainOwnerChanges::due()->whereKey($change->id)->exists(), 'the cron picks it up again');

        // What follows today: after the lock time it is handed to staff as «unknown» (maybe done at the registry).
        Carbon::setTestNow(now()->addSeconds(DomainManagementService::LOCK_SECONDS + 301));
        DomainOwnerChanges::stuck();
        $this->assertNotSame(DomainOwnerChange::STATUS_UNKNOWN, DomainOwnerChange::query()->findOrFail($change->id)->status,
            'a change never asked of the registry is never «unknown»');
    }

    // ---- F-DOMAIN-LIFE-2: the WHMCS import ------------------------------------------------------------------------------

    private PDO $whmcs;

    private Client $client;

    private function whmcs(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Cache::flush();
        config(['settings.default_currency' => 'USD', 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15,
            'settings.credits_auto_use' => false, 'audit.console' => true]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->client = User::factory()->create()->client();
        $this->client->primaryContact->forceFill(['email' => 'owner@example.test'])->save();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT, prefix TEXT, suffix TEXT, format INT, rate REAL, "default" INT)',
            "INSERT INTO tblcurrencies VALUES (1, 'EUR', '', '€', 3, 1, 1)",
            'CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)',
            "INSERT INTO tblclients VALUES ({$this->client->id}, 1)",
            'CREATE TABLE tblconfiguration (setting TEXT, value TEXT)',
            "INSERT INTO tblconfiguration VALUES ('DateFormat', 'DD/MM/YYYY')",
            'CREATE TABLE tbldomainpricing (id INTEGER PRIMARY KEY, extension TEXT, autoreg TEXT, eppcode INT, dnsmanagement INT, emailforwarding INT,
                idprotection INT, grace_period INT, redemption_grace_period INT, "order" INT)',
            "INSERT INTO tbldomainpricing VALUES (1, '.gr', 'grepp', 1, 0, 0, 0, 15, 15, 1), (3, '.com', 'openprovider', 1, 1, 1, 1, 30, 30, 3)",
            'CREATE TABLE tblpricing (id INTEGER PRIMARY KEY, type TEXT, relid INT, currency INT, tsetupfee REAL, msetupfee REAL, qsetupfee REAL, ssetupfee REAL,
                asetupfee REAL, bsetupfee REAL, monthly REAL, quarterly REAL, semiannually REAL, annually REAL, biennially REAL, triennially REAL)',
            'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, orderid INT, type TEXT, registrationdate TEXT, domain TEXT, firstpaymentamount REAL,
                recurringamount REAL, registrar TEXT, registrationperiod INT, expirydate TEXT, status TEXT, nextduedate TEXT, additionalnotes TEXT,
                dnsmanagement INT, emailforwarding INT, idprotection INT, donotrenew INT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE tbldomainsadditionalfields (id INTEGER PRIMARY KEY, domainid INT, name TEXT, value TEXT)',
            'CREATE TABLE tbldomains_extra (id INTEGER PRIMARY KEY, domain_id INT, name TEXT, value TEXT)',
            'CREATE TABLE wHandles (id INTEGER PRIMARY KEY, user_id INT, registrar TEXT, type TEXT, handle TEXT, data TEXT)',
            'CREATE TABLE wDomain_handle (id INTEGER PRIMARY KEY, type TEXT, domain_id INT, handle_id INT)',
            'CREATE TABLE tbldomainreminders (id INTEGER PRIMARY KEY, domain_id INT, date TEXT, recipients TEXT, type INT, days_before_expiry INT)',
            'CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
                status TEXT, total REAL, credit REAL, taxrate REAL)',
            'CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
                amount REAL, taxed INT, duedate TEXT)',
            'CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
                amountout REAL, fees REAL, transid TEXT)',
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    private function domainRow(int $id, string $name, string $status, string $type, string $registrar, int $orderId): void
    {
        $row = ['userid' => $this->client->id, 'orderid' => $orderId, 'type' => $type, 'registrationdate' => '2026-10-28', 'domain' => $name,
            'firstpaymentamount' => 10, 'recurringamount' => 12.5, 'registrar' => $registrar, 'registrationperiod' => 1, 'expirydate' => '0000-00-00',
            'status' => $status, 'nextduedate' => '2026-10-28', 'additionalnotes' => '', 'dnsmanagement' => 0, 'emailforwarding' => 0,
            'idprotection' => 0, 'donotrenew' => 0, 'created_at' => '2026-10-28 10:00:00', 'updated_at' => '2026-10-28 18:30:00'];
        $this->whmcs->prepare('INSERT INTO tbldomains (id, ' . implode(', ', array_keys($row)) . ') VALUES (?, ' . implode(', ', array_fill(0, count($row), '?')) . ')')
            ->execute([$id, ...array_values($row)]);
    }

    private function paidInvoice(int $id, int $lineId, string $type, int $relid, string $description): void
    {
        $this->whmcs->prepare('INSERT INTO tblinvoices VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0)')
            ->execute([$id, $this->client->id, '', '2026-10-28', '2026-10-28', '2026-10-28 18:00:00', 'Paid', 12.5]);
        $this->whmcs->prepare('INSERT INTO tblinvoiceitems VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)')
            ->execute([$lineId, $id, $this->client->id, $type, $relid, $description, 12.5, '2026-10-28']);
    }

    private function import(): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        foreach (['importDomains', 'importInvoices', 'importInvoiceItems'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
    }

    /**
     * F-DOMAIN-LIFE-2: WHMCS sets «Pending Registration» / «Pending Transfer» only once its registrar module's Register /
     * Transfer call went through (the request is at the registrar; e.g. a .gr pendingCreate awaiting approval). Imported
     * as never sent (no meta.sent_at, its paid order line not applied), it is due again here: DomainOrderFulfilment asks
     * the registrar to register (or transfer) it a second time, and the sync never reads it meanwhile.
     */
    public function test_f_domain_life_2_whmcs_pending_registration_and_pending_transfer_are_not_sent_again(): void
    {
        Carbon::setTestNow('2026-10-29 10:00:00');
        $this->whmcs();
        $order = Order::query()->create(['client_id' => $this->client->id, 'currency_code' => 'EUR']);
        $this->domainRow(301, 'awaiting-approval.gr', 'Pending Registration', 'Register', 'grepp', $order->id);
        $this->paidInvoice(31, 311, 'DomainRegister', 301, 'Domain Registration - awaiting-approval.gr - 1 Year/s');
        $this->domainRow(302, 'moving-in.com', 'Pending Transfer', 'Transfer', 'openprovider', $order->id);
        $this->paidInvoice(32, 321, 'DomainTransfer', 302, 'Domain Transfer - moving-in.com - 1 Year/s');

        $this->import();

        $this->assertSame(DomainStatus::PendingRegister, Domain::query()->findOrFail(301)->status, 'precondition: pending registration');
        $this->assertSame(DomainStatus::PendingTransfer, Domain::query()->findOrFail(302)->status, 'precondition: pending transfer');
        $this->assertSame($order->id, Domain::query()->findOrFail(301)->order_id, 'precondition: its order imported');
        $this->assertNotNull(InvoiceItem::query()->find(311), 'precondition: its paid register line imported');

        $due = DomainOrderFulfilment::due()->whereIn('id', [301, 302])->pluck('id')->sort()->values()->all();
        $this->assertSame([], $due, 'WHMCS already sent these to the registrar: never registered or transferred a second time here; due: '
            . json_encode($due) . ', register line applied_at = ' . json_encode(InvoiceItem::query()->find(311)?->applied_at));
        $this->assertNotEmpty(Domain::query()->findOrFail(301)->meta['sent_at'] ?? null, 'imported as sent, so the sync reads it (isSyncable)');
    }
}
