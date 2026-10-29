<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainRenewalService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #535 (WHMCS's domains, D6): what an import leaves for the billing, the renewals at the registrar,
 * the notices and the registrar connections. Data shapes from the local WHMCS copies (description formats, statuses);
 * names, emails and domains are example.test ones.
 */
class ImportDomainsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Cache::flush();
        config(['settings.default_currency' => 'USD', 'settings.domain_reminder_days' => '30,7,1,-3', 'settings.domain_billing_enabled' => true,
            'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false, 'audit.console' => true]);
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
            "INSERT INTO tbldomainpricing VALUES (1, '.gr', 'grepp', 1, 0, 0, 0, 15, 15, 1), (2, '.com.gr', 'grepp', 1, 0, 0, 0, 15, 15, 2),
                (3, '.com', 'openprovider', 1, 1, 1, 1, 30, 30, 3)",
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function domain(int $id, string $name, string $status, string $registrar, array $extra = []): void
    {
        $row = array_merge(['userid' => $this->client->id, 'orderid' => 0, 'type' => 'Register', 'registrationdate' => '2020-03-01', 'domain' => $name,
            'firstpaymentamount' => 10, 'recurringamount' => 12.5, 'registrar' => $registrar, 'registrationperiod' => 1, 'expirydate' => '2027-03-01',
            'status' => $status, 'nextduedate' => $extra['expirydate'] ?? '2027-03-01', 'additionalnotes' => '', 'dnsmanagement' => 0, 'emailforwarding' => 0,
            'idprotection' => 0, 'donotrenew' => 0, 'created_at' => '2020-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00'], $extra);
        $columns = implode(', ', array_keys($row));
        $this->whmcs->prepare("INSERT INTO tbldomains (id, {$columns}) VALUES (?, " . implode(', ', array_fill(0, count($row), '?')) . ')')
            ->execute([$id, ...array_values($row)]);
    }

    /** A WHMCS invoice with its lines: [id, type, relid, description, amount, duedate]. */
    private function invoice(int $id, string $status, ?string $datepaid, array $lines, ?int $userid = null): void
    {
        $this->whmcs->prepare('INSERT INTO tblinvoices VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0)')
            ->execute([$id, $userid ?? $this->client->id, '', '2026-01-01', '2026-01-15', $datepaid ?? '0000-00-00 00:00:00', $status,
                array_sum(array_column($lines, 4))]);
        foreach ($lines as [$lineId, $type, $relid, $description, $amount, $due]) {
            $this->whmcs->prepare('INSERT INTO tblinvoiceitems VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)')
                ->execute([$lineId, $id, $userid ?? $this->client->id, $type, $relid, $description, $amount, $due]);
        }
    }

    /** The full importer's domain, invoice and line steps, as it runs them. */
    private function import(): string
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        foreach (['importDomains', 'importInvoices', 'importInvoiceItems'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }

        return $output->fetch();
    }

    /** The cron's domain billing for one domain (CronJob: billDomainRenewal, then addStageFee). */
    private function bill(int $domainId): int
    {
        $billing = app(RenewalBilling::class);
        $made = DB::transaction(fn () => $billing->billDomainRenewal($domainId, now()->addDays(RenewalBilling::domainInvoiceDays())));
        DB::transaction(fn () => $billing->addStageFee($domainId));

        return $made;
    }

    /** @return list<array{0: string|null, 1: string|null, 2: int}> each renewal line of the domain: [start, end, invoice] */
    private function renewals(int $domainId): array
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domainId)->whereNull('kind')->orderBy('id')->get()
            ->map(fn (InvoiceItem $line) => [$line->period_start?->toDateString(), $line->period_end?->toDateString(), (int) $line->invoice_id])->all();
    }

    /**
     * ADV-535-1 (High, money). The years of a renewal are read from its description only as «Year» or «Έτ…» (Έτος/η):
     * WHMCS's other Greek template, «N Χρόνια» (myip: ~800 renewal lines, 38 paid ones whose N is not the domain's
     * registrationperiod today), falls back to the domain's current registrationperiod, and the description's own end date
     * is never read. A paid «2 Χρόνια (01/03/2025 - 28/02/2027)» on a domain now at 8 years becomes 2025-03-01 → 2033-03-01,
     * applied: DomainRenewalService::billedLine counts an applied line through the first half of its period, so the
     * renewal due on 2027-03-01 is never billed (nor reminded, nor renewed) and the domain lapses. Unpaid and paid after the
     * cut-over, the same line would buy up to 8 years at the registrar for 2 paid (renewLocked: min(lacking, paid years)).
     */
    public function test_adv_535_1_a_renewal_in_years_written_chronia_takes_the_years_of_its_description(): void
    {
        Carbon::setTestNow('2027-02-20 10:00:00');
        $this->domain(81, 'years.example.com', 'Active', 'openprovider', ['registrationperiod' => 8, 'expirydate' => '2027-03-01']);
        $this->invoice(1, 'Paid', '2025-02-20 09:00:00', [
            [11, 'Domain', 81, 'Ανανέωση Domain - years.example.com - 2 Χρόνια (01/03/2025 - 28/02/2027)', 25, '2025-03-01'],
        ]);

        $this->import();

        $this->assertSame([['2025-03-01', '2027-03-01', 1]], $this->renewals(81), 'the period its description says: two years, to the next expiry');
        $this->assertSame(1, $this->bill(81), 'the renewal due on 2027-03-01 is billed, as without the imported line');
    }

    /**
     * ADV-535-2 (High, money). A paid multi-year renewal whose expiry WHMCS moved by one year only: WHMCS's CNIC →
     * Openprovider hook replaced the renewal with a transfer, which adds one year (myip: 5 active domains, «2 Έτος/η»
     * paid in 2026, their WHMCS expiry 365 days before the period's end; one still Pending Transfer). Imported applied
     * with its two years, the line stops counting as billed past its middle (billedLine), which is the domain's expiry:
     * the cron bills the second year, already paid, again. Either the year not bought is renewed (the line waits for the
     * registrar-first renewal, which adopts it if the registrar has it) or staff are told; never billed twice.
     */
    public function test_adv_535_2_a_paid_second_year_the_hooks_transfer_did_not_add_is_never_billed_again(): void
    {
        Carbon::setTestNow('2027-07-10 10:00:00');
        $this->domain(82, 'hook.example.com', 'Active', 'openprovider', ['registrationperiod' => 2, 'type' => 'Transfer', 'expirydate' => '2027-07-22']);
        $this->invoice(2, 'Paid', '2026-06-24 11:00:00', [
            [21, 'Domain', 82, 'Ανανέωση Domain - hook.example.com - 2 Έτος/η (22/07/2026 - 21/07/2028)', 25, '2026-07-22'],
        ]);

        $this->import();
        $this->bill(82);

        $this->assertCount(1, $this->renewals(82), 'paid to 2028-07-22: the year 2027-07-22 → 2028-07-22 is not billed again');
    }

    /**
     * ADV-535-3 (High, money). A WHMCS redemption fee comes as kind «charge». The cron's stage fee
     * (RenewalBilling::addStageFee) looks for kind grace_fee / redemption_fee on the renewal's invoice, finds none, and adds
     * its own redemption fee to the open imported invoice that has WHMCS's already: the customer is billed the fee twice
     * (myip: 278 redemption fee lines in its history). Paid, the restore path (stageFeePaid) does not see the imported
     * fee either and holds the paid renewal for staff.
     */
    public function test_adv_535_3_an_imported_redemption_fee_is_never_billed_a_second_time(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->domain(83, 'redeem.example.com', 'Redemption', 'openprovider', ['expirydate' => '2026-09-01']);
        $this->invoice(3, 'Unpaid', null, [
            [31, 'Domain', 83, 'Domain Renewal - redeem.example.com - 1 Year/s (01/09/2026 - 31/08/2027)', 12.5, '2026-09-01'],
            [32, 'DomainRedemptionFee', 83, 'Domain Redemption Fee', 96.5, '2026-09-01'],
        ]);

        $this->import();
        Domain::query()->whereKey(83)->update(['redemption_fee_override' => '96.50']); // its TLD's fee, as WHMCS's
        $this->assertSame(DomainStatus::Redemption, Domain::query()->find(83)->status);
        $this->bill(83);

        $fees = InvoiceItem::query()->where('invoice_id', 3)->whereNotNull('kind')->pluck('kind')->all();
        $this->assertCount(1, $fees, 'one redemption fee on the invoice (WHMCS\'s), not a second one: ' . implode(', ', $fees));
    }

    /**
     * ADV-535-4 (Medium, registrar). A domain of a WHMCS module with no driver here (enom: myip 1 active) or with no
     * registrar (myip 1 in grace) comes «without a connection (by hand)», as importer.md says, but
     * Domain::effectiveRegistrarConnection() falls back to its TLD's connection (.com, .io → Openprovider). The sync then
     * reads it at Openprovider, which does not have it: noted «not ours» and, a day later on a production connection,
     * marked transferred away (auto-renew off, billing stops, open invoices cancelled); a paid renewal is tried there.
     */
    public function test_adv_535_4_a_domain_of_a_registrar_without_a_driver_is_never_managed_through_its_tlds_connection(): void
    {
        $this->domain(84, 'odd.example.com', 'Active', 'enom');
        $this->domain(85, 'blank.example.com', 'Grace', '');

        $this->import();

        $this->assertNull(Domain::query()->find(84)->effectiveRegistrarConnection()?->registrar, 'enom: by hand, never at Openprovider');
        $this->assertNull(Domain::query()->find(85)->effectiveRegistrarConnection()?->registrar, 'no registrar in WHMCS: by hand');
    }

    /**
     * ADV-535-5 (Medium, status). Two live WHMCS rows of one name: the first by id is kept live and the later one comes
     * cancelled. A name that lapsed (an old row left «Expired») and was registered again (a new row, Active) keeps the
     * stale one and cancels the current one: never billed, reminded or renewed, while the stale one is (its lastDay past:
     * a notice each night for staff). nixpal has Expired rows today.
     */
    public function test_adv_535_5_of_two_live_rows_of_a_name_the_active_one_stays_live(): void
    {
        $this->domain(86, 'again.example.com', 'Expired', 'openprovider', ['expirydate' => '2019-05-01', 'registrationdate' => '2018-05-01']);
        $this->domain(87, 'again.example.com', 'Active', 'openprovider', ['expirydate' => '2027-05-01', 'registrationdate' => '2021-05-01']);

        $this->import();

        $this->assertSame(DomainStatus::Active, Domain::query()->find(87)->status, 'the current registration, not the lapsed row before it');
    }

    /**
     * ADV-535-6 (Medium, personal data). The handle's object is read for its email only, but every WHMCS additional field
     * goes as it is into `domains.meta.whmcs_fields`, a plain JSON column: birth dates, passport and ID card numbers, the
     * Spanish NIF/NIE, tax ids (myip: Birthdate 4, passport 2, es_identificacion(_personal) 18, ID Form Number 3, Tax ID 17,
     * Registrant ID Number…). Not encrypted, and shown wherever meta is.
     */
    public function test_adv_535_6_identity_numbers_and_birth_dates_from_additional_fields_are_not_stored_in_plain_meta(): void
    {
        $this->domain(88, 'fields.example.com', 'Active', 'openprovider');
        $this->whmcs->exec("INSERT INTO tbldomainsadditionalfields (domainid, name, value) VALUES (88, 'Birthdate', '1980-02-03'),
            (88, 'passport', 'P9876543'), (88, 'es_identificacion_personal', '12345678Z'), (88, 'ID Form Number', 'AB1234567'),
            (88, 'Entity Type', 'Individual')");

        $this->import();

        $raw = (string) DB::table('domains')->where('id', 88)->value('meta');
        foreach (['1980-02-03', 'P9876543', '12345678Z', 'AB1234567'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, 'personal identity data in plain meta');
        }
    }

    /**
     * ADV-535-7 (Medium, money). A WHMCS renewal invoice cancelled for a period already due (the customer gave it up; the
     * domain now in its grace) comes without forgone_at. A cancel here marks such a line given up (InvoiceObserver,
     * Δ3a), so the cron never bills it again; the imported one is billed again at once after the cut-over, with its
     * reminders. None in today's copies (the cancelled ones are older periods), but the cut-over's copy decides.
     */
    public function test_adv_535_7_a_renewal_cancelled_in_whmcs_for_a_period_already_due_is_not_billed_again(): void
    {
        Carbon::setTestNow('2026-10-29 10:00:00');
        $this->domain(89, 'given-up.example.com', 'Grace', 'openprovider', ['expirydate' => '2026-10-10']);
        $this->invoice(4, 'Cancelled', null, [
            [41, 'Domain', 89, 'Ανανέωση Domain - given-up.example.com - 1 Έτος/η (10/10/2026 - 09/10/2027)', 12.5, '2026-10-10'],
        ]);

        $this->import();

        $this->assertSame(0, $this->bill(89), 'given up in WHMCS, as a cancel here (Δ3a): not billed again');
    }

    /**
     * ADV-535-8 (Medium, registrar). A paid renewal whose period WHMCS's expiry does not show (the registrar call failed
     * in WHMCS, or a hook transfer that did not finish: myip 12 live domains, their paid period ending 5 to 817 days past
     * their WHMCS expiry) is imported applied, «done at the registrar». Nothing renews it, the cron counts it billed, no
     * notice goes (paid), and the import lists nothing: if the registrar does not have it either, the domain lapses with
     * its year paid. Left waiting, the registrar-first renewal would adopt it where the registrar has it, or renew it.
     */
    public function test_adv_535_8_a_paid_period_its_expiry_does_not_show_is_renewed_or_listed(): void
    {
        Carbon::setTestNow('2026-10-29 10:00:00');
        $this->domain(90, 'lagging.example.com', 'Active', 'openprovider', ['expirydate' => '2026-11-01']);
        $this->invoice(5, 'Paid', '2026-10-01 09:00:00', [
            [51, 'Domain', 90, 'Domain Renewal - lagging.example.com - 1 Year/s (01/11/2026 - 31/10/2027)', 12.5, '2026-11-01'],
        ]);

        $output = $this->import();

        $waiting = DomainRenewalService::waitingLines()->whereKey(51)->exists();
        $this->assertTrue($waiting || str_contains($output, '#90') || str_contains($output, 'lagging.example.com'),
            'renewed (registrar first) or listed for staff, never applied silently: ' . $output);
    }

    /**
     * ADV-535-9 (Low, parity). «A line of an invoice WHMCS deleted is never one without an invoice» checks WHMCS's
     * invoice only: a line of an invoice the importer itself skips (its customer is not here, or has no currency) still
     * comes, on an invoice id that does not exist here. A domain's is not on its domain (the domain is skipped too), but
     * any line is counted where invoice_items are.
     */
    public function test_adv_535_9_a_line_of_an_invoice_the_importer_skipped_never_comes_without_its_invoice(): void
    {
        $this->invoice(6, 'Paid', '2026-02-01 09:00:00', [[61, 'Hosting', 5, 'Hosting', 10, '2026-02-01']], userid: 999999);

        // The importer runs with foreign_key_checks=0 (MariaDB), so there it is written; here SQLite's key refuses it.
        try {
            $this->import();
        } catch (Throwable $e) {
            $this->fail('the line of invoice #6, which the importer skipped, was written: ' . $e->getMessage());
        }

        $this->assertSame(0, DB::table('invoice_items')->whereNotIn('invoice_id', DB::table('invoices')->select('id'))->count(),
            'no line on an invoice that is not here');
    }

    /**
     * ADV-535-10 (Low, handles). The handles are read from wDomain_handle whatever the registrar of the handle (wHandles
     * .registrar) or of the domain: an Openprovider handle left on a domain that is now at grepp or CNIC (moved after it
     * was made) becomes its registrant handle here, a handle that registrar does not have. None in today's copies (all
     * on Openprovider's own domains).
     */
    public function test_adv_535_10_an_openprovider_handle_is_never_linked_on_another_registrars_domain(): void
    {
        $this->domain(91, 'moved.example.gr', 'Active', 'grepp');
        $data = str_replace('O:8:"stdClass"', 'O:12:"OP_Customer1"', serialize((object) ['email' => 'owner@example.test', 'handle' => 'OW000009-GR']));
        $this->whmcs->prepare("INSERT INTO wHandles (id, user_id, registrar, type, handle, data) VALUES (9, ?, 'openprovider', 'all', 'OW000009-GR', ?)")
            ->execute([$this->client->id, $data]);
        $this->whmcs->exec("INSERT INTO wDomain_handle (type, domain_id, handle_id) VALUES ('registrant', 91, 9)");

        $this->import();

        $this->assertNull(Domain::query()->find(91)->registrant_handle, 'an Openprovider handle on a grepp domain: kept for staff, not linked');
    }
}
