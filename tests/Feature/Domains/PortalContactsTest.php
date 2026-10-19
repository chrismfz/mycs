<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Livewire\Domains\Show;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P1 of the portal: a customer sets a domain's contacts by role from the account's contacts, sent to the registrar as
 * staff's «Change contacts» is (logged with the customer's login). The holder only where the registrar lets it simply
 * change, never a .gr name (a charged act of the registry, ours to do); another account's contact never.
 */
class PortalContactsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainRegistrarConnection $op;

    /** @var list<array<string, mixed>> */
    private array $puts = [];

    private int $customers = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
        Http::preventStrayRequests();
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->user = User::factory()->create();
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if ($request->method() === 'POST' && str_ends_with((string) parse_url($url, PHP_URL_PATH), '/customers')) {
                return Http::response(['code' => 0, 'data' => ['handle' => 'NEW' . (++$this->customers) . '-GR']]);
            }
            if ($request->method() === 'PUT') {
                $this->puts[] = $request->data();

                return Http::response(['code' => 0, 'data' => ['success' => true]]);
            }

            return Http::response(['code' => 0, 'data' => ['id' => 30391502, 'domain' => ['name' => 'nixpal', 'extension' => 'com'], 'status' => 'ACT',
                'expiration_date' => '2027-11-17 00:00:00', 'name_servers' => [['name' => 'ns1.myip.gr'], ['name' => 'ns2.myip.gr']]]]);
        });
    }

    private function contact(array $attributes = []): Contact
    {
        return Contact::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'country' => 'GR']);
    }

    private function domain(array $attributes = []): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => $attributes['tld'] ?? 'com'], ['grace_days' => 40, 'redemption_days' => 30, 'is_active' => true]);
        $owner = $this->contact(['first_name' => 'Owner']);

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->op->id, 'registrar_domain_id' => '30391502', 'status' => DomainStatus::Active,
            'expires_at' => '2027-11-17', 'registrant_contact_id' => $owner->id, 'registrant_handle' => 'OWN1-GR',
            'meta' => ['registrar' => ['modify_owner_allowed' => false]]]);
    }

    private function member(array $permissions): User
    {
        $member = User::factory()->create();
        ClientUser::query()->create(['client_id' => $this->user->client()->id, 'user_id' => $member->id, 'granted_at' => now(), 'permissions' => $permissions]);

        return $member;
    }

    public function test_a_customer_sets_the_roles_from_the_accounts_contacts(): void
    {
        $domain = $this->domain();
        $tech = $this->contact(['first_name' => 'Tech', 'email' => 'tech@example.test']);

        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])
            ->set('editContacts', true)->assertSee('tech@example.test')
            ->set('contactRoles.admin', $tech->id)->set('contactRoles.tech', $tech->id)
            ->call('saveContacts')->assertHasNoErrors()->assertSet('editContacts', false);

        $domain->refresh();
        $this->assertSame([$tech->id, $tech->id], [$domain->admin_contact_id, $domain->tech_contact_id]);
        $this->assertSame('NEW1-GR', $this->puts[0]['admin_handle'] ?? null, 'one customer made for the contact, in both roles');
        $this->assertSame('OWN1-GR', $this->puts[0]['owner_handle'] ?? null, 'the holder as it was');
        $log = DomainRegistrarLog::query()->where('action', 'contacts')->latest('id')->first();
        $this->assertSame([$this->user->id, null], [$log->user_id, $log->staff_id]);
    }

    public function test_the_holder_only_where_it_may_simply_change(): void
    {
        $domain = $this->domain();
        $other = $this->contact(['first_name' => 'Other']);

        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])
            ->set('editContacts', true)->set('contactRoles.registrant', $other->id)->call('saveContacts')->assertHasErrors('contactRoles.registrant');
        $this->assertSame([], $this->puts, 'not where the registrar does not allow it');

        $domain->forceFill(['meta' => ['registrar' => ['modify_owner_allowed' => true]]])->save();
        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain->fresh()])
            ->set('editContacts', true)->set('contactRoles.registrant', $other->id)->call('saveContacts')->assertHasNoErrors();
        $this->assertSame($other->id, $domain->fresh()->registrant_contact_id);
    }

    public function test_never_a_gr_holder_nor_another_accounts_contact(): void
    {
        $gr = $this->domain(['sld' => 'cdnet', 'tld' => 'gr', 'fqdn' => 'cdnet.gr', 'meta' => ['registrar' => ['modify_owner_allowed' => true]]]);
        $mine = $this->contact();
        $theirs = Contact::factory()->create(['client_id' => User::factory()->create()->client()->id]);

        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $gr])
            ->set('editContacts', true)->assertSee(__('domains.portal.registrant_locked'))
            ->set('contactRoles.registrant', $mine->id)->call('saveContacts')->assertHasErrors('contactRoles.registrant');
        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $this->domain(['sld' => 'other', 'fqdn' => 'other.com'])])
            ->set('editContacts', true)->set('contactRoles.admin', $theirs->id)->call('saveContacts')->assertHasErrors('contactRoles.admin')
            ->assertDontSee($theirs->email);
        $this->assertSame([], $this->puts);
    }

    public function test_only_who_manages_domains(): void
    {
        $domain = $this->domain();
        $tech = $this->contact();

        Livewire::actingAs($this->member(['domains']))->test(Show::class, ['domain' => $domain])
            ->set('contactRoles.tech', $tech->id)->call('saveContacts')->assertStatus(404); // as the client area refuses: nothing told
        $this->assertNull($domain->fresh()->tech_contact_id);
    }
}
