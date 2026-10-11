<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\DomainRenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Πυλώνας A / A3a — the FIRST write: registrar renew, gated by the §6.6
 * ΔΕΣΜΕΥΤΙΚΟ adopt-on-already-renewed guard (the WHMCS double-renew/relid war
 * story). Sync-first before every renew; already-covered periods ADOPT with
 * no registrar call; every attempt lands in domain_registrar_logs. All
 * against mocked HTTP — no live registrar calls in CI, ever.
 */
class DomainRenewalServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX = 'http://api.sandbox.openprovider.nl:8480';

    private DomainRegistrarConnection $connection;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();

        $this->connection = DomainRegistrarConnection::create([
            'registrar' => 'openprovider',
            'is_active' => true, 'mode' => 'sandbox',
            'config' => ['username' => 'myip', 'password' => 'secret'],
        ]);
        $this->user = User::factory()->create();
    }

    /** @return array{0: Domain, 1: Service} an assigned, annually-billed domain (Ekdosi: ServiceContract) */
    private function assignedDomain(string $expiresAt = '2026-01-01'): array
    {
        $product = $this->createProduct();
        $product->plan->update(['billing_unit' => 'year', 'billing_period' => 1]);
        $contract = Service::factory()->create([
            'user_id' => $this->user->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => 'active', 'expires_at' => $expiresAt,
        ]);
        $domain = Domain::create([
            'user_id' => $this->user->id, 'service_id' => $contract->id,
            'registrar_connection_id' => $this->connection->id, // Ekdosi: routed via the TLD rule
            'sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr',
            'expires_at' => $expiresAt, 'status' => 'active', 'auto_renew' => true,
            'registrar_domain_id' => '77',
        ]);

        return [$domain, $contract];
    }

    /**
     * A renewal invoice the way the cron writes it (Ekdosi: an issued ΤΔΑ): one item for
     * the service, carrying the billing cursor as period_start.
     */
    private function invoiceFor(?Service $service): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'pending']);
        if ($service !== null) {
            $invoice->items()->create([
                'reference_id' => $service->id, 'reference_type' => Service::class,
                'period_start' => $service->expires_at, 'price' => 15, 'quantity' => 1,
                'description' => 'Renewal',
            ]);
        }

        return $invoice;
    }

    private function renewFor(Invoice $invoice): ?DomainRegistrarLog
    {
        return app(DomainRenewalService::class)->renewForInvoice($invoice->refresh())[0] ?? null;
    }

    /** @return array<string, mixed> */
    private function opDomain(string $expiry): array
    {
        return ['id' => 77, 'status' => 'ACT', 'expiration_date' => $expiry . ' 00:00:00', 'name_servers' => []];
    }

    public function test_renew_fires_when_the_period_is_not_covered_and_applies_fresh_truth(): void
    {
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            // 2 GETs: the §6.6 pre-check sync, then the post-renew re-fetch
            // (renew() reuses the freshly-synced stored id — no resolve GET)
            self::SANDBOX . '/v1beta/domains/77' => Http::sequence()
                ->push(['data' => $this->opDomain('2026-01-01')])
                ->push(['data' => $this->opDomain('2027-01-01')]),
            self::SANDBOX . '/v1beta/domains/77/renew' => Http::response(['code' => 0]),
        ]);
        [$domain] = $this->assignedDomain();

        $log = app(DomainRenewalService::class)->renew($domain, 1);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertSame('2027-01-01', $domain->refresh()->expires_at->toDateString());
        $this->assertSame('2027-01-01', $log->request['target_expiry']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/domains/77/renew') && $req['period'] === 1);
    }

    public function test_the_war_story_an_already_renewed_domain_is_adopted_never_double_renewed(): void
    {
        // «Ανανέωσέ το και σε πληρώνω τέλος του μήνα»: the operator renewed via
        // the button (or the registrar panel); when the invoice is later
        // issued, the pre-check sync sees the covered period and ADOPTS.
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/77' => Http::response(['data' => $this->opDomain('2027-01-01')]),
        ]);
        [$domain, $contract] = $this->assignedDomain('2026-01-01'); // SC cursor = the period start
        $domain->forceFill(['expires_at' => '2027-01-01'])->save(); // the button already moved it

        $invoice = $this->invoiceFor($contract);

        $log = $this->renewFor($invoice);

        // Baseline = the SC cursor (2026) → target 2027 → registrar already
        // reports 2027 → adopted, NO renew POST ever left the building.
        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, $log->status);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/renew'));
        $this->assertSame('2027-01-01', $domain->refresh()->expires_at->toDateString());
        $this->assertSame($invoice->id, $log->invoice_id);
    }

    public function test_drifted_cursor_still_adopts_by_consuming_the_button_renewal_log(): void
    {
        // The date guard's blind spot (review r1): expiry 2026-05-01 but the
        // SC cursor operator-edited to 2026-06-15. The button renewed →
        // registrar 2027-05-01 < cursor+1y — a pure date compare would renew
        // AGAIN. The intent-match leg consumes the button's unconsumed OK log
        // instead: adopted, ZERO registrar traffic.
        [$domain, $contract] = $this->assignedDomain('2026-05-01');
        $contract->forceFill(['expires_at' => '2026-06-15'])->save();
        $domain->forceFill(['expires_at' => '2027-05-01'])->save(); // the button moved it
        $buttonLog = DomainRegistrarLog::create([
            'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id, 'invoice_id' => null,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK,
            'request' => ['fqdn' => 'example.gr', 'years' => 1],
        ]);

        $invoice = $this->invoiceFor($contract);

        Http::fake(); // preventStrayRequests + no fakes = ANY http call fails the test

        $log = $this->renewFor($invoice);

        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, $log->status);
        $this->assertSame($invoice->id, $buttonLog->refresh()->invoice_id, 'the button renewal is consumed by this invoice');
        Http::assertNothingSent();
    }

    public function test_a_date_adopt_also_stamps_the_unconsumed_button_log(): void
    {
        // Biennial invoice, 1yr button log (can't intent-match) — but the
        // registrar already covers the biennial target, so the date leg
        // adopts AND must consume the button log: its year is spent, a later
        // intent-match must not count it again (review r2 finding 3).
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/77' => Http::response(['data' => $this->opDomain('2028-01-01')]),
        ]);
        [$domain, $contract] = $this->assignedDomain('2026-01-01');
        $contract->plan->update(['billing_period' => 2]); // Ekdosi: billing_cycle biennial
        // TWO 1yr button renewals fed the 2yr coverage — BOTH must be consumed
        // (a leftover would satisfy a later intent-match with no year behind it).
        $logA = DomainRegistrarLog::create([
            'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id, 'invoice_id' => null,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK,
            'request' => ['fqdn' => 'example.gr', 'years' => 1],
        ]);
        $logB = DomainRegistrarLog::create([
            'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id, 'invoice_id' => null,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK,
            'request' => ['fqdn' => 'example.gr', 'years' => 1],
        ]);
        $invoice = $this->invoiceFor($contract);

        $log = $this->renewFor($invoice);

        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, $log->status);
        $this->assertSame($invoice->id, $logA->refresh()->invoice_id, 'first button year consumed');
        $this->assertSame($invoice->id, $logB->refresh()->invoice_id, 'second button year consumed too');
        $this->assertCount(2, $log->response['consumed_log_ids']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/renew'));
    }

    public function test_a_stale_orphan_button_log_cannot_satisfy_todays_invoice(): void
    {
        // A 2-years-old unconsumed OK log (its registrar year long spent) must
        // NOT intent-match today's invoice — stale logs fall through to the
        // sync-backed date check, which renews for real (r3 finding 2).
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/77' => Http::sequence()
                ->push(['data' => $this->opDomain('2026-01-01')])
                ->push(['data' => $this->opDomain('2027-01-01')]),
            self::SANDBOX . '/v1beta/domains/77/renew' => Http::response(['code' => 0]),
        ]);
        [$domain, $contract] = $this->assignedDomain('2026-01-01');
        $stale = DomainRegistrarLog::create([
            'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id, 'invoice_id' => null,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK,
            'request' => ['fqdn' => 'example.gr', 'years' => 1],
        ]);
        DomainRegistrarLog::whereKey($stale->id)->update(['created_at' => now()->subYears(2)]);

        $invoice = $this->invoiceFor($contract);

        $log = $this->renewFor($invoice);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status, 'a REAL renew ran');
        Http::assertSent(fn ($req) => str_contains($req->url(), '/renew'));
        $this->assertNull($stale->refresh()->invoice_id, 'the stale orphan stays for the A5 reconciler');
    }

    public function test_split_button_renewals_aggregate_to_satisfy_a_biennial_invoice(): void
    {
        // r4: drift + split logs — two 1yr button renewals must TOGETHER
        // intent-match one biennial invoice (no single log has years>=2).
        [$domain, $contract] = $this->assignedDomain('2026-05-01');
        $contract->plan->update(['billing_period' => 2]); // Ekdosi: billing_cycle biennial
        $contract->forceFill(['expires_at' => '2026-06-15'])->save(); // drifted
        $domain->forceFill(['expires_at' => '2028-05-01'])->save();
        $logs = collect([1, 2])->map(fn ($i) => DomainRegistrarLog::create([
            'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id, 'invoice_id' => null,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK,
            'request' => ['fqdn' => 'example.gr', 'years' => 1],
        ]));
        $invoice = $this->invoiceFor($contract);

        Http::fake();
        $log = $this->renewFor($invoice);

        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, $log->status);
        $this->assertCount(2, $log->response['consumed_log_ids']);
        $this->assertSame([$invoice->id, $invoice->id], $logs->map(fn ($l) => $l->refresh()->invoice_id)->all());
        Http::assertNothingSent();
    }

    public function test_a_date_adopt_never_swallows_a_stale_orphan_log(): void
    {
        // r4: the panel covered the period (date adopt fires) while a stale
        // 2y-old orphan log exists — the stamping pool shares the intent-match
        // window, so the orphan stays for the A5 reconciler.
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/77' => Http::response(['data' => $this->opDomain('2027-01-01')]),
        ]);
        [$domain, $contract] = $this->assignedDomain('2026-01-01');
        $stale = DomainRegistrarLog::create([
            'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id, 'invoice_id' => null,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK,
            'request' => ['fqdn' => 'example.gr', 'years' => 1],
        ]);
        DomainRegistrarLog::whereKey($stale->id)->update(['created_at' => now()->subYears(2)]);
        $invoice = $this->invoiceFor($contract);

        $log = $this->renewFor($invoice);

        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, $log->status);
        $this->assertSame([], $log->response['consumed_log_ids']);
        $this->assertNull($stale->refresh()->invoice_id, 'the stale orphan stays for the A5 reconciler');
    }

    public function test_period_recovery_skips_period_less_rows_and_finds_the_carrying_one(): void
    {
        // r5: a newest FAILED row without baseline (e.g. a yearsFor refusal
        // from a mis-cycled era) must not shadow the older period-carrying
        // row — recovery reads the newest row that ACTUALLY carries a period.
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/77' => Http::response(['data' => $this->opDomain('2027-01-01')]),
        ]);
        [$domain, $contract] = $this->assignedDomain('2026-01-01');
        $contract->forceFill(['expires_at' => '2028-01-01'])->save(); // drifted way ahead
        $invoice = $this->invoiceFor($contract);
        DomainRegistrarLog::create([ // older, period-carrying
            'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id, 'invoice_id' => $invoice->id,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_FAILED,
            'request' => ['fqdn' => 'example.gr', 'years' => 1, 'baseline_expiry' => '2026-01-01', 'target_expiry' => '2027-01-01'],
        ]);
        DomainRegistrarLog::create([ // newest, period-less
            'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id, 'invoice_id' => $invoice->id,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_FAILED,
            'request' => ['fqdn' => 'example.gr'],
        ]);

        $log = $this->renewFor($invoice);

        // recovered baseline 2026 → target 2027 → registrar 2027 → ADOPT
        // (the drifted cursor 2028 would have renewed for real)
        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, $log->status);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/renew'));
    }

    public function test_a_concurrent_renewal_is_refused_by_the_per_domain_lock(): void
    {
        [$domain] = $this->assignedDomain();
        $lock = Cache::lock('domains:renew:' . $domain->id, 60);
        $this->assertTrue($lock->get());

        try {
            Http::fake();
            try {
                app(DomainRenewalService::class)->renew($domain, 1);
                $this->fail('a concurrent renewal must refuse');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('σε εξέλιξη', $e->getMessage());
            }
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_a_sync_that_reveals_a_dead_domain_aborts_the_renew(): void
    {
        // Local status stale-Active; the pre-check sync flips it to Deleted
        // (registrar DEL) — «νεκρό όνομα = ποτέ χρέωση» must hold here too.
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/77' => Http::response(['data' => [
                'id' => 77, 'status' => 'DEL', 'expiration_date' => '2026-01-01 00:00:00', 'name_servers' => [],
            ]]),
        ]);
        [$domain] = $this->assignedDomain();

        try {
            app(DomainRenewalService::class)->renew($domain, 1);
            $this->fail('a dead name must never be charged');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Διαγραμμένο', $e->getMessage());
        }
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/renew'));
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::sole()->status);
    }

    public function test_a_post_renew_refetch_failure_still_logs_ok_never_failed(): void
    {
        // From the POST on, the registrar HAS charged — a re-fetch hiccup must
        // not misrecord a real charge as failed (review r1 finding 5).
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/77' => Http::sequence()
                ->push(['data' => $this->opDomain('2026-01-01')]) // pre-check sync
                ->push(['desc' => 'boom'], 502),                  // post-renew re-fetch
            self::SANDBOX . '/v1beta/domains/77/renew' => Http::response(['code' => 0]),
        ]);
        [$domain] = $this->assignedDomain();

        $log = app(DomainRenewalService::class)->renew($domain, 1);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertNull($log->response['short_of_target'], 'unknown, not false — the A5 reconciler re-evaluates');
        $this->assertSame('2026-01-01', $domain->refresh()->expires_at->toDateString(), 'expiry lands with the nightly sync');
    }

    public function test_a_renew_landing_short_of_the_billed_target_is_flagged(): void
    {
        // Registrar expiry lagged the cursor (a prior failed renew never
        // retried): the charge is real → 'ok', but flagged so the operator
        // sees the domain will lapse before billing says.
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/77' => Http::sequence()
                ->push(['data' => $this->opDomain('2026-01-01')])
                ->push(['data' => $this->opDomain('2027-01-01')]),
            self::SANDBOX . '/v1beta/domains/77/renew' => Http::response(['code' => 0]),
        ]);
        [$domain] = $this->assignedDomain('2026-01-01');

        // the invoice bills the period starting at the (drifted-ahead) cursor
        $log = app(DomainRenewalService::class)->renew($domain, 1, null, '2027-01-01');

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertTrue($log->response['short_of_target']);
        $this->assertSame('2028-01-01', $log->request['target_expiry']);
    }

    public function test_a_failed_precheck_sync_aborts_without_writing_to_the_registrar(): void
    {
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/77' => Http::response(['desc' => 'boom'], 500),
        ]);
        [$domain] = $this->assignedDomain();

        try {
            app(DomainRenewalService::class)->renew($domain, 1);
            $this->fail('a failed pre-check must abort the renewal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('προ-έλεγχος', $e->getMessage());
        }

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/renew'));
        $log = DomainRegistrarLog::where('domain_id', $domain->id)->sole();
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, $log->status);
    }

    public function test_refusals_dead_set_manual_and_missing_expiry(): void
    {
        $service = app(DomainRenewalService::class);

        [$trashed] = $this->assignedDomain();
        $trashed->delete();
        try {
            $service->renew($trashed, 1);
            $this->fail('trashed must refuse');
        } catch (RuntimeException) {
        }

        $redemption = Domain::create([
            'sld' => 'red', 'tld' => 'gr', 'fqdn' => 'red.gr',
            'expires_at' => '2026-01-01', 'status' => DomainStatus::Redemption,
        ]);
        try {
            $service->renew($redemption, 1);
            $this->fail('redemption needs restore, not renew');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Redemption', $e->getMessage());
        }

        $manual = DomainRegistrarConnection::create([
            'registrar' => 'manual',
            'is_active' => true, 'mode' => 'production', 'config' => [],
        ]);
        $manualDomain = Domain::create([
            'registrar_connection_id' => $manual->id,
            'sld' => 'man', 'tld' => 'gr', 'fqdn' => 'man.gr',
            'expires_at' => '2026-01-01', 'status' => 'active',
        ]);
        try {
            $service->renew($manualDomain, 1);
            $this->fail('manual must refuse loudly');
        } catch (DomainRegistrarNotConfigured) {
        }

        $noExpiry = Domain::create([
            'registrar_connection_id' => $this->connection->id, // Ekdosi: routed via the TLD rule
            'sld' => 'noexp', 'tld' => 'gr', 'fqdn' => 'noexp.gr', 'status' => 'active',
        ]);
        try {
            $service->renew($noExpiry, 1);
            $this->fail('unknown expiry must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('λήξη', $e->getMessage());
        }

        // EVERY refusal logs a failed row (docblock contract + load-bearing
        // for the re-issue period recovery) — 4 refusals above = 4 rows.
        $this->assertSame(4, DomainRegistrarLog::where('status', DomainRegistrarLog::STATUS_FAILED)->count());
        Http::assertNothingSent();
    }

    public function test_renew_for_invoice_skips_non_domain_contracts_and_is_idempotent(): void
    {
        [$domain, $contract] = $this->assignedDomain();

        // a non-domain SC: no domain row points at it → null, zero HTTP
        $plain = Service::factory()->create([
            'user_id' => $this->user->id, 'product_id' => $contract->product_id,
            'plan_id' => $contract->plan_id, 'status' => 'active', 'expires_at' => '2026-01-01',
        ]);
        $hostingInvoice = $this->invoiceFor($plain);
        $this->assertSame([], app(DomainRenewalService::class)->renewForInvoice($hostingInvoice->refresh()));

        // an ok log for THIS invoice already exists → second fire is a no-op
        $invoice = $this->invoiceFor($contract);
        DomainRegistrarLog::create([
            'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id, 'invoice_id' => $invoice->id,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK,
        ]);
        $this->assertNull($this->renewFor($invoice));

        // a non-yearly cycle cannot map to a renewal term → loud refusal
        $contract->plan->update(['billing_unit' => 'month', 'billing_period' => 1]); // Ekdosi: billing_cycle monthly
        $monthly = $this->invoiceFor($contract);
        try {
            app(DomainRenewalService::class)->renewForInvoice($monthly->refresh());
            $this->fail('a non-yearly cycle must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('κύκλος', $e->getMessage());
        }
    }

    public function test_a_consumed_button_log_is_never_consumed_twice(): void
    {
        // CAS semantics: a log already tied to an invoice can't satisfy a
        // second invoice — that one proceeds to a REAL renew.
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/77' => Http::sequence()
                ->push(['data' => $this->opDomain('2026-01-01')])
                ->push(['data' => $this->opDomain('2027-01-01')]),
            self::SANDBOX . '/v1beta/domains/77/renew' => Http::response(['code' => 0]),
        ]);
        [$domain, $contract] = $this->assignedDomain('2026-01-01');
        $earlier = $this->invoiceFor(null);
        DomainRegistrarLog::create([
            'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id, 'invoice_id' => $earlier->id, // consumed elsewhere
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK,
            'request' => ['fqdn' => 'example.gr', 'years' => 1],
        ]);
        $invoice = $this->invoiceFor($contract);

        $log = $this->renewFor($invoice);

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status, 'a real renew ran');
        Http::assertSent(fn ($req) => str_contains($req->url(), '/renew'));
    }

    // ---- MyCS: on-paid (replaces Ekdosi's on-issue observer tests) ----

    public function test_paying_the_renewal_invoice_renews_at_the_registrar(): void
    {
        // The full flow through the REAL observer: status -> paid fires Invoice\Paid after
        // the service is advanced; the domain renews for the period the item carries.
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/77' => Http::sequence()
                ->push(['data' => $this->opDomain('2026-01-01')])
                ->push(['data' => $this->opDomain('2027-01-01')]),
            self::SANDBOX . '/v1beta/domains/77/renew' => Http::response(['code' => 0]),
        ]);
        [$domain, $contract] = $this->assignedDomain('2026-01-01');
        $invoice = $this->invoiceFor($contract);

        $invoice->update(['status' => 'paid']);

        $log = DomainRegistrarLog::where('invoice_id', $invoice->id)->sole();
        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertSame('2026-01-01', $log->request['baseline_expiry'], 'the billed period, not the moved service date');
        $this->assertSame('2027-01-01', $domain->refresh()->expires_at->toDateString(), 'registrar truth applied');
        Http::assertSentCount(4); // login, pre-check sync, renew, re-fetch
    }

    public function test_a_renewal_done_by_hand_before_payment_is_adopted_on_payment(): void
    {
        // The WHMCS double-renew war story, MyCS flavour: renewed at the registrar before
        // the client paid; on payment the cursor (2026) + 1y is already covered -> adopt.
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/77' => Http::response(['data' => $this->opDomain('2027-01-01')]),
        ]);
        [$domain, $contract] = $this->assignedDomain('2026-01-01');
        $invoice = $this->invoiceFor($contract);
        $domain->forceFill(['expires_at' => '2027-01-01'])->save(); // renewed by hand meanwhile

        $invoice->update(['status' => 'paid']);

        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, DomainRegistrarLog::where('invoice_id', $invoice->id)->sole()->status);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/renew'));
    }

    public function test_a_registrar_failure_never_fails_the_payment(): void
    {
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/77' => Http::response(['data' => $this->opDomain('2026-01-01')]),
            self::SANDBOX . '/v1beta/domains/77/renew' => Http::response(['code' => 399, 'desc' => 'Registry down'], 500),
        ]);
        [$domain, $contract] = $this->assignedDomain('2026-01-01');
        $invoice = $this->invoiceFor($contract);

        $invoice->update(['status' => 'paid']);

        $this->assertSame('paid', $invoice->refresh()->status, 'the payment stands');
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::where('invoice_id', $invoice->id)->latest('id')->first()->status);
        $this->assertSame('2026-01-01', $domain->refresh()->expires_at->toDateString());
    }
}
