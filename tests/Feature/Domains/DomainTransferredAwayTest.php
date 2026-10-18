<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource;
use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainManagementService;
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
 * 2201): as WHMCS's sync, marked transferred away by itself when seen twice an hour apart by the connection that held
 * it (billing and sync stop, auto-renew off, its open invoices cancelled a day later); never when many of one connection
 * say so at once (a setting: staff decide, the badge); staff can mark or resume it by hand. Never noted for a name gone
 * (2303) or a pending order; read as ours again, the note goes.
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

    public function test_seen_twice_an_hour_apart_it_is_transferred_away_and_staff_can_resume_it(): void
    {
        $domain = $this->domain();
        $invoice = Invoice::factory()->create(['client_id' => $domain->client_id, 'currency_code' => 'EUR']);
        InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => '19.00', 'description' => 'example1.gr',
            'reference_type' => Domain::class, 'reference_id' => $domain->id]);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_UNPAID]); // its renewal, open

        $this->assertInstanceOf(DomainNotFoundAtRegistrar::class, $this->sync($domain, 2201));
        $this->assertSame([DomainStatus::Active, true], [$domain->fresh()->status, $domain->fresh()->auto_renew], 'one answer: noted');
        Carbon::setTestNow('2026-10-20 10:30:00');
        $this->sync($domain, 2201);
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status, 'within the hour: noted');

        Carbon::setTestNow('2026-10-20 11:05:00');
        $this->sync($domain, 2201);
        $away = $domain->fresh();
        $this->assertSame([DomainStatus::TransferredAway, false], [$away->status, $away->auto_renew]);
        $this->assertSame([[$invoice->id], 'active', 'sync'], [$away->meta['transferred_away']['open_invoices'] ?? null,
            $away->meta['transferred_away']['from'] ?? null, $away->meta['transferred_away']['by'] ?? null]);
        $this->assertFalse(app(DomainSyncService::class)->isSyncable($away), 'its sync stops');

        // The daily cron cancels its open renewal invoice only a day after (owner 2026-10-18: time to undo a mistake).
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'not the same day');

        // A mistake: resumed by staff as it was, then synced (here still «not ours»: noted again, never ended at once).
        $this->transport = new FakeTransport;
        GrEppRegistrar::reset();
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
        $this->answer(2201);
        app(DomainManagementService::class)->resumeNotTransferredAway($away, Staff::factory()->create());
        $this->assertSame([DomainStatus::Active, true], [$domain->fresh()->status, $domain->fresh()->auto_renew]);
    }

    public function test_many_of_one_connection_at_once_is_a_setting_and_staff_decide(): void
    {
        $domains = collect(['example1', 'example2', 'example3'])->map(fn ($sld) => $this->domain(['sld' => $sld, 'fqdn' => "{$sld}.gr"]));
        $domains->each(fn (Domain $d) => $this->sync($d, 2201));
        Carbon::setTestNow('2026-10-20 11:05:00');
        $domains->each(fn (Domain $d) => $this->sync($d, 2201));

        $this->assertSame(0, $domains->filter(fn (Domain $d) => $d->fresh()->status === DomainStatus::TransferredAway)->count());
        $this->assertSame('3', DomainResource::getNavigationBadge(), 'staff are told');

        // Staff confirm the one that did move.
        app(DomainManagementService::class)->markTransferredAway($domains[0]->fresh(), Staff::factory()->create());
        $this->assertSame(['staff', DomainStatus::TransferredAway], [$domains[0]->fresh()->meta['transferred_away']['by'] ?? null, $domains[0]->fresh()->status]);
    }

    public function test_never_a_name_gone_a_pending_order_or_one_ours_again(): void
    {
        // 2303: no such name (deleted, not moved).
        $gone = $this->domain();
        $this->sync($gone, 2303);
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->sync($gone, 2303);
        $this->assertSame(DomainStatus::Active, $gone->fresh()->status);
        $this->assertArrayNotHasKey('not_ours', $gone->fresh()->meta ?? []);

        // Ours again before the hour: the note cleared.
        $back = $this->domain(['sld' => 'example2', 'fqdn' => 'example2.gr']);
        $this->sync($back, 2201);
        $this->assertNotNull($back->fresh()->meta['not_ours'] ?? null);
        $this->sync($back, then: function () {
            $body = (string) file_get_contents(base_path('packages/gr-epp/tests/fixtures/live/domain-info-other-registrar-contacts-response.xml'));
            $this->transport->queue(new HttpResponse(200, (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', $body)));
            foreach (['admin', 'tech', 'billing'] as $role) {
                $this->answer(2201); // its roles' contacts, another registrar's
            }
        });
        $this->assertArrayNotHasKey('not_ours', $back->fresh()->meta ?? []);

        // A pending order: its fulfilment decides.
        $pending = $this->domain(['sld' => 'example3', 'fqdn' => 'example3.gr', 'status' => DomainStatus::PendingTransfer, 'meta' => ['sent_at' => '2026-10-19T10:00:00+00:00']]);
        $this->sync($pending, 2201);
        Carbon::setTestNow('2026-10-20 14:00:00');
        $this->sync($pending, 2201);
        $this->assertSame(DomainStatus::PendingTransfer, $pending->fresh()->status);
    }

    public function test_an_openprovider_domain_no_longer_in_the_account_too(): void
    {
        // The same note for any registrar: Openprovider's «not in the account» (where it went not known).
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'fakeaway', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        config(['domains.registrars.fakeaway' => GoneRegistrar::class]);
        $domain = $this->domain(['sld' => 'example4', 'tld' => 'com', 'fqdn' => 'example4.com', 'domain_tld_id' => null, 'registrar_connection_id' => $op->id]);

        $this->assertInstanceOf(DomainNotFoundAtRegistrar::class, $this->sync($domain));
        Carbon::setTestNow('2026-10-20 11:01:00');
        $this->sync($domain);
        $this->assertSame(DomainStatus::TransferredAway, $domain->fresh()->status, 'any registrar: seen twice an hour apart');
        $this->assertSame('sync', $domain->fresh()->meta['transferred_away']['by'] ?? null);
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
