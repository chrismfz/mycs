<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\IpPool;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Ip\IpAllocator;
use App\Services\Ops\RollbackReport;
use App\Services\Tax\EuVat;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Vies\Transport;
use MyCS\Vies\Vies;
use Tests\TestCase;

/**
 * Second adversarial review of #690 (mycs:rollback-report), lens «correctness of the rules and scale»: what the rule
 * «the MyCS's own = a created audit or created_at after --since» gets wrong, and how the queries hold up on the myip
 * instance (~32,500 invoices, 10^5+ lines and payments, a large audits table).
 */
class Adv690bScaleTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $cutover;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        $this->cutover = Carbon::parse('2026-11-14 22:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, list<list<string>>> */
    private function report(?Carbon $until = null): array
    {
        return collect((new RollbackReport($this->cutover, $until))->sections())->mapWithKeys(fn ($s) => [$s['key'] => $s['rows']])->all();
    }

    /** As the importer leaves it: no audits, WHMCS's dates (same as Adv690Test::imported). */
    private function imported(): array
    {
        Carbon::setTestNow('2026-11-14 21:00');
        Config::set('audit.console', false);
        $client = User::factory()->create(['created_at' => '2020-01-01'])->client();
        $client->forceFill(['created_at' => '2020-01-01'])->save();
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-12-01', 'currency_code' => 'USD', 'price' => 10.00, 'created_at' => '2021-01-01']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'created_at' => '2026-11-01', 'due_at' => '2026-11-20']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => 10, 'quantity' => 1, 'description' => 'Hosting', 'created_at' => '2026-11-01']);
        $domain = Domain::factory()->create(['client_id' => $client->id, 'sld' => 'example', 'fqdn' => 'example.test', 'expires_at' => '2027-01-01', 'created_at' => '2019-01-01']);
        ExtensionHelper::addPayment($invoice->id, null, 3.00, transactionId: 'WHMCS-OLD');

        Carbon::setTestNow('2026-11-15 10:00');
        Config::set('audit.console', true);

        return [$client, $service, $invoice, $domain];
    }

    /**
     * ADV-690b-S-1: a bank transfer that arrived on Friday, recorded by staff on Saturday with its day (Record payment →
     * «Paid at»): the transaction's created_at is Friday's, before --since. It has a «created» audit after --since, so it
     * is the MyCS's own and kept out of «changed»; but payments() also asks created_at ≥ since, so it is nowhere.
     */
    public function test_a_payment_recorded_after_the_cutover_with_an_earlier_paid_date_is_listed(): void
    {
        [, , $invoice] = $this->imported();

        $payment = (new RecordPaymentService)->recordByStaff($invoice, null, 7.00, 'BANK-FRI', paidAt: Carbon::parse('2026-11-13 12:00'));
        $this->assertTrue($payment->created_at->lt($this->cutover), 'dated the day the money arrived');

        $this->assertStringContainsString('BANK-FRI', json_encode($this->report()), 'the 7.00 taken by the MyCS goes back to WHMCS (AddInvoicePayment)');
    }

    /**
     * ADV-690b-S-2: staff move a WHMCS invoice's issue date to today (Edit invoice → Invoice date, INV-17). Its
     * created_at is now after --since, so the rule takes it for one the MyCS issued: «Invoices issued by the MyCS»
     * (CreateInvoice: a second invoice in WHMCS), and its own changes are hidden from «Invoices changed».
     */
    public function test_a_whmcs_invoice_redated_to_today_is_not_listed_as_issued_by_the_mycs(): void
    {
        [, , $invoice] = $this->imported();

        $invoice->created_at = now(); // EditInvoice::mutateFormDataBeforeSave, issuedAt(today) = now()
        $invoice->status = 'unpaid';
        $invoice->due_at = '2026-11-30';
        $invoice->save();

        $report = $this->report();
        $this->assertNotContains((string) $invoice->id, array_column($report['invoices_new'], 0), 'a WHMCS invoice: CreateInvoice would duplicate it');
        $this->assertContains((string) $invoice->id, array_column($report['invoices_changed'], 0), 'its due date and date go back with UpdateInvoice');
    }

    /**
     * ADV-690b-S-3: the issue date of a WHMCS invoice moved to an earlier day (not today): the audit's only field is
     * created_at, which is in NOISE, so the row is dropped. For an invoice created_at is its date (WHMCS `date`).
     */
    public function test_a_whmcs_invoice_issue_date_changed_is_listed(): void
    {
        [, , $invoice] = $this->imported();

        $invoice->created_at = Carbon::parse('2026-10-25'); // EditInvoice: an earlier day's start
        $invoice->save();

        $this->assertContains((string) $invoice->id, array_column($this->report()['invoices_changed'], 0), 'the invoice date in WHMCS (UpdateInvoice date)');
    }

    /**
     * ADV-690b-S-4: --until is honoured by the parts read with between(), not by those read from mine() (new invoices,
     * lines, clients, services, domains): an invoice issued after --until is still listed.
     */
    public function test_until_bounds_the_invoices_issued_by_the_mycs(): void
    {
        [$client] = $this->imported();

        Carbon::setTestNow('2026-11-17 10:00');
        $late = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        Carbon::setTestNow('2026-11-18 10:00');

        $report = $this->report(Carbon::parse('2026-11-16 22:00'));
        $this->assertNotContains((string) $late->id, array_column($report['invoices_new'], 0), 'issued after --until');
        $this->assertNotContains((string) $client->id, array_column($report['clients_new'], 0));
    }

    /**
     * ADV-690b-S-5: an IP given to a WHMCS service (IpAllocator::assign): services.dedicated_ip is written by query and the
     * only audit is the custom «ip_assigned», which changed() leaves out (updated, deleted, restored only). WHMCS's
     * tblhosting.dedicatedip stays empty.
     */
    public function test_an_ip_assigned_to_a_whmcs_service_is_listed(): void
    {
        [, $service] = $this->imported();
        $pool = IpPool::query()->create(['name' => 'VPS', 'family' => 4, 'network' => '192.0.2.0/29', 'prefix' => 29, 'gateway' => '192.0.2.1']);
        $pool->add('192.0.2.0/29');

        $ip = IpAllocator::assign($service, $pool);
        $this->assertSame($ip->address, $service->fresh()->dedicated_ip);

        $this->assertStringContainsString($ip->address, json_encode($this->report()['services_changed']), 'the dedicated IP in WHMCS (UpdateClientProduct dedicatedip)');
    }

    /**
     * ADV-690b-S-6: vat:recheck runs from the schedule with audit.console off (it never turns it on, unlike CronJob,
     * SyncDomains): a primary contact's number VIES now says is invalid changes its VAT status with no audit, so
     * «Contacts changed» misses it (WHMCS keeps it tax exempt / reverse charged).
     */
    public function test_a_vat_status_changed_by_the_scheduled_recheck_is_listed(): void
    {
        config(['settings.tax_enabled' => true, 'settings.eu_vat_enabled' => true, 'settings.eu_vat_home_country' => 'EE',
            'settings.eu_vat_requester' => 'EE100000000', 'settings.system_email_address' => 'staff@example.test']);
        $this->app->bind(Vies::class, fn () => new Vies(new class implements Transport
        {
            public function postJson(string $url, array $body): array
            {
                return ['status' => 200, 'body' => json_encode(['valid' => false, 'name' => '---', 'address' => '---', 'requestIdentifier' => 'WAPI1'])];
            }
        }, 'EE100000000'));
        [$client] = $this->imported();
        Config::set('audit.console', false);
        $contact = $client->primaryContact()->firstOrFail();
        $contact->forceFill(['country' => 'DE', 'vat_number' => 'DE129273398', 'vat_status' => EuVat::STATUS_VALID, 'vat_checked_at' => '2026-08-01'])->save();
        $this->assertSame(0, DB::table('audits')->count(), 'as imported');

        $this->artisan('vat:recheck')->assertSuccessful(); // as the schedule runs it: audit.console as config/audit.php has it
        $this->assertSame(EuVat::STATUS_INVALID, Contact::query()->find($contact->id)->vat_status);

        $this->assertContains((string) $contact->id, array_column($this->report()['contacts_changed'], 0), 'the VAT status went invalid in the MyCS');
    }

    /**
     * ADV-690b-S-7: mine() is unbounded and changed()/linesOnWhmcsInvoices() bind it whole in whereNotIn (whereKey is
     * inlined, whereNotIn is not). MariaDB refuses a statement with more than 65,535 placeholders. With 70,000 lines
     * after --since (a --since given before the import finished, a rehearsal re-imported) the report fails instead of
     * answering. Kept to what the query log shows.
     */
    public function test_no_query_binds_more_than_mariadbs_placeholder_limit(): void
    {
        [, , $invoice] = $this->imported();
        $now = now()->toDateTimeString();
        foreach (array_chunk(range(1, 70_000), 1_000) as $chunk) {
            DB::table('invoice_items')->insert(array_map(fn () => ['invoice_id' => $invoice->id, 'description' => 'x', 'price' => 1, 'quantity' => 1,
                'created_at' => $now, 'updated_at' => $now], $chunk));
        }

        DB::enableQueryLog();
        try {
            $this->report();
        } catch (QueryException $e) {
            $this->fail('the report fails on its own query: ' . mb_substr($e->getMessage(), 0, 200));
        } finally {
            $max = collect(DB::getQueryLog())->max(fn ($q) => count($q['bindings']));
            DB::disableQueryLog();
        }
        $this->assertLessThanOrEqual(65_535, $max, 'MariaDB: «Prepared statement contains too many placeholders»');
    }

    /**
     * ADV-690b-S-8: mails() reads, for every client mailed, its five subjects and its Client again (2 queries per
     * client); credit() finds each Credit per audit. On a renewal day the cron mails thousands: the query count grows
     * with the rows instead of staying fixed.
     */
    public function test_the_query_count_does_not_grow_with_the_customers_mailed(): void
    {
        $this->imported();
        $count = function (int $clients): int {
            $ids = Client::factory()->count($clients)->create()->modelKeys();
            foreach ($ids as $id) {
                DB::table('email_logs')->insert(['client_id' => $id, 'subject' => 'Invoice created', 'to' => "c{$id}@example.test", 'body' => '-',
                    'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->report();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $few = $count(2);
        $many = $count(40); // 42 mailed in all
        $this->assertLessThan(10, $many - $few, "queries: {$few} with 2 clients mailed, {$many} with 42");
    }
}
