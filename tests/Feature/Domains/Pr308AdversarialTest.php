<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Domains\DomainLifecycle;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #308 (a domain is never ended as deleted by a guess). Written without the author's context;
 * Http::fake only, never a real registrar. Each test_adv<n> FAILS on the PR as it is and names its finding; the
 * test_guard ones pass and stay as guards.
 */
class Pr308AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const OP = 'https://api.openprovider.eu';

    private User $user;

    private DomainTld $tld;

    /** @var array<string, array<string, mixed>> Openprovider's records by id */
    private array $byId = [];

    /** @var list<array<string, mixed>> what Openprovider's search by name answers */
    private array $search = [];

    /** Openprovider's search by name answers a maintenance page. */
    private bool $searchDown = false;

    /** CentralNic Reseller's answer code to every command (545: not in the account). */
    private int $cnicCode = 200;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_starts_with($url, self::OP)) {
                $path = (string) parse_url($url, PHP_URL_PATH);
                if ($path === '/v1/auth/login') {
                    return Http::response(['code' => 0, 'data' => ['token' => 'tok']]);
                }
                if ($path === '/v1/domains' && $this->searchDown) {
                    return Http::response('maintenance', 503);
                }
                if ($path === '/v1/domains') {
                    return Http::response(['code' => 0, 'data' => ['results' => $this->search, 'total' => count($this->search)]]);
                }
                if (preg_match('#^/v1/domains/(\d+)$#', $path, $m) === 1) {
                    return isset($this->byId[$m[1]]) ? Http::response(['code' => 0, 'data' => $this->byId[$m[1]]])
                        : Http::response(['code' => 320, 'desc' => 'Domain not found'], 404);
                }

                return Http::response(['code' => 0, 'data' => []]);
            }
            // CentralNic Reseller (its own host): one answer to every command.
            if ($this->cnicCode === 545) {
                return Http::response("[RESPONSE]\ncode = 545\ndescription = Entity reference not found\nEOF\n");
            }

            return Http::response("[RESPONSE]\ncode = 200\ndescription = OK\nproperty[PAIDUNTIL DATE][0] = 2025-09-01 00:00:00.0\nEOF\n");
        });
        config(['audit.console' => true, 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15,
            'settings.cronjob_invoice' => 7, 'settings.credits_auto_use' => false]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->user = User::factory()->create();
        // Both stages known (40 days of grace, 30 of redemption): the dates can say «past its last recoverable day».
        $this->tld = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'grace_days' => 40, 'redemption_days' => 30]);
        foreach ([['renew', 1, '23.33'], ['grace', 1, '100.30'], ['redemption', 1, '150.00']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function connection(string $registrar, bool $active = true): DomainRegistrarConnection
    {
        $connection = DomainRegistrarConnection::query()->create(['registrar' => $registrar, 'label' => strtoupper($registrar), 'is_active' => $active,
            'mode' => 'production']);
        if ($registrar !== DomainRegistrarConnection::MANUAL) {
            $connection->storeCredentials(['username' => 'reseller', 'password' => 'Test-Secret-1'], ['password']);
        }

        return $connection;
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-20',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    /** An open renewal invoice of the domain for [$start, $end). */
    private function openRenewal(Domain $domain, string $start, string $end): Invoice
    {
        $invoice = Invoice::query()->create(['client_id' => $domain->client_id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => $start, 'currency_code' => 'EUR']);
        $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'price' => '23.33', 'quantity' => 1,
            'description' => "Renewal {$domain->fqdn}", 'period_start' => $start, 'period_end' => $end]);

        return $invoice->fresh();
    }

    /** An Openprovider record of example.com. */
    private static function record(int $id, string $status, array $extra = []): array
    {
        return $extra + ['id' => $id, 'domain' => ['name' => 'example', 'extension' => 'com'], 'status' => $status,
            'expiration_date' => '2025-09-01 00:00:00', 'name_servers' => [['name' => 'ns1.example.net']]];
    }

    private function credentials(): DomainRegistrarCredentials
    {
        return new DomainRegistrarCredentials(['username' => 'u', 'password' => 'p'], sandbox: false);
    }

    /** The 2-hourly pass of the sync (dates and a batch), then the nightly cron. */
    private function nightAndDay(): void
    {
        $this->artisan('domains:sync', ['--scheduled' => true]);
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    // ── (1) DomainLifecycle: never ended by dates when its registrar has an API ────────────────────────────────────

    /**
     * ADV-1 (medium, FAILING). A connection switched off for good (the registrar left, its account closed; the
     * RenewAsTransfer migration away from it done) is not «off for an hour». A domain on it that lapsed in 2025-09 and
     * was never renewed is past its grace (40) and redemption (30) by 13 months: before #308 the dates ended it
     * Deleted and the cron cancelled its open renewal invoice once past recovery. Now hasApi() keeps it in Redemption
     * forever: its invoice stays open and payable (the customer can pay for a name that is gone, the paid line then
     * waits on a connection that is off), the invoice's own overdue reminders go on (DomainReminders::covers no longer
     * covers it), and nobody is told. Nothing ever ends it: no sync reads it (not syncable), the dates may not.
     */
    public function test_adv1_a_connection_off_for_good_leaves_a_long_lapsed_domain_open_and_billed_forever(): void
    {
        $gone = $this->connection('openprovider', active: false);
        $domain = $this->domain(['registrar_connection_id' => $gone->id, 'expires_at' => '2025-09-01', 'status' => DomainStatus::Redemption]);
        $invoice = $this->openRenewal($domain, '2025-09-01', '2026-09-01');

        $this->nightAndDay();
        Carbon::setTestNow('2026-12-21 10:00:00'); // two months later still
        $this->nightAndDay();

        $this->assertTrue($domain->fresh()->status === DomainStatus::Deleted || $invoice->fresh()->status === Invoice::STATUS_CANCELLED,
            'past its last recoverable day by 15 months on a connection that is off: ended (or at least its invoice cancelled), not open forever');
    }

    /**
     * ADV-2 (low, pre-existing, FAILING; #308's premise «the registrar with an API says deleted itself»). CentralNic
     * Reseller never says «deleted»: a name it deleted (AUTOEXPIRE) answers 545 «not in the account», which the sync
     * only notes (elsewhere false, a lapsed one). A domain past its last recoverable day on CNIC is therefore never
     * ended and its open renewal invoice is never cancelled, with an active connection as much as with one off.
     */
    public function test_adv2_a_cnic_domain_its_registrar_deleted_is_never_ended_and_its_invoice_never_cancelled(): void
    {
        $cnic = $this->connection('cnic');
        $this->cnicCode = 545;
        $domain = $this->domain(['registrar_connection_id' => $cnic->id, 'expires_at' => '2025-09-01', 'status' => DomainStatus::Redemption]);
        $invoice = $this->openRenewal($domain, '2025-09-01', '2026-09-01');

        $this->artisan('domains:sync', ['--domain' => 'example.com']);
        $this->nightAndDay();

        $this->assertTrue($domain->fresh()->status === DomainStatus::Deleted || $invoice->fresh()->status === Invoice::STATUS_CANCELLED,
            'gone at CNIC (545) and past recovery by 13 months: ended, or its invoice cancelled; never open forever');
    }

    /** GUARD. A manual registrar's domain past both stages is still ended by its dates (unchanged by #308). */
    public function test_guard_a_manual_registrars_domain_past_both_stages_is_ended_by_its_dates(): void
    {
        $manual = $this->connection(DomainRegistrarConnection::MANUAL);
        $domain = $this->domain(['registrar_connection_id' => $manual->id, 'expires_at' => '2025-09-01', 'status' => DomainStatus::Redemption]);

        $this->assertSame(DomainStatus::Deleted, app(DomainLifecycle::class)->next($domain->fresh()));
    }

    /** GUARD. No connection at all (neither the domain's nor its TLD's): ended by its dates, as a manual one. */
    public function test_guard_a_domain_without_any_connection_is_ended_by_its_dates(): void
    {
        $domain = $this->domain(['expires_at' => '2025-09-01', 'status' => DomainStatus::Redemption]);

        $this->assertSame(DomainStatus::Deleted, app(DomainLifecycle::class)->next($domain->fresh()));
    }

    // ── (2) OpenproviderRegistrar::fetchDomainData: a dead by-id record falls through to the search ────────────────

    /**
     * ADV-3 (high, FAILING). The customer's name lapsed for good at Openprovider (DEL, past both quarantines: id 100,
     * stored here), and the name was then registered again in the same reseller account for SOMEONE ELSE (by staff in
     * the panel for another customer, by WHMCS still running on the account before the cut-over, by the other company's
     * instance on a shared account): id 200, ACT, another owner handle. «A live record of the name wins» adopts it: the
     * lapsed customer's domain becomes Active with the other registration's id, expiry and nameservers. Its next renewal
     * is billed to this customer and charged at Openprovider on id 200 (resolveDomainId uses the stored id), and staff or
     * the customer change the other owner's nameservers from here. Before #308 the stored id's tombstone ended it.
     */
    public function test_adv3_a_live_record_of_the_name_registered_for_someone_else_is_adopted_over_the_tombstone(): void
    {
        $op = $this->connection('openprovider');
        $this->byId = [
            '100' => self::record(100, 'DEL', ['owner_handle' => 'AA000001-NL']),
            '200' => self::record(200, 'ACT', ['owner_handle' => 'ZZ000099-NL', 'expiration_date' => '2027-10-20 00:00:00',
                'creation_date' => '2026-10-20 00:00:00', 'name_servers' => [['name' => 'ns1.someone-else.example']]]),
        ];
        $this->search = [$this->byId['100'], $this->byId['200']];
        $domain = $this->domain(['registrar_connection_id' => $op->id, 'expires_at' => '2025-09-01', 'status' => DomainStatus::Redemption,
            'registrar_domain_id' => '100', 'registrant_handle' => 'AA000001-NL']);

        app(DomainSyncService::class)->sync($domain);
        $domain->refresh();

        $this->assertSame(['100', false], [$domain->registrar_domain_id, $domain->status === DomainStatus::Active],
            'another owner\'s registration of the name (ZZ000099-NL, created after ours was deleted) is not this customer\'s domain: never adopted onto it');
    }

    /**
     * ADV-4 (medium, FAILING). A DEL in its soft quarantine is still ours (D5a: Grace, renewable or restorable; the
     * adapter's own syncResultFrom says so), yet the by-id read now treats it as a tombstone and lets ANY non-DEL/FAI
     * record of the name win: here a registration request someone placed on the name (REQ, id 200). The domain takes
     * id 200, loses «Grace by the registrar» (status and dates read from a REQ: none), and a restore or renewal is then
     * sent on id 200 (resolveDomainId uses the stored id), not on its own record.
     */
    public function test_adv4_a_quarantined_del_of_this_domain_loses_to_another_pending_record_of_the_name(): void
    {
        $op = $this->connection('openprovider');
        $this->byId = [
            '100' => self::record(100, 'DEL', ['soft_quarantine_expiry_date' => '2026-11-05 00:00:00', 'hard_quarantine_expiry_date' => '2026-12-05 00:00:00']),
            '200' => self::record(200, 'REQ', ['expiration_date' => '2027-10-21 00:00:00']),
        ];
        $this->search = [$this->byId['100'], $this->byId['200']];
        $domain = $this->domain(['registrar_connection_id' => $op->id, 'expires_at' => '2025-09-01', 'status' => DomainStatus::Grace,
            'registrar_domain_id' => '100']);

        $result = (new OpenproviderRegistrar)->syncDomain($domain, $this->credentials());

        $this->assertSame(['100', DomainStatus::Grace, '2026-11-05'], [$result->registrarDomainId, $result->status, $result->graceEndsOn],
            'its own record in its quarantine is the truth: never another request of the name');
    }

    /**
     * ADV-5 (medium, FAILING). A transfer in sent from here (meta.sent_at): its request is id 200, which Openprovider now
     * shows FAI (a wrong EPP code). An earlier request of the same name still lingers in the account, never moved (REQ,
     * id 100). The by-id FAI now falls through to the search, the REQ wins: the domain takes id 100, and transferOutcome
     * (its own record, by the id stored before, dead) never sees the failure. The customer is not told to check the code,
     * staff get no Pending Module Action; the domain waits as Pending Transfer on a request nobody sent. Before #308 the
     * stored id's FAI was read and the failure told.
     */
    public function test_adv5_a_failed_transfer_is_hidden_by_an_older_pending_request_of_the_name(): void
    {
        $op = $this->connection('openprovider');
        $this->byId = ['100' => self::record(100, 'REQ'), '200' => self::record(200, 'FAI')];
        $this->search = [$this->byId['100'], $this->byId['200']];
        $domain = $this->domain(['registrar_connection_id' => $op->id, 'status' => DomainStatus::PendingTransfer, 'registrar_domain_id' => '200',
            'meta' => ['sent_at' => now()->subDay()->toIso8601String()]]);

        app(DomainSyncService::class)->sync($domain);
        $domain->refresh();

        $this->assertSame(['200', '200'], [$domain->registrar_domain_id, $domain->meta['transfer_failed']['id'] ?? null],
            'its own request (200) failed: told, and never swapped for another request of the name');
    }

    /** GUARD. The same failed transfer without another record of the name is still told (unchanged by #308). */
    public function test_guard_a_failed_transfer_alone_is_still_told(): void
    {
        $op = $this->connection('openprovider');
        $this->byId = ['200' => self::record(200, 'FAI')];
        $this->search = [$this->byId['200']];
        $domain = $this->domain(['registrar_connection_id' => $op->id, 'status' => DomainStatus::PendingTransfer, 'registrar_domain_id' => '200',
            'meta' => ['sent_at' => now()->subDay()->toIso8601String()]]);

        app(DomainSyncService::class)->sync($domain);

        $this->assertSame('200', $domain->fresh()->meta['transfer_failed']['id'] ?? null);
    }

    /** GUARD. A quarantined DEL alone is still read as ours, in its grace (unchanged by #308). */
    public function test_guard_a_quarantined_del_alone_is_its_grace(): void
    {
        $op = $this->connection('openprovider');
        $this->byId = ['100' => self::record(100, 'DEL', ['soft_quarantine_expiry_date' => '2026-11-05 00:00:00'])];
        $this->search = [$this->byId['100']];
        $domain = $this->domain(['registrar_connection_id' => $op->id, 'expires_at' => '2025-09-01', 'status' => DomainStatus::Grace,
            'registrar_domain_id' => '100']);

        $result = (new OpenproviderRegistrar)->syncDomain($domain, $this->credentials());

        $this->assertSame(['100', DomainStatus::Grace, false], [$result->registrarDomainId, $result->status, $result->deadRecord]);
    }

    /** GUARD. A tombstone by id with a search that does not answer is still read (the PR's own fallback). */
    public function test_guard_a_tombstone_by_id_is_kept_when_the_search_fails(): void
    {
        $op = $this->connection('openprovider');
        $this->byId = ['100' => self::record(100, 'DEL')];
        $this->searchDown = true;
        $domain = $this->domain(['registrar_connection_id' => $op->id, 'expires_at' => '2025-09-01', 'status' => DomainStatus::Redemption,
            'registrar_domain_id' => '100']);

        $result = (new OpenproviderRegistrar)->syncDomain($domain, $this->credentials());

        $this->assertSame(['100', true], [$result->registrarDomainId, $result->deadRecord]);
    }
}
