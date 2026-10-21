<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\User;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Domain sweep (2026-10-22), the registrar adapters: how each one reads and classifies the registrar's answers. Written
 * without the authors' context; Http::fake only, never a real registrar. Each test_F<n> FAILS on main (e3b94471) and
 * names the finding.
 */
class DomainSweepRegistrarsTest extends TestCase
{
    use RefreshDatabase;

    private const OP = 'https://api.openprovider.eu';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-22 10:00:00');
        Cache::flush();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function opConnection(): DomainRegistrarConnection
    {
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);

        return $op;
    }

    /**
     * F1 (expiry). A name that lapsed at Openprovider (its DEL tombstone, id 100, the id stored here) and was bought again
     * there (a new ACT record, id 200: re-registered by hand, as the adapter's own comment on «lapse-and-rebuy» says the
     * account then holds both). The by-id read accepts the tombstone because it is «a record of this name» and never
     * reaches the by-name search that prefers the live record: the sync reads the domain as deleted (a dead record), keeps
     * the tombstone's id and the old expiry, and the new registration is never seen (no renewal, no reminders).
     */
    public function test_f1_openprovider_a_stored_id_of_a_tombstone_hides_the_live_record_of_the_same_name(): void
    {
        $tombstone = ['id' => 100, 'domain' => ['name' => 'example', 'extension' => 'com'], 'status' => 'DEL',
            'expiration_date' => '2025-10-01 00:00:00', 'name_servers' => [['name' => 'ns1.example.net']]];
        $live = ['id' => 200, 'domain' => ['name' => 'example', 'extension' => 'com'], 'status' => 'ACT',
            'expiration_date' => '2027-10-20 00:00:00', 'name_servers' => [['name' => 'ns1.example.net']]];
        Http::fake([
            self::OP . '/v1/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::OP . '/v1/domains?full_name=*' => Http::response(['code' => 0, 'data' => ['results' => [$tombstone, $live]]]),
            self::OP . '/v1/domains/100' => Http::response(['code' => 0, 'data' => $tombstone]),
            self::OP . '/v1/domains/200' => Http::response(['code' => 0, 'data' => $live]),
        ]);
        $op = $this->opConnection();
        $user = User::factory()->create();
        // Lapsed here (its redemption), the tombstone's id stored; bought again at Openprovider meanwhile.
        $domain = Domain::factory()->create(['client_id' => $user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'registrar_connection_id' => $op->id, 'status' => DomainStatus::Redemption, 'expires_at' => '2025-10-01', 'registrar_domain_id' => '100']);

        $result = (new OpenproviderRegistrar)->syncDomain($domain, new DomainRegistrarCredentials(['username' => 'u', 'password' => 'p'], sandbox: false));
        $this->assertSame([false, DomainStatus::Active, '200', '2027-10-20'], [$result->deadRecord, $result->status, $result->registrarDomainId, $result->expiresAt],
            'a live record of this very name exists: never its tombstone');

        app(DomainSyncService::class)->sync($domain);
        $domain->refresh();
        $this->assertNotSame(DomainStatus::Deleted, $domain->status, 'bought again there: never ended here by its old tombstone');
        $this->assertSame(['200', '2027-10-20'], [$domain->registrar_domain_id, $domain->expires_at?->toDateString()]);
    }
}
