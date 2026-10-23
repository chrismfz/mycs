<?php

namespace Tests\Feature\Domains;

use App\Admin\Pages\GrRegistry;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\AvailabilityResult;
use App\Support\Domains\DomainRegistrarCredentials;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** Adversarial review of #394 (the .gr Registry page): each test fails on the PR as it stands. */
class GrRegistryPageAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $gr;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'Μητρώο .gr', 'is_active' => true, 'mode' => 'production']);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]);
        $this->actingAs($staff, 'staff');
    }

    private function registrar(GrEppRegistrar $registrar): void
    {
        $factory = Mockery::mock(DomainRegistrarFactory::class);
        $factory->shouldReceive('for')->andReturn($registrar);
        $factory->shouldReceive('credentialsFor')->andReturn(new DomainRegistrarCredentials([]));
        $this->app->instance(DomainRegistrarFactory::class, $factory);
    }

    /**
     * ADV-1: the registrar's message goes to checkError as is (and into the Livewire snapshot), while RegistrarBalances
     * hides the connection's own credentials in the same kind of text (its review 7). A staff member with
     * admin.domains.viewAny only, who may not see the connection's credentials, reads them here.
     */
    public function test_adv1_a_registrar_error_never_shows_the_connections_password(): void
    {
        $this->gr->storeCredentials(['username' => 'myip1', 'password' => 'Gr-Secret-pw9'], ['password']);
        $registrar = Mockery::mock(GrEppRegistrar::class);
        $registrar->shouldReceive('checkAvailabilityMany')->andThrow(new RuntimeException('login myip1/Gr-Secret-pw9 refused (2200)'));
        $this->registrar($registrar);

        $page = Livewire::test(GrRegistry::class)->set('names', 'mybusiness.gr')->call('check');

        $this->assertNotNull($page->get('checkError'));
        $this->assertStringNotContainsString('Gr-Secret-pw9', (string) $page->get('checkError'));
        $page->assertDontSee('Gr-Secret-pw9');
    }

    /**
     * ADV-2: a .ελ name typed in punycode (xn--…xn--qxam, as copied from a browser bar or a zone file) is dropped as
     * «not .gr», though the registrar's Rules::name takes punycode and turns it to Unicode.
     */
    public function test_adv2_a_dot_el_name_in_punycode_is_checked(): void
    {
        $asked = idn_to_ascii('αθήνα.ελ', IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        $registrar = Mockery::mock(GrEppRegistrar::class);
        $registrar->shouldReceive('checkAvailabilityMany')->once()->withArgs(fn (array $names) => $names === [$asked])
            ->andReturn([$asked => new AvailabilityResult($asked, true)]);
        $this->registrar($registrar);

        Livewire::test(GrRegistry::class)->set('names', strtoupper($asked))->call('check')
            ->assertSet('checkError', null)->assertSee(__('domains.gr_registry.free'));
    }

    /**
     * ADV-3: the glue hosts take the first 50 .gr domains with any meta.child_hosts, then drop those with no hosts: a
     * domain whose child hosts were read once and found none (hosts: []) fills the limit, so our own ns1 is not shown.
     * The same trap toCheck() avoids for regulator_statuses («empty ones would fill the limit»).
     */
    public function test_adv3_domains_read_with_no_child_hosts_do_not_hide_ours(): void
    {
        $client = User::factory()->create()->clients()->firstOrFail();
        foreach (range(1, 50) as $i) {
            Domain::factory()->create(['client_id' => $client->id, 'sld' => sprintf('a%02d', $i), 'tld' => 'gr', 'fqdn' => sprintf('a%02d.gr', $i),
                'meta' => ['child_hosts' => ['read_at' => now()->toIso8601String(), 'hosts' => []]]]);
        }
        Domain::factory()->create(['client_id' => $client->id, 'sld' => 'myip', 'tld' => 'gr', 'fqdn' => 'myip.gr',
            'meta' => ['child_hosts' => ['read_at' => now()->toIso8601String(), 'hosts' => [['name' => 'ns1.myip.gr', 'addresses' => ['192.0.2.53']]]]]]);

        $this->get(GrRegistry::getUrl())->assertOk()->assertSee('ns1.myip.gr');
    }
}
