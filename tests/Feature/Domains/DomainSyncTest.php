<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Services\Domains\DomainSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Πυλώνας A / A2b — the registrar-truth pull applied to the row: expiry/NS/
 * registrar-id/status updates, sync bookkeeping (last_synced_at/sync_error),
 * routing via the domain's or the TLD's connection, and the never-silent
 * failure path. All against mocked HTTP (no live registrar calls in CI).
 */
class DomainSyncTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX = 'http://api.sandbox.openprovider.nl:8480';

    private DomainRegistrarConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // No live registrar calls in CI, EVER — an unmatched URL fails loudly
        // instead of escaping to the real network (caught one already: after
        // the id is adopted, the next sync hits /domains/{id}, not ?full_name=).
        Http::preventStrayRequests();

        $this->connection = DomainRegistrarConnection::create([
            'registrar' => 'openprovider',
            'is_active' => true,
            'mode' => 'sandbox',
            'config' => ['username' => 'myip', 'password' => 'secret'],
        ]);
        // Routing via the TLD default (the domain carries no own connection).
    }

    private function domain(array $extra = []): Domain
    {
        return Domain::create(array_merge([
            'sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr',
            'expires_at' => '2026-01-01',
            'registrar_connection_id' => $this->connection->id, // Ekdosi: routed via the TLD rule
        ], $extra));
    }

    public function test_sync_applies_expiry_status_ns_and_adopts_the_registrar_id(): void
    {
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains?full_name=example.gr' => Http::response(['data' => ['results' => [[
                'id' => 12345,
                'status' => 'ACT',
                'expiration_date' => '2027-06-15 00:00:00',
                'name_servers' => [['name' => 'NS1.Myip.GR'], ['name' => 'ns2.myip.gr']],
                'owner_handle' => 'AB1-GR',
                'tech_handle' => 'CD2-GR',
            ]]]]),
        ]);

        $domain = $this->domain(['status' => 'pending_register']);
        $result = app(DomainSyncService::class)->sync($domain);
        // The same pull carries the contact handles (feeds the per-domain
        // contacts refresh on the View action — no second domain fetch).
        $this->assertSame(['registrant' => 'AB1-GR', 'tech' => 'CD2-GR'], $result->contactHandles);
        $domain->refresh();

        $this->assertSame('2027-06-15', $domain->expires_at->toDateString());
        $this->assertSame('12345', $domain->registrar_domain_id);
        $this->assertSame(DomainStatus::Active, $domain->status);
        $this->assertSame('ACT', $domain->module_meta['registrar_status']);
        $this->assertNotNull($domain->last_synced_at);
        $this->assertNull($domain->sync_error);
        $this->assertSame(['ns1.myip.gr', 'ns2.myip.gr'], $domain->nameservers()->pluck('host')->all());
    }

    public function test_a_pending_transfer_keeps_its_expiry_until_the_registrar_reports_act(): void
    {
        // Live finding (nixpal.com): while REQ, OP reports today + 1y as the expiry.
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains?full_name=example.gr' => Http::response(['data' => ['results' => [[
                'id' => 777, 'status' => 'REQ', 'expiration_date' => '2027-10-11 00:00:00',
            ]]]]),
        ]);
        $domain = $this->domain(['status' => 'pending_transfer', 'expires_at' => '2026-11-17']);

        app(DomainSyncService::class)->sync($domain);
        $domain->refresh();

        $this->assertSame('2026-11-17', $domain->expires_at->toDateString());
        $this->assertSame(DomainStatus::PendingTransfer, $domain->status);
        $this->assertSame('777', $domain->registrar_domain_id);
    }

    public function test_sync_corrects_the_lock_privacy_mirrors_from_registrar_truth(): void
    {
        // A3d gate r1/r2: a toggle at the registrar PANEL must not leave the
        // local mirrors wrong forever — the sync corrects them. Explicit
        // booleans ONLY: a non-boolean echo (string/int) never overwrites.
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains?full_name=example.gr' => Http::response(['data' => ['results' => [[
                'id' => 5, 'status' => 'ACT', 'expiration_date' => '2027-06-15 00:00:00',
                'is_locked' => true,                  // panel-toggled ON → corrects our false
                'is_private_whois_enabled' => 'false', // NON-boolean → ignored, mirror keeps its value
            ]]]]),
        ]);

        $domain = $this->domain(['status' => 'active', 'transfer_lock' => false, 'whois_privacy' => true]);
        app(DomainSyncService::class)->sync($domain);

        $domain->refresh();
        $this->assertTrue($domain->transfer_lock, 'registrar truth corrected the stale mirror');
        $this->assertTrue($domain->whois_privacy, 'a non-boolean echo must never overwrite the mirror');
    }

    public function test_a_lapsed_expiry_derives_expired_even_when_op_still_reports_act(): void
    {
        // OP keeps ACT past the expiry date — the documented active→expired
        // transition is derived from the registrar expiry (§6.5).
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains?full_name=example.gr' => Http::response(['data' => ['results' => [[
                'id' => 5, 'status' => 'ACT',
                'expiration_date' => now()->subDays(10)->format('Y-m-d') . ' 00:00:00',
                'name_servers' => [],
            ]]]]),
        ]);

        $domain = $this->domain();
        app(DomainSyncService::class)->sync($domain);

        $this->assertSame(DomainStatus::Expired, $domain->refresh()->status);
    }

    public function test_unknown_registrar_status_keeps_the_local_status(): void
    {
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains?full_name=example.gr' => Http::response(['data' => ['results' => [[
                'id' => 1, 'status' => 'SCH', 'expiration_date' => '2027-01-01 00:00:00', 'name_servers' => [],
            ]]]]),
        ]);

        $domain = $this->domain(['status' => 'grace']);
        app(DomainSyncService::class)->sync($domain);
        $domain->refresh();

        $this->assertSame(DomainStatus::Grace, $domain->status, 'άγνωστο OP status δεν αλλάζει το τοπικό');
        $this->assertSame('SCH', $domain->module_meta['registrar_status']);
    }

    public function test_a_failed_pull_is_recorded_on_the_row_and_rethrown(): void
    {
        // Same URL, first pull fails, second succeeds (a later Http::fake does
        // NOT supersede an earlier wildcard stub — sequence on one URL instead).
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains?full_name=example.gr' => Http::sequence()
                ->push(['desc' => 'boom'], 500)
                ->push(['data' => ['results' => [[
                    'id' => 7, 'status' => 'ACT', 'expiration_date' => '2027-01-01 00:00:00', 'name_servers' => [],
                ]]]]),
        ]);

        $domain = $this->domain();
        try {
            app(DomainSyncService::class)->sync($domain);
            $this->fail('έπρεπε να ρίξει');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }

        $domain->refresh();
        $this->assertNotNull($domain->last_synced_at);
        $this->assertStringContainsString('boom', (string) $domain->sync_error);

        // A later success clears the error.
        app(DomainSyncService::class)->sync($domain);
        $this->assertNull($domain->refresh()->sync_error);
    }

    public function test_manual_or_unrouted_domains_are_not_syncable(): void
    {
        $unrouted = Domain::create([
            'sld' => 'loose', 'tld' => 'com', 'fqdn' => 'loose.com',
        ]);

        $service = app(DomainSyncService::class);
        $this->assertFalse($service->isSyncable($unrouted));
        $this->assertTrue($service->isSyncable($this->domain()));
    }

    public function test_terminal_or_off_mode_domains_are_not_syncable_and_terminal_status_is_never_overwritten(): void
    {
        $service = app(DomainSyncService::class);

        // OPERATOR-terminal status = intent — the sync must not touch it
        // (the two-clocks rule: cancelled would be resurrected by OP's 'ACT').
        $cancelled = $this->domain(['fqdn' => 'cxl.gr', 'sld' => 'cxl', 'status' => 'cancelled']);
        $this->assertFalse($service->isSyncable($cancelled));

        // Registrar-set Deleted keeps syncing (a redemption restore must be
        // picked up — Deleted blocks assignment, NOT the watch).
        $deleted = $this->domain(['fqdn' => 'del.gr', 'sld' => 'del', 'status' => 'deleted']);
        $this->assertTrue($service->isSyncable($deleted));

        // mode «Ανενεργό» = skip, never «fall back to sandbox with prod creds».
        $this->connection->update(['mode' => 'off']);
        $this->assertFalse($service->isSyncable($this->domain()));
        $this->connection->update(['mode' => 'sandbox']);

        // A tombstone is never rewritten (parity with the nightly command).
        $trashed = $this->domain(['fqdn' => 'tomb.gr', 'sld' => 'tomb']);
        $trashed->delete(); // soft delete sets deleted_at on the instance
        $this->assertFalse($service->isSyncable($trashed));
    }

    public function test_a_stale_registrar_id_falls_back_to_resolve_by_name(): void
    {
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/999' => Http::response(['desc' => 'not found'], 404),
            self::SANDBOX . '/v1beta/domains?full_name=example.gr' => Http::response(['data' => ['results' => [[
                'id' => 1000, 'status' => 'ACT', 'expiration_date' => '2029-01-01 00:00:00', 'name_servers' => [],
            ]]]]),
        ]);

        $domain = $this->domain(['registrar_domain_id' => '999']);
        app(DomainSyncService::class)->sync($domain);
        $domain->refresh();

        $this->assertSame('2029-01-01', $domain->expires_at->toDateString());
        // The by-name resolve is authoritative — the NEW id is adopted (no
        // repeat of the failing by-id call next run), the old kept for audit.
        $this->assertSame('1000', $domain->registrar_domain_id);
        $this->assertSame('999', $domain->module_meta['previous_registrar_domain_id']);
    }

    public function test_rejected_login_surfaces_openproviders_own_message(): void
    {
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['desc' => 'Authentication failed'], 403),
        ]);

        $domain = $this->domain();
        try {
            app(DomainSyncService::class)->sync($domain);
            $this->fail('έπρεπε να ρίξει');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Authentication failed', $e->getMessage());
        }
        $this->assertStringContainsString('Authentication failed', (string) $domain->refresh()->sync_error);
    }
}
