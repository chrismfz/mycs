<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\DebugLog;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\Registrars\CnicRegistrar;
use App\Services\Invoice\RecordPaymentService;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MyCS\Cnic\Command;
use MyCS\Cnic\Response;
use Tests\TestCase;

/**
 * Adversarial review of #297 (CentralNic Reseller, step 1: reads only). Written without the author's context; the
 * answers are shaped as CNIC's public command reference gives them (kb.centralnicreseller.com/api/api-command). Never
 * the real API: Http::fake only.
 *
 * Each test names its finding (CNIC-ADV-n). Those marked GUARD pass and stay as guards.
 */
class CnicRegistrarAdversarialTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{url: string, command: string, form: array<string, mixed>}> */
    private array $sent = [];

    /** @var array<string, string|\Closure> command => its answer (or a closure returning it) */
    private array $answers = [];

    private ?DomainRegistrarConnection $cnic = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Cache::flush();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            parse_str($request->body(), $form);
            $command = (string) ($form['s_command'] ?? '');
            $this->sent[] = ['url' => $request->url(), 'command' => $command, 'form' => $form];
            preg_match('/^COMMAND=(\w+)/', $command, $m);
            $answer = $this->answers[$m[1] ?? ''] ?? "[RESPONSE]\ncode = 500\ndescription = Invalid command name\nEOF\n";
            $answer = $answer instanceof \Closure ? $answer() : $answer;

            return Http::response($answer, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function credentials(): DomainRegistrarCredentials
    {
        return new DomainRegistrarCredentials(['username' => 'myipreseller', 'password' => 'Cnic-Secret-1'], sandbox: false);
    }

    /** A StatusDomain answer with these properties (each a value or a list). */
    private static function statusDomain(array $properties): string
    {
        $lines = ['[RESPONSE]', 'code = 200', 'description = Command completed successfully'];
        foreach ($properties as $name => $values) {
            foreach ((array) $values as $i => $value) {
                $lines[] = "property[{$name}][{$i}] = {$value}";
            }
        }
        $lines[] = 'EOF';

        return implode("\r\n", $lines) . "\r\n";
    }

    private static function base(array $override = []): array
    {
        return array_merge([
            'DOMAIN' => 'example.com', 'ROID' => '1234567_DOMAIN_COM-VRSN', 'STATUS' => ['ACTIVE'],
            'CREATED_DATE' => '2015-03-02 09:12:44.0', 'REGISTRATION_EXPIRATION_DATE' => '2031-03-02 09:12:44.0',
            'PAIDUNTIL_DATE' => '2031-03-02 09:12:44.0', 'RENEWALMODE' => 'AUTOEXPIRE', 'TRANSFER_LOCK' => '1',
            'NAMESERVER' => ['ns1.example.net', 'ns2.example.net'], 'OWNER_CONTACT' => 'P-ABC123', 'AUTH' => 's3cr3t-Auth!',
        ], $override);
    }

    private function sync(array $override = []): DomainSyncResult
    {
        $this->answers['StatusDomain'] = self::statusDomain(self::base($override));

        return (new CnicRegistrar)->syncDomain(Domain::factory()->make(['sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com']), $this->credentials());
    }

    private function connection(): DomainRegistrarConnection
    {
        if ($this->cnic === null) {
            $this->cnic = DomainRegistrarConnection::query()->create(['registrar' => 'cnic', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
            $this->cnic->storeCredentials(['username' => 'myipreseller', 'password' => 'Cnic-Secret-1'], ['password']);
        }

        return $this->cnic;
    }

    private function domain(array $attributes = []): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'com'], ['is_active' => true, 'grace_days' => 40, 'redemption_days' => 30]);
        foreach ([1 => '23.33', 2 => '46.66'] as $years => $price) {
            DomainTldPrice::query()->firstOrCreate(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => $years, 'currency_code' => 'EUR'], ['price' => $price]);
        }

        return Domain::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id, 'sld' => 'example', 'tld' => 'com',
            'fqdn' => 'example.com', 'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->connection()->id, 'status' => DomainStatus::Active,
            'expires_at' => '2027-03-02', 'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function syncThrough(Domain $domain): ?\Throwable
    {
        try {
            app(DomainSyncService::class)->sync($domain->fresh());
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    // ---------------------------------------------------------------- dates

    /**
     * CNIC-ADV-1 (High). Openprovider's datePart() rejects a zero or impossible date (its adversarial F3: «it became year
     * -1 and broke the sync»; D5a F5). CnicRegistrar::date() only checks the shape, so «0000-00-00» or «2031-02-30»
     * become our expiry. A domain imported without an expiry takes 0000-00-00 (year -1: expired at once, invoiced for a
     * period starting two thousand years ago); and the garbled PAIDUNTIL wins over a good registry date (the `??` is
     * applied before the date is checked).
     */
    public function test_adv1_a_zero_paid_until_never_becomes_our_expiry(): void
    {
        $result = $this->sync(['PAIDUNTIL_DATE' => '0000-00-00 00:00:00']);

        $this->assertNotSame('0000-00-00', $result->expiresAt, 'a zero date is no date');
    }

    public function test_adv1_an_impossible_paid_until_never_becomes_our_expiry(): void
    {
        $result = $this->sync(['PAIDUNTIL_DATE' => '2031-02-30 00:00:00.0', 'REGISTRATION_EXPIRATION_DATE' => '2031-02-28 00:00:00.0']);

        $this->assertNotSame('2031-02-30', $result->expiresAt, 'not a date: never stored (Carbon rolls it over into March)');
    }

    public function test_adv1_a_garbled_paid_until_falls_back_to_the_registry_expiry_not_to_nothing(): void
    {
        $result = $this->sync(['PAIDUNTIL_DATE' => 'n/a', 'REGISTRATION_EXPIRATION_DATE' => '2031-03-02 09:12:44.0']);

        // Today: null (PAIDUNTIL is non-empty, so the registry's date is never looked at), the domain's expiry frozen.
        $this->assertNotNull($result->expiresAt, 'a PAIDUNTIL that is not a date is as one not given');
    }

    public function test_adv1_a_domain_without_an_expiry_is_never_given_year_zero_by_a_sync(): void
    {
        $this->answers['StatusDomain'] = self::statusDomain(self::base(['PAIDUNTIL_DATE' => '0000-00-00 00:00:00']));
        $domain = $this->domain(['expires_at' => null]);

        $this->syncThrough($domain);

        $expires = $domain->fresh()->expires_at;
        $this->assertTrue($expires === null || $expires->year > 2000, 'expiry stored: ' . $expires?->toDateString());
    }

    /**
     * CNIC-ADV-2 (Medium). The import (listDomains) takes DOMAIN_REGISTRATION_EXPIRATION_DATE, the registry's date; the
     * sync takes PAIDUNTIL_DATE, what was paid. Where the registry runs ahead (a gTLD's registry auto-renew, a TLD renewed
     * at the registry by CNIC's own schedule), the import gives a later expiry, and the sync, forward only, never brings
     * it back (only a warning): the customer is invoiced a year late, while CNIC (AUTOEXPIRE) deletes the domain at its
     * paid-until date.
     */
    public function test_adv2_the_import_and_the_sync_give_the_same_expiry_for_the_same_domain(): void
    {
        $this->answers['QueryDomainList'] = "[RESPONSE]\ncode = 200\ndescription = OK\nproperty[DOMAIN][0] = example.com\n"
            . "property[DOMAIN_REGISTRATION_EXPIRATION_DATE][0] = 2028-03-02 09:12:44.0\nproperty[RENEWALMODE][0] = AUTOEXPIRE\n"
            . "property[TOTAL][0] = 1\nproperty[COUNT][0] = 1\nEOF\n";
        // The name's own record, as CNIC answers it to the sync below (the import reads each name: #297).
        $this->answers['StatusDomain'] = self::statusDomain(self::base(['REGISTRATION_EXPIRATION_DATE' => '2028-03-02 09:12:44.0', 'PAIDUNTIL_DATE' => '2027-03-02 09:12:44.0']));
        $imported = (new CnicRegistrar)->listDomains($this->credentials(), 0, 100)->records[0]->expiresAt;
        $synced = $this->sync(['REGISTRATION_EXPIRATION_DATE' => '2028-03-02 09:12:44.0', 'PAIDUNTIL_DATE' => '2027-03-02 09:12:44.0'])->expiresAt;

        $this->assertSame($synced, $imported, 'imported with the registry date, synced with the paid one: the import must not run ahead');
    }

    /**
     * CNIC-ADV-2b (Medium): the same, through the sync. Our expiry (from an import, or WHMCS) is the registry's 2028;
     * CNIC is paid to 2027. The sync warns but keeps 2028, so no renewal invoice is made before CNIC deletes it.
     */
    public function test_adv2_paid_until_earlier_than_ours_is_not_a_silent_warning_only(): void
    {
        $this->answers['StatusDomain'] = self::statusDomain(self::base(['REGISTRATION_EXPIRATION_DATE' => '2028-03-02 09:12:44.0',
            'PAIDUNTIL_DATE' => '2027-03-02 09:12:44.0']));
        $domain = $this->domain(['expires_at' => '2028-03-02']);

        $this->syncThrough($domain);

        $this->assertSame('2027-03-02', $domain->fresh()->expires_at->toDateString(),
            'CNIC deletes it at its paid-until (AUTOEXPIRE): billing on the registry date bills too late');
    }

    /**
     * CNIC-ADV-3 (Medium, semantics to confirm with CNIC). With RENEWALMODE AUTOEXPIRE, RENEWAL_DATE is the day CNIC
     * deletes the domain, which for several TLDs is before the paid-until/registry expiry. Billing on PAIDUNTIL alone
     * lets the customer's invoice fall after the day CNIC already deleted it. RENEWAL_DATE is read into the details but
     * never used.
     */
    public function test_adv3_an_autoexpire_domain_is_billed_by_the_day_cnic_deletes_it(): void
    {
        $result = $this->sync(['RENEWALMODE' => 'AUTOEXPIRE', 'RENEWAL_DATE' => '2031-02-10 09:12:44.0']);

        $this->assertLessThanOrEqual('2031-02-10', (string) $result->expiresAt, 'its expiry for billing is no later than the day CNIC deletes it');
    }

    // ---------------------------------------------------------------- status

    /**
     * CNIC-ADV-4 (Medium). status() calls anything without «redemption» or «pendingdelete» in STATUS Active: an empty
     * answer (no STATUS at all), an outgoing «pendingTransfer», a «clientHold»/«serverHold», and a redemption that CNIC
     * reports in RGP_STATUS (a property of StatusDomain) rather than STATUS. Active is the one status that moves a
     * pending order out of pending and lets the renewal service charge (renewable); «never a guess» says null here.
     */
    public function test_adv4_a_redemption_in_rgp_status_is_not_active(): void
    {
        $result = $this->sync(['STATUS' => ['clientTransferProhibited'], 'RGP_STATUS' => 'redemptionPeriod']);

        $this->assertNotSame(DomainStatus::Active, $result->status);
    }

    public function test_adv4_an_answer_without_a_status_is_not_active(): void
    {
        $answer = self::base();
        unset($answer['STATUS']);
        $this->answers['StatusDomain'] = self::statusDomain($answer);
        $result = (new CnicRegistrar)->syncDomain(Domain::factory()->make(['fqdn' => 'example.com']), $this->credentials());

        $this->assertNull($result->status, 'no status said: unknown, never Active');
    }

    public function test_adv4_a_pending_transfer_is_not_active(): void
    {
        $result = $this->sync(['STATUS' => ['ACTIVE', 'pendingTransfer']]);

        $this->assertNotSame(DomainStatus::Active, $result->status, 'a transfer under way is not a settled Active');
    }

    // ---------------------------------------------------------------- not ours

    /**
     * CNIC-ADV-5 (High). A domain CNIC deleted (AUTOEXPIRE at its RENEWAL_DATE, which may come before our expiry, and
     * nothing renews it from here: writes are not asked) answers 545 like one moved away: twice an hour apart and it is
     * marked transferred away (auto-renew off, its open renewal invoices cancelled), while it sits in redemption,
     * restorable, and the customer is never told. CNIC's 545 cannot tell «deleted» from «moved»; the last read said it
     * would be deleted on that day.
     */
    public function test_adv5_a_domain_cnic_deleted_on_its_renewal_date_is_not_marked_transferred_away(): void
    {
        $this->answers['StatusDomain'] = self::statusDomain(self::base(['PAIDUNTIL_DATE' => '2027-03-02 09:12:44.0',
            'REGISTRATION_EXPIRATION_DATE' => '2027-03-02 09:12:44.0', 'RENEWAL_DATE' => '2027-02-20 09:12:44.0', 'RENEWALMODE' => 'AUTOEXPIRE']));
        $domain = $this->domain(['expires_at' => '2027-03-02']);
        $this->assertNull($this->syncThrough($domain));

        $this->answers['StatusDomain'] = "[RESPONSE]\ncode = 545\ndescription = Entity reference not found\nEOF\n";
        Carbon::setTestNow('2027-02-21 10:00:00');
        $this->syncThrough($domain);
        Carbon::setTestNow('2027-02-21 12:00:00');
        $this->syncThrough($domain);

        $this->assertSame(DomainStatus::Active, $domain->fresh()->status, 'deleted at CNIC on its renewal date, not moved: staff decide');
    }

    /** CNIC-ADV-6 GUARD (passes): neither a login refused (531), a temporary 421, nor a proxy's page note it «not ours». */
    public function test_adv6_guard_only_a_545_says_not_ours(): void
    {
        $domain = $this->domain();
        foreach (["[RESPONSE]\ncode = 531\ndescription = Authorization failed\nEOF\n", "[RESPONSE]\ncode = 421\ndescription = Command failed due to server error\nEOF\n",
            '<html><body>502 Bad Gateway</body></html>', ''] as $i => $answer) {
            $this->answers['StatusDomain'] = $answer;
            Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00')->addHours(2 * $i));
            $this->assertNotNull($this->syncThrough($domain));
            $fresh = $domain->fresh();
            $this->assertSame(DomainStatus::Active, $fresh->status);
            $this->assertArrayNotHasKey('not_ours', $fresh->meta ?? []);
            $this->assertStringNotContainsString('Cnic-Secret-1', (string) $fresh->sync_error);
        }
    }

    // ---------------------------------------------------------------- secrets

    /**
     * CNIC-ADV-7 (Medium). With settings.debug on, RequestListener logs every HTTP answer of type text/plain through
     * Redact::text(), which masks keys only in JSON and known setting values. CNIC answers in plain text, and StatusDomain
     * (read on every sync) carries `property[AUTH][0] = <the EPP code>`: every domain's auth code lands in debug_logs,
     * which staff can read (D-11: an EPP code is a secret like a password). Openprovider's JSON `auth_code` is masked by
     * its key.
     */
    public function test_adv7_the_auth_code_never_reaches_the_debug_log(): void
    {
        config(['settings.debug' => true]);
        $this->sync();

        $logged = json_encode(DebugLog::query()->where('type', 'http')->pluck('context')->all());
        $this->assertNotSame('[]', $logged, 'the HTTP call was logged (the listener is on)');
        $this->assertStringNotContainsString('s3cr3t-Auth!', $logged);
    }

    /** CNIC-ADV-7b GUARD (passes): the reseller password, sent as s_pw, is masked there (a stored encrypted value). */
    public function test_adv7_guard_the_reseller_password_never_reaches_the_debug_log(): void
    {
        config(['settings.debug' => true]);
        $this->connection();
        $this->sync();

        $this->assertStringNotContainsString('Cnic-Secret-1', json_encode(DebugLog::query()->where('type', 'http')->pluck('context')->all()));
    }

    /**
     * CNIC-ADV-7d (Low). That mask is only Redact's list of stored secrets, which skips values under 6 characters; the
     * form key «s_pw» is not a secret name to Redact (pass|secret|token…). A short reseller password is logged as is.
     */
    public function test_adv7_a_short_reseller_password_never_reaches_the_debug_log_either(): void
    {
        config(['settings.debug' => true]);
        $this->connection()->storeCredentials(['password' => 'Pw5!x'], ['password']);
        $this->answers['StatusDomain'] = self::statusDomain(self::base());
        (new CnicRegistrar)->syncDomain(Domain::factory()->make(['fqdn' => 'example.com']),
            new DomainRegistrarCredentials(['username' => 'myipreseller', 'password' => 'Pw5!x'], sandbox: false));

        $this->assertStringNotContainsString('Pw5!x', json_encode(DebugLog::query()->where('type', 'http')->pluck('context')->all()));
    }

    /** CNIC-ADV-7c GUARD (passes): no auth code in the details, the stored meta or the sync error of a synced domain. */
    public function test_adv7_guard_the_auth_code_is_never_stored_on_the_domain(): void
    {
        $this->answers['StatusDomain'] = self::statusDomain(self::base(['X-SG-TRANSFER-OUT-CODE' => 'sg-out-code-9']));
        $domain = $this->domain();
        $this->assertNull($this->syncThrough($domain));

        $stored = json_encode($domain->fresh()->toArray());
        $this->assertStringNotContainsString('s3cr3t-Auth!', $stored);
        $this->assertStringNotContainsString('sg-out-code-9', $stored);
    }

    // ---------------------------------------------------------------- the wire

    /** CNIC-ADV-8 GUARD (passes): a lone CR, a CRLF, or a key with a line break never makes another command line. */
    public function test_adv8_guard_no_line_break_of_any_kind_makes_another_command_line(): void
    {
        $lines = Command::lines(['COMMAND' => 'StatusContact', 'CONTACT' => "P-1\rCOMMAND=DeleteDomain", "X\nCOMMAND" => 'DeleteDomain',
            'DOMAIN' => "a.com\r\nCOMMAND=DeleteDomain"]);

        $this->assertSame(1, preg_match_all('/^COMMAND=/m', str_replace("\r", "\n", $lines)));
        $this->assertCount(4, explode("\n", $lines));
    }

    /**
     * CNIC-ADV-9 (Low). Response::parse ignores the [i] of property[NAME][i] and appends: a column whose rows come out of
     * order, or one missing a row, shifts every later row onto the wrong domain (QueryDomainList's expiry of B on A). The
     * php-sdk keys by the index.
     */
    public function test_adv9_a_property_is_read_by_its_index(): void
    {
        $r = Response::parse("code = 200\nproperty[DOMAIN][0] = a.com\nproperty[DOMAIN][1] = b.com\n"
            . "property[DOMAIN_REGISTRATION_EXPIRATION_DATE][1] = 2029-01-01\nproperty[DOMAIN_REGISTRATION_EXPIRATION_DATE][0] = 2027-01-01\n");

        $this->assertSame('2027-01-01', $r->list('DOMAIN_REGISTRATION_EXPIRATION_DATE')[0], 'a.com\'s expiry is its own');
    }

    /**
     * CNIC-ADV-10 (Low). The import maps RENEWALMODE DEFAULT to auto-renew on; DEFAULT is the account's default, which a
     * reseller renewing by hand has as AUTOEXPIRE. Not known: null («confident mapping only»).
     */
    public function test_adv10_renewalmode_default_is_not_known_auto_renew(): void
    {
        $this->answers['QueryDomainList'] = "[RESPONSE]\ncode = 200\ndescription = OK\nproperty[DOMAIN][0] = alpha.com\nproperty[RENEWALMODE][0] = DEFAULT\nproperty[TOTAL][0] = 1\nEOF\n";
        $this->answers['StatusDomain'] = self::statusDomain(self::base(['RENEWALMODE' => 'DEFAULT'])); // the name's own record (#297)

        $this->assertNull((new CnicRegistrar)->listDomains($this->credentials(), 0, 100)->records[0]->autoRenew);
    }

    /** CNIC-ADV-11 GUARD (passes): the list's pager counts every row it returned, a skipped one too, and a total that is not a number is unknown. */
    public function test_adv11_guard_the_list_counts_raw_rows_and_an_odd_total_is_unknown(): void
    {
        $this->answers['QueryDomainList'] = "[RESPONSE]\ncode = 200\ndescription = OK\nproperty[DOMAIN][0] = alpha.com\nproperty[DOMAIN][1] = nodot\n"
            . "property[DOMAIN][2] = beta.co.uk\nproperty[TOTAL][0] = -1\nEOF\n";
        $this->answers['StatusDomain'] = self::statusDomain(self::base()); // each name's own record (#297)

        $page = (new CnicRegistrar)->listDomains($this->credentials(), 200, 0);

        $this->assertSame([3, null, 2], [$page->rawCount, $page->total, count($page->records)]);
        $this->assertSame(['beta', 'co.uk'], [$page->records[1]->sld, $page->records[1]->tld]);
        $this->assertStringContainsString("FIRST=200\nLIMIT=1", $this->sent[0]['command'], 'never a LIMIT of 0 (all at once)');
    }

    // ---------------------------------------------------------------- renewals

    private function paidRenewal(Domain $domain): InvoiceItem
    {
        Queue::fake();
        config(['audit.console' => true, 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false]);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $line = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->latest('id')->firstOrFail();
        (new RecordPaymentService)->handle($line->invoice, null, (float) $line->invoice->fresh()->total, transactionId: 'tx-' . $line->id);

        return $line->fresh();
    }

    /**
     * CNIC-ADV-12 GUARD (passes): a paid renewal at CNIC sends nothing but reads, opens one Pending Module Action for staff
     * (told once), and «Renewed by hand» applies it only once CNIC shows the new paid-until.
     */
    public function test_adv12_guard_a_paid_renewal_goes_to_staff_once_and_by_hand_only_as_cnic_shows_it(): void
    {
        $this->answers['StatusDomain'] = self::statusDomain(self::base(['PAIDUNTIL_DATE' => '2026-11-01 00:00:00.0', 'REGISTRATION_EXPIRATION_DATE' => '2026-11-01 00:00:00.0']));
        $domain = $this->domain(['expires_at' => '2026-11-01']);
        $line = $this->paidRenewal($domain);
        $renewals = app(DomainRenewalService::class);
        // Step 3 renews at CNIC (#301): here CNIC refuses it, so it is staff's (the guard's intent: by hand only as CNIC shows).
        $this->answers['RenewDomain'] = "[RESPONSE]\ncode = 549\ndescription = Command failed; renewal not allowed\nEOF\n";

        $this->assertSame(DomainRenewalService::FAILED, $renewals->renewLine($line->id));
        $commands = array_map(fn ($s) => strtok($s['command'], "\n"), $this->sent);
        $this->assertSame([], array_values(array_diff(array_unique($commands), ['COMMAND=StatusDomain', 'COMMAND=RenewDomain'])), 'only reads and the refused renewal reached CNIC');
        $row = ModuleAction::query()->where('domain_id', $domain->id)->sole();
        $this->assertSame(ModuleAction::STATUS_OPEN, $row->status);

        try {
            $row->markResolved(null);
            $this->fail('«renewed by hand» while CNIC does not show it');
        } catch (\RuntimeException) {
        }
        $this->assertNull($line->fresh()->applied_at);

        $this->answers['StatusDomain'] = self::statusDomain(self::base(['PAIDUNTIL_DATE' => '2027-11-01 00:00:00.0', 'REGISTRATION_EXPIRATION_DATE' => '2027-11-01 00:00:00.0']));
        $row->fresh()->markResolved(null);
        $this->assertNotNull($line->fresh()->applied_at);
        $this->assertSame('2027-11-01', $domain->fresh()->expires_at->toDateString());
    }

    /**
     * CNIC-ADV-13 (Low). Every renewal is «not asked» at CNIC, so each daily cron (and each Retry) re-reads CNIC and
     * writes a new failed «renew» registrar log for every paid CNIC renewal waiting for staff, for as long as it waits:
     * a log row a day per domain that says nothing new, and the domain's log fills with «failed renewals» that never were.
     */
    public function test_adv13_a_renewal_is_charged_and_logged_once_whatever_the_runs(): void
    {
        // Step 3 renews at CNIC (#301): the guard\'s intent kept: one renewal logged and charged, never one a run.
        $this->answers['StatusDomain'] = self::statusDomain(self::base(['PAIDUNTIL_DATE' => '2026-11-01 00:00:00.0', 'REGISTRATION_EXPIRATION_DATE' => '2026-11-01 00:00:00.0']));
        $domain = $this->domain(['expires_at' => '2026-11-01']);
        $line = $this->paidRenewal($domain);
        $this->answers['RenewDomain'] = "[RESPONSE]\ncode = 200\ndescription = Command completed successfully\nEOF\n";
        $renewals = app(DomainRenewalService::class);
        $renewals->renewLine($line->id);
        $this->answers['StatusDomain'] = self::statusDomain(self::base(['PAIDUNTIL_DATE' => '2027-11-01 00:00:00.0', 'REGISTRATION_EXPIRATION_DATE' => '2027-11-01 00:00:00.0']));
        foreach (range(1, 2) as $day) {
            $renewals->renewLine($line->id);
        }

        $this->assertSame(1, collect($this->sent)->filter(fn ($s) => str_starts_with($s['command'], 'COMMAND=RenewDomain'))->count());
        $this->assertLessThanOrEqual(1, DomainRegistrarLog::query()->where('domain_id', $domain->id)->where('action', 'renew')->count());
    }
}
