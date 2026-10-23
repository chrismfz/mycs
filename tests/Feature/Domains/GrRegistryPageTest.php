<?php

namespace Tests\Feature\Domains;

use App\Admin\Pages\GrRegistry;
use App\Contracts\ChangesOwner;
use App\Models\Domain;
use App\Models\DomainOwnerChange;
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
use Tests\TestCase;

/** Our account at the .gr registry (G8, owner 2026-10-23): one page, read from what is kept; only its check asks. */
class GrRegistryPageTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $gr;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'Μητρώο .gr', 'is_active' => true, 'mode' => 'production']);
        $this->gr->forceFill(['balance' => ['amount' => '1234.50', 'currency' => 'EUR', 'charges_allowed' => true, 'suspends_on' => null,
            'suspended_on' => null, 'read_at' => now()->subMinutes(20)->toIso8601String()]])->save();
    }

    private function staff(array $permissions = ['*']): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    public function test_it_shows_the_account_what_needs_a_look_and_our_glue_hosts(): void
    {
        $staff = $this->staff();
        $client = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Pappa'])->clients()->firstOrFail();
        Domain::factory()->create(['client_id' => $client->id, 'sld' => 'held', 'tld' => 'gr', 'fqdn' => 'held.gr',
            'meta' => ['registrar' => ['roid' => 'R1', 'regulator_statuses' => 'regulatorHold']]]);
        Domain::factory()->create(['client_id' => $client->id, 'sld' => 'fine', 'tld' => 'gr', 'fqdn' => 'fine.gr', 'meta' => ['registrar' => ['roid' => 'R2']]]);
        $myip = Domain::factory()->create(['client_id' => $client->id, 'sld' => 'myip', 'tld' => 'gr', 'fqdn' => 'myip.gr',
            'meta' => ['child_hosts' => ['read_at' => now()->toIso8601String(), 'hosts' => [['name' => 'ns1.myip.gr', 'addresses' => ['192.0.2.53'], 'linked' => true, 'read' => true]]]]]);
        DomainOwnerChange::query()->create(['domain_id' => $myip->id, 'kind' => ChangesOwner::NAME, 'contact_id' => $client->ensurePrimaryContact()->id,
            'status' => DomainOwnerChange::STATUS_UNKNOWN, 'staff_id' => $staff->id]);

        $this->get(GrRegistry::getUrl())->assertOk()
            ->assertSee('1.234,50 EUR')->assertSee(__('domains.gr_registry.allowed'))
            ->assertSee('held.gr')->assertSee(__('domains.registry_status.regulatorHold'))->assertDontSee('fine.gr')
            ->assertSee(__('domains.owner_change.statuses.unknown'))
            ->assertSee('ns1.myip.gr')->assertSee('192.0.2.53')
            ->assertSee(GrRegistry::GRWEB);
    }

    public function test_a_check_tells_free_taken_and_under_restrictions_apart(): void
    {
        $this->staff();
        $registrar = Mockery::mock(GrEppRegistrar::class);
        $registrar->shouldReceive('checkAvailabilityMany')->once()->withArgs(fn (array $names) => $names === ['mybusiness.gr', 'athens.gr', 'nixpal.gr'])
            ->andReturn([
                'mybusiness.gr' => new AvailabilityResult('mybusiness.gr', true),
                'athens.gr' => new AvailabilityResult('athens.gr', true, 'restricted: Γεωγραφικός όρος'),
                'nixpal.gr' => new AvailabilityResult('nixpal.gr', false, 'Δεσμευμένο'),
            ]);
        $factory = Mockery::mock(DomainRegistrarFactory::class);
        $factory->shouldReceive('for')->andReturn($registrar);
        $factory->shouldReceive('credentialsFor')->andReturn(new DomainRegistrarCredentials([]));
        $this->app->instance(DomainRegistrarFactory::class, $factory);

        Livewire::test(GrRegistry::class)->set('names', "https://www.MyBusiness.gr/\nathens.gr nixpal.gr example.com")->call('check')
            ->assertSet('checkError', null)
            ->assertSee(__('domains.gr_registry.free'))->assertSee(__('domains.gr_registry.restricted'))->assertSee('Γεωγραφικός όρος')
            ->assertSee(__('domains.gr_registry.taken'));
    }

    public function test_at_most_ten_names_and_only_dot_gr(): void
    {
        $this->staff();
        Livewire::test(GrRegistry::class)->set('names', implode(' ', array_map(fn ($i) => "n{$i}.gr", range(1, 11))))->call('check')
            ->assertSet('checkError', __('domains.gr_registry.check_too_many', ['count' => 10]));
        Livewire::test(GrRegistry::class)->set('names', 'example.com')->call('check')->assertSet('checkError', __('domains.gr_registry.check_none'));
    }

    public function test_staff_who_may_not_see_domains_get_no_page_and_read_now_needs_manage(): void
    {
        $this->staff(['admin.clients.viewAny']);
        $this->get(GrRegistry::getUrl())->assertForbidden();

        $this->staff(['admin.domains.viewAny']);
        $this->get(GrRegistry::getUrl())->assertOk()->assertDontSee(__('domains.gr_registry.read_now'));
        Livewire::test(GrRegistry::class)->call('readBalance', $this->gr->id)->assertForbidden();
    }
}
