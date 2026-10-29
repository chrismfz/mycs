<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Import\WhmcsDomainImport;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * WHMCS's domains (D6): their registrar connections and TLDs, each domain with its dates, amount, addons and contacts,
 * the Openprovider handles that are their contact's, the notices WHMCS sent for the current expiry, and their invoice
 * lines (a paid renewal applied: never renewed again at the registrar).
 */
class ImportDomainsTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['settings.default_currency' => 'USD', 'settings.domain_reminder_days' => '30,7,1,-3']);
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
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    private function domain(int $id, string $name, string $status, string $registrar, array $extra = []): void
    {
        $row = array_merge(['userid' => $this->client->id, 'orderid' => 0, 'type' => 'Register', 'registrationdate' => '2020-03-01', 'domain' => $name,
            'firstpaymentamount' => 10, 'recurringamount' => 12.5, 'registrar' => $registrar, 'registrationperiod' => 1, 'expirydate' => '2027-03-01',
            'status' => $status, 'nextduedate' => '2027-03-01', 'additionalnotes' => '', 'dnsmanagement' => 0, 'emailforwarding' => 0, 'idprotection' => 0,
            'donotrenew' => 0, 'created_at' => '2020-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00'], $extra);
        $columns = implode(', ', array_keys($row));
        $this->whmcs->prepare("INSERT INTO tbldomains (id, {$columns}) VALUES (?, " . implode(', ', array_fill(0, count($row), '?')) . ')')
            ->execute([$id, ...array_values($row)]);
    }

    /** An Openprovider customer as the WHMCS module keeps it: an object of its own class (not loaded here). */
    private static function customer(string $email, string $handle): string
    {
        $data = serialize((object) ['email' => $email, 'handle' => $handle, 'additionalData' => (object) ['passportNumber' => 'X123']]);

        return str_replace('O:8:"stdClass"', 'O:12:"OP_Customer1"', $data);
    }

    private function run_(): array
    {
        return (new WhmcsDomainImport($this->whmcs))->run();
    }

    public function test_domains_come_with_their_connection_tld_dates_addons_and_contacts(): void
    {
        $this->domain(11, 'Example.COM.gr', 'Active', 'grepp', ['dnsmanagement' => 1, 'additionalnotes' => 'VIP']);
        $this->domain(12, 'shop.example.test.com', 'Active', 'openprovider', ['donotrenew' => 1, 'idprotection' => 1, 'type' => 'Transfer']);
        $this->domain(13, 'old.example.gr', 'Transferred Away', 'grepp');
        $this->domain(14, 'new.example.com', 'Pending', 'openprovider', ['type' => 'Transfer', 'expirydate' => '0000-00-00']);
        $this->domain(15, 'odd.example.com', 'Active', 'enom');
        $this->domain(16, 'nobody.example.com', 'Active', 'openprovider', ['userid' => 999999]);
        $this->domain(17, 'strange.example.com', '', '');
        $this->whmcs->exec("INSERT INTO tbldomainsadditionalfields (domainid, name, value) VALUES (11, 'Internationalized domain name Script (only for IDN domains)', 'el'),
            (11, 'Consent to Publish Domain Information', 'yes')");
        $this->whmcs->exec("INSERT INTO tbldomains_extra (domain_id, name, value) VALUES (12, 'openprovider_dnssecmanagement', 'on')");

        $result = $this->run_();

        $domains = Domain::query()->orderBy('id')->get()->keyBy('id');
        $this->assertSame([11, 12, 13, 14, 15, 17], $domains->keys()->all(), 'one of a customer not here skipped');
        $gr = $domains[11];
        $this->assertSame(['example', 'com.gr', 'example.com.gr'], [$gr->sld, $gr->tld, $gr->fqdn], 'the longest TLD known');
        $this->assertSame(DomainTld::query()->where('tld', 'com.gr')->value('id'), $gr->domain_tld_id);
        $this->assertSame(DomainStatus::Active, $gr->status);
        $this->assertSame(['2020-03-01', '2027-03-01', 'EUR', '12.50'], [$gr->registered_at->toDateString(), $gr->expires_at->toDateString(), $gr->currency_code, $gr->recurring_amount]);
        $this->assertTrue($gr->auto_renew && $gr->dns_management);
        $this->assertSame(['el', 'VIP'], [$gr->idn_script, $gr->notes]);
        $this->assertSame('yes', $gr->meta['whmcs_fields']['Consent to Publish Domain Information']);
        $primary = $this->client->primaryContact->id;
        $this->assertSame([$primary, $primary, null, null], [$gr->registrant_contact_id, $gr->admin_contact_id, $gr->tech_contact_id, $gr->billing_contact_id]);

        $this->assertFalse($domains[12]->auto_renew, 'WHMCS «do not renew»');
        $this->assertTrue($domains[12]->id_protection && $domains[12]->dnssec_enabled);
        $this->assertSame([DomainStatus::TransferredAway, DomainStatus::PendingTransfer], [$domains[13]->status, $domains[14]->status]);
        $this->assertNull($domains[14]->expires_at);

        $connections = DomainRegistrarConnection::query()->orderBy('registrar')->get();
        $this->assertSame(['grepp', 'openprovider'], $connections->pluck('registrar')->all());
        $this->assertTrue($connections->every(fn ($c) => !$c->is_active && $c->mode === 'off'), 'off and without credentials until staff enter them');
        $this->assertSame($connections->firstWhere('registrar', 'grepp')->id, $gr->registrar_connection_id);
        $this->assertSame($connections->firstWhere('registrar', 'grepp')->id, DomainTld::query()->where('tld', 'gr')->value('registrar_connection_id'));
        $this->assertNull($domains[15]->registrar_connection_id, 'enom: no driver here');
        $this->assertSame(DomainStatus::Cancelled, $domains[17]->status, 'no status in WHMCS: imported cancelled, listed');
        $notes = implode("\n", $result['notes']);
        $this->assertStringContainsString('«enom» in WHMCS has no driver here', $notes);
        $this->assertStringContainsString('Domain #17', $notes);
        $this->assertStringContainsString('Domain #16: its customer', $notes);
    }

    public function test_a_handle_is_its_roles_only_when_it_is_that_contact_and_nothing_else_of_it_is_kept(): void
    {
        $this->domain(21, 'mine.example.com', 'Active', 'openprovider');
        $this->domain(22, 'theirs.example.com', 'Active', 'openprovider');
        $this->whmcs->prepare("INSERT INTO wHandles (id, user_id, registrar, type, handle, data) VALUES (1, ?, 'openprovider', 'all', 'OW000001-GR', ?),
            (2, ?, 'openprovider', 'all', 'XX000002-GR', ?)")->execute([$this->client->id, self::customer('Owner@Example.test', 'OW000001-GR'),
            $this->client->id, self::customer('someone.else@example.test', 'XX000002-GR')]);
        $this->whmcs->exec("INSERT INTO wDomain_handle (type, domain_id, handle_id) VALUES ('registrant', 21, 1), ('admin', 21, 1), ('tech', 21, 1),
            ('registrant', 22, 2)");

        $this->run_();

        $mine = Domain::query()->find(21);
        $this->assertSame(['OW000001-GR', 'OW000001-GR', null], [$mine->registrant_handle, $mine->admin_handle, $mine->tech_handle]);
        $this->assertSame(['tech' => 'OW000001-GR'], $mine->meta['whmcs_handles'], 'a role with no contact here: kept for staff, not linked');
        $theirs = Domain::query()->find(22);
        $this->assertNull($theirs->registrant_handle, 'another person there: never paired with the contact here');
        $this->assertSame(['registrant' => 'XX000002-GR'], $theirs->meta['whmcs_handles']);
        $this->assertStringNotContainsString('X123', (string) DB::table('domains')->pluck('meta')->implode(' '), 'nothing of the customer object but its email');
    }

    public function test_the_notices_whmcs_sent_for_the_current_expiry_are_never_sent_again(): void
    {
        $this->domain(31, 'remind.example.com', 'Active', 'openprovider', ['expirydate' => '2026-11-20']);
        // This expiry: 30 and 10 days before (the 10 a day late); last year's expiry: history.
        $this->whmcs->exec("INSERT INTO tbldomainreminders (domain_id, date, recipients, type, days_before_expiry) VALUES
            (31, '2026-10-21', '', 1, 30), (31, '2026-11-11', '', 2, 10), (31, '2025-10-21', '', 1, 30)");

        $this->run_();

        $this->assertSame([[10, '2026-11-20'], [30, '2026-11-20']],
            DB::table('domain_reminders')->where('domain_id', 31)->orderBy('days')->get()->map(fn ($r) => [(int) $r->days, substr((string) $r->expires_on, 0, 10)])->all(),
            'WHMCS\'s 30 and 10 as sent; ours at or before the closest (30) too; 7, 1 and -3 still to come');
    }

    public function test_its_invoice_lines_a_paid_renewal_applied_with_its_period_never_renewed_again(): void
    {
        $this->domain(41, 'lines.example.com', 'Active', 'openprovider');
        $this->run_();
        $import = new WhmcsDomainImport($this->whmcs);
        (new ReflectionProperty($import, 'imported'))->setValue($import, null);
        $line = fn (array $row) => $import->line($row + ['relid' => 41, 'duedate' => '2026-03-01', 'invoice_datepaid' => '2026-02-20 09:00:00']);

        $paid = $line(['type' => 'Domain', 'description' => 'Ανανέωση Domain - lines.example.com - 1 Έτος/η (01/03/2026 - 28/02/2027)', 'invoice_status' => 'Paid']);
        $this->assertSame([Domain::class, 41, null, '2026-03-01', '2027-03-01', '2026-02-20 09:00:00'],
            [$paid['reference_type'], $paid['reference_id'], $paid['kind'], $paid['period_start'], $paid['period_end'], $paid['applied_at']]);
        $unpaid = $line(['type' => 'Domain', 'description' => 'Domain Renewal - lines.example.com - 2 Year/s', 'invoice_status' => 'Unpaid']);
        $this->assertSame([null, '2026-03-01', '2028-03-01', null], [$unpaid['kind'], $unpaid['period_start'], $unpaid['period_end'], $unpaid['applied_at']],
            'no dates: from its due date, for the years it says; paid later, renewed then (as WHMCS would)');
        $register = $line(['type' => 'DomainRegister', 'description' => 'Κατοχύρωση Domain', 'invoice_status' => 'Paid']);
        $this->assertSame([InvoiceItem::KIND_REGISTER, '2026-02-20 09:00:00'], [$register['kind'], $register['applied_at']]);
        $fee = $line(['type' => 'DomainRedemptionFee', 'description' => 'Redemption', 'invoice_status' => 'Cancelled']);
        $this->assertSame([InvoiceItem::KIND_CHARGE, null], [$fee['kind'], $fee['period_start']]);
        $this->assertNull($import->line(['type' => 'Domain', 'relid' => 999, 'description' => '']), 'a domain not here: no type, as before');
        $this->assertNull($import->line(['type' => 'Hosting', 'relid' => 41, 'description' => '']));

        // Through the importer: the line is on the domain, applied, and never one the payment renews again.
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'domains'))->setValue($command, $import);
        $reference = (new ReflectionMethod($command, 'lineReference'))->invoke($command, ['type' => 'Domain', 'relid' => 41, 'duedate' => '2026-03-01',
            'description' => 'Domain Renewal - lines.example.com - 1 Year/s (01/03/2026 - 28/02/2027)', 'invoice_status' => 'Paid', 'invoice_datepaid' => '2026-02-20']);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'currency_code' => 'EUR']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']); // as the importer writes it
        DB::table('invoice_items')->insert(['invoice_id' => $invoice->id, 'description' => 'x', 'price' => 12.5, 'created_at' => now(), 'updated_at' => now()] + $reference);
        $this->assertSame(0, DomainRenewalService::waitingLines()->count(), 'a paid WHMCS renewal is done already: never renewed again at the registrar');
        $hosting = (new ReflectionMethod($command, 'lineReference'))->invoke($command, ['type' => 'Hosting', 'relid' => 5, 'description' => '']);
        $this->assertSame([null, null, null], [$hosting['period_start'], $hosting['period_end'], $hosting['applied_at']], 'a service\'s line as before');
    }

    public function test_the_full_importer_runs_the_step_and_says_what_it_did(): void
    {
        $this->domain(51, 'step.example.com', 'Active', 'openprovider');
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        (new ReflectionMethod($command, 'importDomains'))->invoke($command);

        $this->assertSame(1, Domain::query()->count());
        $this->assertStringContainsString('Domains: 1 (0 skipped), 3 TLDs, 2 registrar connection(s)', $output->fetch());
    }

    /**
     * WHMCS's CNIC → Openprovider hook log (mod_domain_migration) is kept for staff, never trusted over WHMCS's registrar:
     * the 26 it logged as failed were all at Openprovider (read from the registrars, 2026-10-29: moved by hand since).
     * CNIC's paid renewals go on as transfers to Openprovider.
     */
    public function test_the_hooks_log_is_kept_but_whmcs_registrar_decides_and_the_move_goes_on_at_renewal(): void
    {
        $this->whmcs->exec("INSERT INTO tbldomainpricing VALUES (4, '.eu', 'cnic', 1, 0, 0, 0, 0, 40, 4)");
        $this->domain(61, 'moved.example.eu', 'Active', 'openprovider');
        $this->domain(62, 'stuck.example.eu', 'Active', 'openprovider');
        $this->domain(63, 'tried.example.eu', 'Active', 'openprovider');
        $this->domain(64, 'never.example.eu', 'Active', 'cnic');
        $this->whmcs->exec('CREATE TABLE mod_domain_migration (id INTEGER PRIMARY KEY, domain_id INT, domain TEXT, client_id INT, invoice_id INT, old_registrar TEXT,
            new_registrar TEXT, dry_run INT, epp TEXT, unlock_ok INT, idprotect_ok INT, transfer_submitted INT, status TEXT, error TEXT, created_at TEXT, updated_at TEXT)');
        $this->whmcs->exec("INSERT INTO mod_domain_migration (domain_id, old_registrar, new_registrar, dry_run, epp, status, created_at) VALUES
            (61, 'cnic', 'openprovider', 0, 'secret-epp-1', 'submitted', '2026-05-01 10:00:00'),
            (62, 'cnic', 'openprovider', 0, 'secret-epp-2', 'submitted', '2026-04-01 10:00:00'),
            (62, 'cnic', 'openprovider', 0, 'secret-epp-3', 'failed', '2026-05-01 10:00:00'),
            (63, 'cnic', 'openprovider', 0, '', 'triggered', '2026-05-01 10:00:00'),
            (64, 'cnic', 'openprovider', 1, '', 'failed', '2026-05-01 10:00:00')");

        $this->run_();

        $connections = DomainRegistrarConnection::query()->pluck('id', 'registrar');
        $this->assertSame([61 => $connections['openprovider'], 62 => $connections['openprovider'], 63 => $connections['openprovider'], 64 => $connections['cnic']],
            Domain::query()->orderBy('id')->pluck('registrar_connection_id', 'id')->all(), 'WHMCS\'s registrar, whatever the hook logged');
        $this->assertSame('failed', Domain::query()->find(62)->meta['whmcs_migration']['status'], 'its last real attempt, for staff');
        $this->assertArrayNotHasKey('whmcs_migration', Domain::query()->find(64)->meta ?? [], 'a dry run is no attempt');
        $this->assertStringNotContainsString('secret-epp', (string) DB::table('domains')->pluck('meta')->implode(' '), 'never its EPP code');
        $this->assertSame($connections['openprovider'], DomainRegistrarConnection::query()->find($connections['cnic'])->renew_transfer_to_id,
            'a paid renewal at CNIC moves it to Openprovider, as the hook did');
    }

    /** Through the full importer: the domain's lines on it; a line of an invoice WHMCS deleted is never one without an invoice. */
    public function test_the_lines_come_on_their_domain_and_a_deleted_invoices_line_never_comes(): void
    {
        $this->domain(71, 'billed.example.com', 'Active', 'openprovider');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT, duedate TEXT)');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (1, {$this->client->id}, '', '2026-02-01', '2026-03-01', '2026-02-20 09:00:00', 'Paid', 12.5, 0, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES
            (11, 1, {$this->client->id}, 'Domain', 71, 'Domain Renewal - billed.example.com - 1 Year/s (01/03/2026 - 28/02/2027)', 12.5, 0, '2026-03-01'),
            (12, 999, {$this->client->id}, 'Domain', 71, 'Domain Renewal - billed.example.com - 1 Year/s (01/03/2025 - 28/02/2026)', 12.5, 0, '2025-03-01')");
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        foreach (['importDomains', 'importInvoices', 'importInvoiceItems'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }

        $this->assertSame([11], DB::table('invoice_items')->pluck('id')->all(), 'the line of invoice #999 (deleted in WHMCS) is not imported');
        $line = InvoiceItem::query()->findOrFail(11);
        $this->assertSame([Domain::class, 71, '2026-03-01', '2027-03-01'], [$line->reference_type, (int) $line->reference_id,
            $line->period_start?->toDateString(), $line->period_end?->toDateString()]);
        $this->assertNotNull($line->applied_at);
        $this->assertStringContainsString('1 invoice line(s) of invoices deleted in WHMCS: not imported', $output->fetch());
    }
}
