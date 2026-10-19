<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Livewire\Domains\Show;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\User;
use App\Support\Domains\RegistryStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2 of the portal: a .gr domain's record at the registry for its customer, as the sync last read it (the page calls no
 * registry): its statuses and the EETT's in words, its forms, when it last changed; nothing internal (ROID, protocol,
 * handles). Another registrar's domain shows none.
 */
class PortalRegistryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
    }

    private function domain(string $registrar, string $fqdn, array $record): Domain
    {
        $connection = DomainRegistrarConnection::query()->create(['registrar' => $registrar, 'label' => $registrar, 'is_active' => true, 'mode' => 'production']);
        [$sld, $tld] = explode('.', $fqdn, 2);
        $domainTld = DomainTld::query()->create(['tld' => $tld, 'grace_days' => 15, 'redemption_days' => 0, 'is_active' => true]);

        return Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => $sld, 'tld' => $tld, 'fqdn' => $fqdn, 'domain_tld_id' => $domainTld->id,
            'registrar_connection_id' => $connection->id, 'status' => DomainStatus::Active, 'meta' => ['registrar' => $record]]);
    }

    public function test_a_gr_customer_sees_its_records_statuses_in_words(): void
    {
        $domain = $this->domain('grepp', 'forth.gr', ['roid' => 'd34d-gr', 'statuses' => 'ok', 'protocol' => '63161',
            'regulator_statuses' => 'regulatorOwnerChangeProhibited, regulatorHold', 'bundle' => 'forth.gr (domain, chargeable) <b>x</b>',
            'updated' => '2026-10-18 16:21 UTC', 'handles' => 'registrant c60_q1pcBkTq']);

        app()->setLocale('el');
        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])
            ->assertSee('Στο μητρώο .gr')
            ->assertSee('Ενεργό, τίποτα σε εκκρεμότητα')
            ->assertSee('ΕΕΤΤ: δεν επιτρέπεται μεταβίβαση')
            ->assertSee('2026-10-18 16:21 UTC')
            ->assertSee('&lt;b&gt;x&lt;/b&gt;', false)
            ->assertDontSee('d34d-gr')->assertDontSee('63161')->assertDontSee('c60_q1pcBkTq')
            ->assertDontSee('(regulatorHold)', false);
    }

    public function test_none_for_another_registrars_or_one_never_read(): void
    {
        $op = $this->domain('openprovider', 'nixpal.com', ['status' => 'ACT', 'statuses' => 'ok', 'roid' => 'x']);
        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $op])->assertDontSee(__('domains.portal.at_registry'));

        $unread = $this->domain('grepp', 'cdnet.gr', ['protocol' => '993651']);
        $this->assertNull(RegistryStatuses::forCustomer($unread));
    }
}
