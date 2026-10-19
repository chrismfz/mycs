<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource;
use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\Registrars\GrEppRegistrar;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * G8-3: the .gr registry's record on the domain's page, as grweb shows it: its statuses and the EETT's in words, its
 * protocol, forms (.gr / .ελ), DS check and ROID; read by the sync, the page calls no registry.
 */
class GreppRegistryDetailsTest extends TestCase
{
    use RefreshDatabase;

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
        $this->transport = new FakeTransport;
        GrEppRegistrar::$transport = fn () => $this->transport;
        GrEppRegistrar::reset();
    }

    protected function tearDown(): void
    {
        GrEppRegistrar::$transport = null;
        GrEppRegistrar::reset();
        parent::tearDown();
    }

    public function test_the_registrys_record_in_words(): void
    {
        // The guide's example 36 (forth.gr) is the registry's own («registry»): our registrar id here.
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'registrar_id' => 'registry', 'contact_prefix' => 'c60'], ['password']);
        $tld = DomainTld::query()->create(['tld' => 'gr', 'is_active' => true, 'registrar_connection_id' => $gr->id, 'grace_days' => 15, 'redemption_days' => 0]);
        $domain = Domain::factory()->create(['client_id' => User::factory()->create()->client()->id, 'sld' => 'forth', 'tld' => 'gr', 'fqdn' => 'forth.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $gr->id, 'status' => DomainStatus::Active]);
        $body = (string) file_get_contents(base_path('packages/gr-epp/tests/fixtures/v4.3/36-domain-info-response.xml'));
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml')
            ->queue(new HttpResponse(200, (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', $body)));
        app(DomainSyncService::class)->sync($domain->fresh());
        GrEppRegistrar::reset(); // its session ended (a logout)
        $sent = count($this->transport->sent);

        $this->assertTrue(DomainResource::fromGrRegistry($domain->fresh()));
        $staff = Staff::factory()->withPermissions(['admin.domains.viewAny'])->create();
        $this->livewireAs($staff)->test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->assertSee(__('At the .gr registry'))
            ->assertSee(__('domains.registry_status.regulatorHold') . ' (regulatorHold)')
            ->assertSee(__('domains.registry_status.regulatorOwnerChangeProhibited'))
            ->assertSee('63161')
            ->assertDontSee(__('Auto renew at the registrar'), 'not Openprovider\'s fields, all «—»');
        $this->assertCount($sent, $this->transport->sent, 'the page calls no registry');
    }

    public function test_a_status_in_words_or_as_given(): void
    {
        app()->setLocale('el');
        $this->assertSame(['Ενεργό, τίποτα σε εκκρεμότητα (ok)', 'someNewStatus'], DomainResource::registryStatuses('ok, someNewStatus'));
        $this->assertSame([], DomainResource::registryStatuses(null));
    }
}
