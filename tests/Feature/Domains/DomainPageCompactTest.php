<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\DomainResource;
use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\Registrars\GrEppRegistrar;
use DateTimeImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use MyCS\GrEpp\Data\DomainInfo;
use Tests\TestCase;

/** The domain's page as the mockup (owner 2026-10-23): its commands in a row, the client's domains, each registrar's fields. */
class DomainPageCompactTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
        $this->owner = User::factory()->create();
    }

    private function domain(string $fqdn, array $attributes = []): Domain
    {
        [$sld, $tld] = explode('.', $fqdn, 2);

        return Domain::factory()->create(['client_id' => $this->owner->clients()->firstOrFail()->id, 'sld' => $sld, 'tld' => $tld, 'fqdn' => $fqdn, ...$attributes]);
    }

    public function test_the_clients_other_domains_with_the_previous_and_next(): void
    {
        $this->domain('alpha.gr');
        $beta = $this->domain('beta.gr');
        $gone = $this->domain('gamma.gr', ['status' => DomainStatus::TransferredAway]);

        $this->get(DomainResource::getUrl('view', ['record' => $beta]))->assertOk()
            ->assertSee(__("This client's domains") . ' (3)')->assertSeeInOrder(['alpha.gr', 'beta.gr', 'gamma.gr'])
            ->assertSee(DomainStatus::TransferredAway->getLabel())
            ->assertSee(DomainResource::getUrl('view', ['record' => $gone]), false)
            ->assertSee(__('Previous domain'))->assertSee(__('Next domain'));
    }

    public function test_one_domain_alone_has_no_list(): void
    {
        $only = $this->domain('alone.gr');

        $this->get(DomainResource::getUrl('view', ['record' => $only]))->assertOk()->assertDontSee(__("This client's domains"));
    }

    public function test_centralnics_fields_show_under_their_own_labels(): void
    {
        $cnic = DomainRegistrarConnection::query()->create(['registrar' => 'cnic', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
        $domain = $this->domain('example.com', ['registrar_connection_id' => $cnic->id, 'meta' => ['registrar' => [
            'status' => 'ACTIVE', 'paiduntil_date' => '2027-03-05 10:00:00', 'renewalmode' => 'AUTORENEW', 'zone' => 'com', 'x-registry-lock' => '0',
        ]]]);

        $this->get(DomainResource::getUrl('view', ['record' => $domain]))->assertOk()
            ->assertSee(__('Paid until'))->assertSee('2027-03-05 10:00:00')->assertSee(__('Renewal mode'))->assertSee('AUTORENEW')
            ->assertSee(__('Registry lock'))->assertDontSee(__('Owner change (trade) allowed'));
    }

    public function test_the_gr_registry_s_sponsor_last_transfer_and_hosts_are_kept_and_shown(): void
    {
        $info = new DomainInfo(name: 'pappa.gr', roid: 'R1-gr', statuses: [], regulatorStatuses: [], registrant: 'c60_a', contacts: [],
            nameservers: ['ns1.pappa.gr'], hosts: ['ns1.pappa.gr', 'mail.pappa.gr'], clientId: 'c60', created: null, updated: null, expires: null,
            transferred: new DateTimeImmutable('2026-10-18 21:01:00'), protocol: '993651', punycode: null, bundle: [], dsValidation: null);

        $details = GrEppRegistrar::syncResultFrom($info)->details;
        $this->assertSame(['c60', '2026-10-18 21:01 UTC', 'mail.pappa.gr, ns1.pappa.gr'], [$details['sponsor'], $details['transferred'], $details['hosts']]);

        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => '.gr', 'is_active' => true, 'mode' => 'production']);
        $domain = $this->domain('pappa.gr', ['registrar_connection_id' => $gr->id, 'meta' => ['registrar' => ['roid' => 'R1-gr', 'statuses' => 'ok'] + $details]]);
        $this->get(DomainResource::getUrl('view', ['record' => $domain]))->assertOk()
            ->assertSee(__('Sponsoring registrar'))->assertSee('c60')->assertSee(__('Last transfer'))->assertSee('2026-10-18 21:01 UTC')
            ->assertSee('mail.pappa.gr')->assertSee(__('Names from the last sync; «Read child nameservers» reads their addresses.'));
    }

    public function test_a_command_button_opens_the_action_of_more(): void
    {
        $domain = $this->domain('alpha.gr');

        $page = Livewire::test(ViewDomain::class, ['record' => $domain->getRouteKey()]);
        $names = collect($page->instance()->registrarCommands())->map(fn ($action) => $action->getName())->all();
        foreach ($names as $name) {
            $page->assertActionVisible(substr($name, strlen('command_')));
        }
        $this->assertNotContains('command_recall', $names, 'only what this domain is offered');
    }

    public function test_back_to_its_client_on_the_same_line_as_the_list_which_holds_no_block(): void
    {
        $this->domain('alpha.gr');
        $beta = $this->domain('beta.gr');
        $client = $beta->client;

        $page = Livewire::test(ViewDomain::class, ['record' => $beta->getRouteKey()]);
        $html = (string) $page->instance()->getSubheading()->toHtml();
        $this->assertStringContainsString('← #' . $client->id . ' ' . e($client->name), $html);
        $this->assertStringContainsString(ClientResource::getUrl('view', ['record' => $client]), $html);
        // Filament prints it in a <p>: a <div> there would close it and drop the arrows to a line of their own.
        $this->assertStringNotContainsString('<div', $html);

        $alone = Domain::factory()->create(['client_id' => User::factory()->create()->clients()->firstOrFail()->id, 'sld' => 'solo', 'tld' => 'gr', 'fqdn' => 'solo.gr']);
        $this->get(DomainResource::getUrl('view', ['record' => $alone]))->assertOk()->assertSee('← #' . $alone->client_id)->assertDontSee(__("This client's domains"));
    }
}
