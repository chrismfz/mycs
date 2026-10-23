<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Admin\Resources\DomainResource\RelationManagers\RegistrarLogsRelationManager;
use App\Exceptions\DisplayException;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainRecordCorrections;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/** Staff correct a domain's own record (owner 2026-10-23): name, registrar, nameservers by hand, price; before → after. */
class DomainRecordCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
        $this->staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $this->actingAs($this->staff, 'staff');
        DomainTld::query()->create(['tld' => 'gr', 'is_active' => true, 'sort' => 1]);
        $manual = DomainRegistrarConnection::query()->create(['registrar' => DomainRegistrarConnection::MANUAL, 'label' => 'By hand', 'is_active' => true, 'mode' => 'production']);
        $this->domain = Domain::factory()->create(['client_id' => User::factory()->create()->clients()->firstOrFail()->id, 'sld' => 'exmaple', 'tld' => 'gr',
            'fqdn' => 'exmaple.gr', 'registrar_connection_id' => $manual->id]);
        $this->domain->nameservers()->createMany([['host' => 'ns1.old.gr', 'sort' => 0], ['host' => 'ns2.old.gr', 'sort' => 1]]);
    }

    public function test_a_name_corrected_is_audited_before_and_after_and_never_one_ours_already(): void
    {
        app(DomainRecordCorrections::class)->rename($this->domain, ' Example.GR ', $this->staff);

        $this->assertSame(['example.gr', 'example', 'gr'], [$this->domain->refresh()->fqdn, $this->domain->sld, $this->domain->tld]);
        $audit = Audit::query()->where('event', 'record_renamed')->sole();
        $this->assertSame(['exmaple.gr', 'example.gr', $this->staff->id], [$audit->old_values['fqdn'], $audit->new_values['fqdn'], $audit->new_values['staff_id']]);

        Domain::factory()->create(['client_id' => $this->domain->client_id, 'sld' => 'taken', 'tld' => 'gr', 'fqdn' => 'taken.gr']);
        $this->expectException(DisplayException::class);
        app(DomainRecordCorrections::class)->rename($this->domain, 'taken.gr', $this->staff);
    }

    public function test_not_a_name_is_refused(): void
    {
        $this->expectExceptionMessage(__('domains.correct.not_a_name', ['name' => 'not a name']));
        app(DomainRecordCorrections::class)->rename($this->domain, 'not a name', $this->staff);
    }

    public function test_its_registrar_moved_by_hand_is_audited(): void
    {
        $other = DomainRegistrarConnection::query()->create(['registrar' => DomainRegistrarConnection::MANUAL, 'label' => 'Elsewhere', 'is_active' => true, 'mode' => 'production']);
        $before = $this->domain->registrar_connection_id;

        app(DomainRecordCorrections::class)->moveToRegistrar($this->domain, $other->id, $this->staff);

        $this->assertSame($other->id, $this->domain->refresh()->registrar_connection_id);
        $audit = Audit::query()->where('event', 'record_registrar')->sole();
        $this->assertSame([$before, $other->id], [$audit->old_values['registrar_connection_id'], $audit->new_values['registrar_connection_id']]);
    }

    public function test_nameservers_by_hand_only_where_no_registrar_keeps_them_audited_before_and_after(): void
    {
        app(DomainRecordCorrections::class)->setNameserversByHand($this->domain, ['NS1.new.gr', '', 'ns2.new.gr'], $this->staff);

        $this->assertSame(['ns1.new.gr', 'ns2.new.gr'], $this->domain->nameservers()->orderBy('sort')->pluck('host')->all());
        $audit = Audit::query()->where('event', 'record_nameservers')->sole();
        $this->assertSame([['ns1.old.gr', 'ns2.old.gr'], ['ns1.new.gr', 'ns2.new.gr']], [$audit->old_values['nameservers'], $audit->new_values['nameservers']]);
    }

    public function test_the_renewal_price_by_hand_and_back_to_the_tlds_from_edit(): void
    {
        Livewire::test(ViewDomain::class, ['record' => $this->domain->getRouteKey()])
            ->callAction('edit', ['auto_renew' => true, 'years' => 1, 'recurring_amount' => '9.5', 'notes' => ''])->assertHasNoActionErrors();
        $this->assertSame('9.50', (string) $this->domain->refresh()->recurring_amount);

        Livewire::test(ViewDomain::class, ['record' => $this->domain->getRouteKey()])
            ->callAction('edit', ['auto_renew' => true, 'years' => 1, 'recurring_amount' => null, 'notes' => ''])->assertHasNoActionErrors();
        $this->assertNull($this->domain->refresh()->recurring_amount);
    }

    public function test_the_page_corrects_the_record_in_one_go(): void
    {
        Livewire::test(ViewDomain::class, ['record' => $this->domain->getRouteKey()])->assertActionVisible('correctRecord')
            ->callAction('correctRecord', ['fqdn' => 'example.gr', 'registrar_connection_id' => $this->domain->registrar_connection_id, 'nameservers' => "ns1.a.gr\nns2.a.gr"])
            ->assertHasNoActionErrors();

        $this->assertSame('example.gr', $this->domain->refresh()->fqdn);
        $this->assertSame(['ns1.a.gr', 'ns2.a.gr'], $this->domain->nameservers()->orderBy('sort')->pluck('host')->all());
    }

    public function test_the_log_shows_a_change_before_and_after_and_offers_to_restore_it(): void
    {
        $log = DomainRegistrarLog::query()->create(['domain_id' => $this->domain->id, 'registrar_connection_id' => $this->domain->registrar_connection_id,
            'staff_id' => $this->staff->id, 'action' => 'nameservers', 'status' => DomainRegistrarLog::STATUS_OK,
            'request' => ['nameservers' => ['ns1.new.gr', 'ns2.new.gr'], 'before' => ['ns1.old.gr', 'ns2.old.gr']], 'response' => []]);

        $this->assertSame('ns1.old.gr, ns2.old.gr → ns1.new.gr, ns2.new.gr', RegistrarLogsRelationManager::change($log));
        Livewire::test(RegistrarLogsRelationManager::class, ['ownerRecord' => $this->domain, 'pageClass' => ViewDomain::class])
            ->assertSee('ns1.old.gr, ns2.old.gr → ns1.new.gr, ns2.new.gr')
            ->assertTableActionVisible('restoreNameservers', $log);
    }
}
