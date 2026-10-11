<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainContact;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\DomainRenewalInProgress;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\DomainTransferService;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Πυλώνας A / A3c — the transfer slice (§6.3): inbound transfers are async
 * (start → PendingTransfer, the nightly sync completes or ⚠-flags), adopt-on-
 * retry never pays twice, the auth code NEVER lands in any log, and the EPP
 * code retrieval (transfer-out aid) is audit-logged WITHOUT the code. All
 * against mocked HTTP.
 */
class DomainTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX = 'http://api.sandbox.openprovider.nl:8480';

    private DomainRegistrarConnection $connection;

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
    }

    private function pendingTransferDomain(array $extra = [], bool $withContact = true): Domain
    {
        $domain = Domain::create(array_merge([
            'sld' => 'moving', 'tld' => 'eu', 'fqdn' => 'moving.eu',
            'status' => DomainStatus::PendingTransfer,
            'registrar_connection_id' => $this->connection->id, // Ekdosi: routed via the TLD rule
        ], $extra));
        if ($withContact) {
            DomainContact::create([
                'domain_id' => $domain->id,
                'type' => 'registrant', 'name' => 'Νίκος Π.', 'email' => 'n@myip.gr',
                'registrar_contact_handle' => 'NP1-EU',
            ]);
        }

        return $domain;
    }

    public function test_a_new_contact_sends_a_non_empty_area_code_and_keeps_every_digit(): void
    {
        // Live finding (nixpal.com): OP answers 500 «Invalid telephone region code» to an empty area_code.
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains?full_name=moving.eu' => Http::response(['data' => ['results' => []]]),
            self::SANDBOX . '/v1beta/customers' => Http::response(['data' => ['handle' => 'XX1-GR']]),
            self::SANDBOX . '/v1beta/domains/transfer' => Http::response(['data' => ['id' => 701, 'status' => 'REQ']]),
        ]);
        $domain = $this->pendingTransferDomain(withContact: false);
        DomainContact::create([
            'domain_id' => $domain->id, 'type' => 'registrant', 'name' => 'Test Person',
            'email' => 't@example.test', 'phone' => '+30 6900000001',
            'address1' => 'Odos 4', 'city' => 'Serres', 'postcode' => '62100', 'country' => 'GR',
        ]);

        app(DomainTransferService::class)->transferIn($domain, 'SECRET-EPP-123');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1beta/customers')
            && $request['phone'] === ['country_code' => '+30', 'area_code' => '690', 'subscriber_number' => '0000001']);
        $this->assertSame('XX1-GR', $domain->contacts()->value('registrar_contact_handle'));
    }

    public function test_in_domain_nameservers_carry_their_glue_ip(): void
    {
        // Live finding (nixpal.com): OP answers 500 «IP is not specified» for ns1.<domain> without an ip.
        OpenproviderRegistrar::$glueResolver = fn (string $host) => $host === 'ns1.moving.eu' ? '192.0.2.10' : null;
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains?full_name=moving.eu' => Http::response(['data' => ['results' => []]]),
            self::SANDBOX . '/v1beta/domains/transfer' => Http::response(['data' => ['id' => 702, 'status' => 'REQ']]),
        ]);
        $domain = $this->pendingTransferDomain();
        $domain->nameservers()->create(['host' => 'ns1.moving.eu', 'sort_order' => 0]);
        $domain->nameservers()->create(['host' => 'ns.example.net', 'sort_order' => 1]);

        try {
            app(DomainTransferService::class)->transferIn($domain->fresh(), 'SECRET-EPP-123');
        } finally {
            OpenproviderRegistrar::$glueResolver = null;
        }

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1beta/domains/transfer')
            && $request['name_servers'] === [['name' => 'ns1.moving.eu', 'ip' => '192.0.2.10'], ['name' => 'ns.example.net']]);
    }

    public function test_transfer_in_starts_stays_pending_and_never_logs_the_auth_code(): void
    {
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains?full_name=moving.eu' => Http::response(['data' => ['results' => []]]), // unconditional probe: not ours yet
            self::SANDBOX . '/v1beta/domains/transfer' => Http::response(['data' => [
                'id' => 700, 'status' => 'REQ',
            ]]),
        ]);
        $domain = $this->pendingTransferDomain(); // no NS rows: keep delegation

        $log = app(DomainTransferService::class)->transferIn($domain, 'SECRET-EPP-123');

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $domain->refresh();
        $this->assertSame(DomainStatus::PendingTransfer, $domain->status, 'async — the sync completes it');
        $this->assertSame('700', $domain->registrar_domain_id);

        Http::assertSent(function ($req) {
            if (!str_ends_with($req->url(), '/v1beta/domains/transfer')) {
                return false;
            }

            return $req['auth_code'] === 'SECRET-EPP-123'
                && $req['period'] === 1
                && $req['autorenew'] === 'off'
                && $req['owner_handle'] === 'NP1-EU'
                && !array_key_exists('name_servers', $req->data()); // delegation kept
        });
        // The auth code is a bearer credential for the name — NEVER in a log.
        $this->assertStringNotContainsString('SECRET-EPP-123', json_encode(DomainRegistrarLog::all()->toArray()));
    }

    public function test_a_retry_adopts_the_inflight_transfer_never_pays_twice(): void
    {
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains?full_name=moving.eu' => Http::response(['data' => ['results' => [[
                'id' => 700, 'status' => 'REQ',
            ]]]]),
        ]);
        $domain = $this->pendingTransferDomain();
        DomainRegistrarLog::create([ // the timed-out first attempt
            'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id,
            'action' => 'transfer_in', 'status' => DomainRegistrarLog::STATUS_FAILED,
            'request' => ['fqdn' => 'moving.eu'], 'error' => 'cURL timeout',
        ]);

        $log = app(DomainTransferService::class)->transferIn($domain, 'SECRET-EPP-123');

        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, $log->status);
        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/v1beta/domains/transfer'));
        $this->assertSame('700', $domain->refresh()->registrar_domain_id);
    }

    public function test_a_panel_started_transfer_is_adopted_on_the_first_click(): void
    {
        // r1 finding 4: the probe is UNCONDITIONAL — a transfer started at
        // the registrar panel (no local id, no logs) must be adopted on the
        // very first click, never re-POSTed.
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains?full_name=moving.eu' => Http::response(['data' => ['results' => [[
                'id' => 700, 'status' => 'REQ', 'owner_handle' => 'PANEL1-EU',
            ]]]]),
        ]);
        $domain = $this->pendingTransferDomain();

        $log = app(DomainTransferService::class)->transferIn($domain, 'X');

        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, $log->status);
        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/v1beta/domains/transfer'));
        // the adopted record's handles persist (no duplicate OP customers later)
        $this->assertSame('PANEL1-EU', $domain->contacts()->where('type', 'registrant')->sole()->refresh()->registrar_contact_handle);
    }

    public function test_a_registrar_error_echoing_the_auth_code_is_scrubbed(): void
    {
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains?full_name=moving.eu' => Http::response(['data' => ['results' => []]]),
            self::SANDBOX . '/v1beta/domains/transfer' => Http::response(['desc' => 'invalid auth code SECRET-EPP-123 rejected'], 400),
        ]);
        $domain = $this->pendingTransferDomain();

        try {
            app(DomainTransferService::class)->transferIn($domain, 'SECRET-EPP-123');
            $this->fail('the transfer failed');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('SECRET-EPP-123', $e->getMessage(), 'scrubbed from the operator-facing message too');
        }
        $this->assertStringNotContainsString('SECRET-EPP-123', json_encode(DomainRegistrarLog::all()->toArray()));
    }

    public function test_a_dead_prior_request_restarts_the_transfer_fresh(): void
    {
        // The earlier request FAI-ed at the registry — the probe sees the
        // tombstone and a fresh transfer starts (with the new auth code).
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/700' => Http::response(['data' => ['id' => 700, 'status' => 'FAI']]),
            self::SANDBOX . '/v1beta/domains/transfer' => Http::response(['data' => ['id' => 701, 'status' => 'REQ']]),
        ]);
        $domain = $this->pendingTransferDomain(['registrar_domain_id' => '700']);

        $log = app(DomainTransferService::class)->transferIn($domain, 'NEW-EPP-456');

        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertSame('701', $domain->refresh()->registrar_domain_id, 'the NEW request id');
    }

    public function test_the_nightly_sync_flags_a_failed_pending_transfer(): void
    {
        // §6.3 'failed' leg: the record turned FAI — sync_error ⚠ lands on
        // the row, the status stays pending for the operator's decision. The
        // flag is GATED on an actual request in the API history (a lingering
        // tombstone from the name's previous life must not fake it).
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/700' => Http::response(['data' => ['id' => 700, 'status' => 'FAI']]),
        ]);
        $domain = $this->pendingTransferDomain(['registrar_domain_id' => '700']);
        DomainRegistrarLog::create([ // the transfer WAS requested from ekdosi
            'domain_id' => $domain->id,
            'registrar_connection_id' => $this->connection->id,
            'action' => 'transfer_in', 'status' => DomainRegistrarLog::STATUS_OK,
            'request' => ['fqdn' => 'moving.eu'],
        ]);

        app(DomainSyncService::class)->sync($domain);

        $domain->refresh();
        $this->assertSame(DomainStatus::PendingTransfer, $domain->status);
        $this->assertStringContainsString('απέτυχε στον registrar', $domain->sync_error);
        $this->assertStringContainsString('FAI', $domain->sync_error);
    }

    public function test_refusals_wrong_status_no_contact_manual_and_lock(): void
    {
        $service = app(DomainTransferService::class);
        Http::fake();

        $active = $this->pendingTransferDomain(['sld' => 'a1', 'fqdn' => 'a1.eu', 'status' => 'active']);
        try {
            $service->transferIn($active, 'X');
            $this->fail('only pending_transfer transfers');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Εκκρεμεί μεταφορά', $e->getMessage());
        }

        $noContact = $this->pendingTransferDomain(['sld' => 'a2', 'fqdn' => 'a2.eu'], withContact: false);
        try {
            $service->transferIn($noContact, 'X');
            $this->fail('registrant required');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('registrant', $e->getMessage());
        }

        $manual = DomainRegistrarConnection::create([
            'registrar' => 'manual',
            'is_active' => true, 'mode' => 'production', 'config' => [],
        ]);
        $manualDomain = $this->pendingTransferDomain(['sld' => 'a3', 'fqdn' => 'a3.eu', 'registrar_connection_id' => $manual->id]);
        try {
            $service->transferIn($manualDomain, 'X');
            $this->fail('manual refuses');
        } catch (DomainRegistrarNotConfigured) {
        }

        $locked = $this->pendingTransferDomain(['sld' => 'a4', 'fqdn' => 'a4.eu']);
        $lock = Cache::lock('domains:transfer:' . $locked->id, 60);
        $this->assertTrue($lock->get());
        try {
            $service->transferIn($locked, 'X');
            $this->fail('lock refuses');
        } catch (DomainRenewalInProgress) {
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
        $this->assertSame(4, DomainRegistrarLog::where('status', DomainRegistrarLog::STATUS_FAILED)->count());
    }

    public function test_epp_code_is_returned_and_audited_without_the_code(): void
    {
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/800/authcode' => Http::response(['data' => ['auth_code' => 'OUT-CODE-999']]),
        ]);
        $domain = $this->pendingTransferDomain(['sld' => 'own', 'fqdn' => 'own.eu', 'status' => 'active', 'registrar_domain_id' => '800']);

        $code = app(DomainTransferService::class)->eppCode($domain);

        $this->assertSame('OUT-CODE-999', $code);
        $log = DomainRegistrarLog::where('action', 'epp_code')->sole();
        $this->assertSame(DomainRegistrarLog::STATUS_OK, $log->status);
        $this->assertTrue($log->response['retrieved']);
        $this->assertStringNotContainsString('OUT-CODE-999', json_encode($log->toArray()), 'the code must never persist');
    }

    public function test_a_lingering_tombstone_without_a_request_neither_flags_nor_deletes_a_pending_row(): void
    {
        // Negative direction of the two new sync gates (r2 P2 #4): a DEL
        // tombstone from the name's previous life, NO request log → no false
        // «απέτυχε» flag, and the pending row is not flipped to Deleted.
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/700' => Http::response(['data' => ['id' => 700, 'status' => 'DEL']]),
        ]);
        $domain = $this->pendingTransferDomain(['registrar_domain_id' => '700']);

        app(DomainSyncService::class)->sync($domain);

        $domain->refresh();
        $this->assertSame(DomainStatus::PendingTransfer, $domain->status, 'tombstones never flip pending rows');
        $this->assertNull($domain->sync_error, 'no request was ever made — no false «απέτυχε» flag');
    }

    public function test_epp_code_config_refusals_are_audited_too(): void
    {
        // A3d gate r1: even the config-shaped refusals on the most
        // credential-shaped read leave their audit row.
        Http::fake();
        $manual = DomainRegistrarConnection::create([
            'registrar' => 'manual',
            'is_active' => true, 'mode' => 'production', 'config' => [],
        ]);
        $domain = $this->pendingTransferDomain(['sld' => 'man', 'fqdn' => 'man.eu', 'status' => 'active', 'registrar_connection_id' => $manual->id]);

        try {
            app(DomainTransferService::class)->eppCode($domain);
            $this->fail('manual routing refuses');
        } catch (DomainRegistrarNotConfigured) {
        }
        Http::assertNothingSent();
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::where('action', 'epp_code')->sole()->status);
    }

    public function test_epp_code_failures_are_audited_too(): void
    {
        Http::fake([
            self::SANDBOX . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1beta/domains/800/authcode' => Http::response(['desc' => 'nope'], 500),
        ]);
        $domain = $this->pendingTransferDomain(['sld' => 'own2', 'fqdn' => 'own2.eu', 'status' => 'active', 'registrar_domain_id' => '800']);

        try {
            app(DomainTransferService::class)->eppCode($domain);
            $this->fail('the failure surfaces');
        } catch (RuntimeException) {
        }
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::where('action', 'epp_code')->sole()->status);
    }
}
