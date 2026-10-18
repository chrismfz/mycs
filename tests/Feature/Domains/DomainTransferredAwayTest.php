<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Domains\DomainNotFoundAtRegistrar;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * A domain of ours moved to another registrar (live 2026-10-18: cdnet.gr to Openprovider, the .gr registry answering
 * 2201): the sync notes it, and seen so again an hour later marks it transferred away (billing and sync stop, auto-renew
 * off, an open renewal invoice noted for staff). Never on one answer, never a name gone (2303), never a pending order.
 */
class DomainTransferredAwayTest extends TestCase
{
    use RefreshDatabase;

    private FakeTransport $transport;

    private DomainRegistrarConnection $gr;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Cache::flush();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $this->gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
        $this->transport = new FakeTransport;
        GrEppRegistrar::$transport = fn () => $this->transport;
        GrEppRegistrar::reset();
    }

    protected function tearDown(): void
    {
        GrEppRegistrar::$transport = null;
        GrEppRegistrar::reset();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function answer(int $code): void
    {
        $this->transport->queue(new HttpResponse(200, '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
            . "<result code=\"{$code}\"><msg>m</msg></result><trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>"));
    }

    /** A sync whose domain:info is answered $code (or by $then, queued after the login). */
    private function sync(Domain $domain, ?int $code = null, ?\Closure $then = null): ?\Throwable
    {
        GrEppRegistrar::reset();
        $this->transport = new FakeTransport;
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
        $code !== null ? $this->answer($code) : $then?->__invoke();
        try {
            app(DomainSyncService::class)->sync($domain->fresh());
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private function domain(array $attributes = []): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'registrar_connection_id' => $this->gr->id, 'grace_days' => 15, 'redemption_days' => 0]);

        return Domain::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id, 'sld' => 'example1', 'tld' => 'gr',
            'fqdn' => 'example1.gr', 'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->gr->id, 'status' => DomainStatus::Active,
            'expires_at' => '2028-08-16', 'currency_code' => 'EUR', 'years' => 2, 'auto_renew' => true]);
    }

    public function test_seen_not_ours_twice_an_hour_apart_it_is_transferred_away(): void
    {
        $domain = $this->domain();
        $invoice = Invoice::factory()->create(['client_id' => $domain->client_id, 'currency_code' => 'EUR']);
        InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => '19.00', 'description' => 'example1.gr',
            'reference_type' => Domain::class, 'reference_id' => $domain->id]);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_UNPAID]); // its renewal, open

        $this->assertInstanceOf(DomainNotFoundAtRegistrar::class, $this->sync($domain, 2201));
        $this->assertSame([DomainStatus::Active, true], [$domain->fresh()->status, $domain->fresh()->auto_renew], 'one answer: only noted');
        $this->assertNotNull($domain->fresh()->meta['not_ours_since'] ?? null);

        Carbon::setTestNow('2026-10-20 10:30:00');
        $this->sync($domain, 2201);
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status, 'within the hour: still only noted');

        Carbon::setTestNow('2026-10-20 11:05:00');
        $this->sync($domain, 2201);
        $fresh = $domain->fresh();
        $this->assertSame([DomainStatus::TransferredAway, false], [$fresh->status, $fresh->auto_renew]);
        $this->assertSame([$invoice->id], $fresh->meta['transferred_away']['open_invoices'] ?? null);
        $this->assertArrayNotHasKey('not_ours_since', $fresh->meta);
        $this->assertFalse(app(DomainSyncService::class)->isSyncable($fresh), 'its sync stops');
    }

    public function test_never_a_name_gone_a_pending_order_or_one_ours_again(): void
    {
        // 2303: no such name (deleted, not moved).
        $gone = $this->domain();
        $this->sync($gone, 2303);
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->sync($gone, 2303);
        $this->assertSame(DomainStatus::Active, $gone->fresh()->status);
        $this->assertArrayNotHasKey('not_ours_since', $gone->fresh()->meta ?? []);

        // Ours again before the hour: the note cleared.
        $back = $this->domain(['sld' => 'example2', 'fqdn' => 'example2.gr']);
        $this->sync($back, 2201);
        $this->assertNotNull($back->fresh()->meta['not_ours_since'] ?? null);
        $this->sync($back, then: function () {
            $body = (string) file_get_contents(base_path('packages/gr-epp/tests/fixtures/live/domain-info-other-registrar-contacts-response.xml'));
            $this->transport->queue(new HttpResponse(200, (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', $body)));
            foreach (['admin', 'tech', 'billing'] as $role) {
                $this->answer(2201); // its roles' contacts, another registrar's
            }
        });
        $this->assertArrayNotHasKey('not_ours_since', $back->fresh()->meta ?? []);

        // A pending order: its fulfilment decides.
        $pending = $this->domain(['sld' => 'example3', 'fqdn' => 'example3.gr', 'status' => DomainStatus::PendingTransfer, 'meta' => ['sent_at' => '2026-10-19T10:00:00+00:00']]);
        $this->sync($pending, 2201);
        Carbon::setTestNow('2026-10-20 14:00:00');
        $this->sync($pending, 2201);
        $this->assertSame(DomainStatus::PendingTransfer, $pending->fresh()->status);
    }

    public function test_an_openprovider_domain_no_longer_in_the_account_too(): void
    {
        // The same rule for any registrar: Openprovider's «not in the account» (where it went not known) seen twice.
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'fakeaway', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        config(['domains.registrars.fakeaway' => GoneRegistrar::class]);
        $domain = $this->domain(['sld' => 'example4', 'tld' => 'com', 'fqdn' => 'example4.com', 'domain_tld_id' => null, 'registrar_connection_id' => $op->id]);

        $this->assertInstanceOf(DomainNotFoundAtRegistrar::class, $this->sync($domain));
        Carbon::setTestNow('2026-10-20 11:01:00');
        $this->sync($domain);
        $this->assertSame(DomainStatus::TransferredAway, $domain->fresh()->status);
    }
}

/** A registrar whose account no longer lists the domain (as Openprovider's). */
class GoneRegistrar extends OpenproviderRegistrar
{
    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw new DomainNotFoundAtRegistrar("{$domain->fqdn} is not in the Openprovider account.");
    }
}
