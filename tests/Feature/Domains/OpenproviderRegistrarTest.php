<?php

namespace Tests\Feature\Domains;

use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\DomainRegistrarRegistry;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Πυλώνας A / A2a — the READ-ONLY Openprovider adapter against a mocked HTTP
 * layer (the MyDataSubmitterSafetyTest discipline: no live calls in CI, ever).
 * Covers: auth + token caching, the fail-safe sandbox/production routing,
 * ping()'s never-throw contract, availability parsing, and the single
 * re-login retry on a mid-flight 401.
 */
class OpenproviderRegistrarTest extends TestCase
{
    private const SANDBOX = 'http://api.sandbox.openprovider.nl:8480';

    private const PRODUCTION = 'https://api.openprovider.eu';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // token cache is keyed per endpoint×username
        Http::preventStrayRequests(); // no live registrar calls in CI, ever
    }

    private function adapter(): OpenproviderRegistrar
    {
        return new OpenproviderRegistrar;
    }

    private function creds(bool $sandbox = true): DomainRegistrarCredentials
    {
        return new DomainRegistrarCredentials(
            config: ['username' => 'myip', 'password' => 'secret'],
            sandbox: $sandbox,
        );
    }

    public function test_it_is_wired_in_the_registry(): void
    {
        $this->assertInstanceOf(
            OpenproviderRegistrar::class,
            app(DomainRegistrarRegistry::class)->for('openprovider'),
        );
    }

    public function test_the_write_surface_is_exactly_what_the_a3_slices_have_landed(): void
    {
        // Write-by-slice discipline (owner runs production creds): A3a landed
        // renew() ONLY — every other mutating method must not exist before its
        // own slice arrives with its guards. renew() itself is reachable only
        // through DomainRenewalService (§6.6 adopt guard + audit log).
        $methods = array_map('strtolower', get_class_methods(OpenproviderRegistrar::class));
        $this->assertContains('renew', $methods, 'A3a: renew() is a landed write surface');
        $this->assertContains('register', $methods, 'A3b: register() is a landed write surface');
        $this->assertContains('transferin', $methods, 'A3c: transferIn() is a landed write surface');
        $this->assertContains('geteppcode', $methods, 'A3c: getEppCode() (read, transfer-out aid) landed');
        $this->assertContains('updatedomain', $methods, 'A3d: updateDomain() (NS/lock/privacy/contacts) landed');
        $this->assertContains('restore', $methods, 'A3d: restore() (redemption) landed');
        foreach (['requestdelete', 'setdnssec', 'setdnsseckeys', 'deletedomain'] as $forbidden) {
            $this->assertNotContains($forbidden, $methods, "adapter must not expose {$forbidden}() before its slice (delete = A4/never; DNSSEC keys = BACKLOG)");
        }
    }

    public function test_ping_true_on_successful_login_and_uses_the_sandbox_endpoint(): void
    {
        Http::fake([self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok-1']])]);

        $this->assertTrue($this->adapter()->ping($this->creds(sandbox: true)));
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), self::SANDBOX.'/v1beta/auth/login')
            && $request['username'] === 'myip');
    }

    public function test_only_explicit_production_hits_the_live_endpoint(): void
    {
        Http::fake([self::PRODUCTION.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok-live']])]);

        $this->assertTrue($this->adapter()->ping($this->creds(sandbox: false)));
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), self::PRODUCTION));
    }

    public function test_ping_never_throws(): void
    {
        // Missing creds → typed refusal internally → false.
        $this->assertFalse($this->adapter()->ping(new DomainRegistrarCredentials));

        // Bad auth → false.
        Http::fake([self::SANDBOX.'/v1beta/auth/login' => Http::response(['desc' => 'bad creds'], 403)]);
        $this->assertFalse($this->adapter()->ping($this->creds()));

        // Transport blowup → false.
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->assertFalse($this->adapter()->ping($this->creds()));
    }

    public function test_check_availability_parses_free_and_taken(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok-1']]),
            self::SANDBOX.'/v1beta/domains/check' => Http::sequence()
                ->push(['data' => ['results' => [['domain' => 'free-name.gr', 'status' => 'free']]]])
                ->push(['data' => ['results' => [['domain' => 'taken.gr', 'status' => 'active']]]]),
        ]);

        $adapter = $this->adapter();
        $free = $adapter->checkAvailability('Free-Name.gr', $this->creds());
        $this->assertTrue($free->available);
        $this->assertSame('free-name.gr', $free->fqdn);

        $taken = $adapter->checkAvailability('taken.gr', $this->creds());
        $this->assertFalse($taken->available);
        $this->assertSame('active', $taken->reason);

        // The payload splits name/extension on the FIRST dot (com.gr works).
        Http::assertSent(fn ($request): bool => ! str_contains($request->url(), 'check')
            || $request['domains'][0]['name'] === 'free-name' || $request['domains'][0]['name'] === 'taken');
    }

    public function test_missing_credentials_throw_the_typed_refusal_on_availability(): void
    {
        $this->expectException(DomainRegistrarNotConfigured::class);
        $this->adapter()->checkAvailability('example.gr', new DomainRegistrarCredentials);
    }

    public function test_a_stale_token_relogs_in_once_and_retries(): void
    {
        // Tokens are cached ENCRYPTED (a live bearer must never sit plaintext
        // in the cache table) — seed accordingly.
        Cache::put('domains:op:token:'.md5(self::SANDBOX.'|myip'), Crypt::encryptString('stale-token'), 300);

        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'fresh-token']]),
            self::SANDBOX.'/v1beta/domains/check' => Http::sequence()
                ->push(['desc' => 'expired'], 401)
                ->push(['data' => ['results' => [['domain' => 'x.gr', 'status' => 'free']]]]),
        ]);

        $result = $this->adapter()->checkAvailability('x.gr', $this->creds());
        $this->assertTrue($result->available);
        // login once (after the 401), check twice.
        Http::assertSentCount(3);
    }

    public function test_api_errors_surface_the_registrar_description(): void
    {
        Http::fake([
            self::SANDBOX.'/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX.'/v1beta/domains/check' => Http::response(['code' => 399, 'desc' => 'Extension not supported'], 400),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Extension not supported');
        $this->adapter()->checkAvailability('x.zz', $this->creds());
    }
}
