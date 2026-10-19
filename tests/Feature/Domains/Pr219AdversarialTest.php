<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Livewire\Domains\Show;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Adversarial review of #219 (P1 of the portal: a domain's contacts by role, set by the customer). The tests named
 * test_fN_* failed when written (each a finding of the review); test_guard_* passed and stay as guards.
 */
class Pr219AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainRegistrarConnection $op;

    /** @var list<array<string, mixed>> */
    private array $puts = [];

    private int $customers = 0;

    /** What the registrar's GET reports for the domain's handles (as Openprovider: owner_handle, admin_handle…). */
    private array $theirHandles = ['owner_handle' => 'OWN1-GR'];

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
                'expiration_date' => '2027-11-17 00:00:00', 'name_servers' => [['name' => 'ns1.myip.gr'], ['name' => 'ns2.myip.gr']]] + $this->theirHandles]);
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

    /** @return list<string> the messages the component showed (notify) */
    private function notices(Testable $test): array
    {
        return collect($test->effects['dispatches'] ?? [])->where('name', 'notify')
            ->map(fn (array $d) => (string) ($d['params'][0]['message'] ?? $d['params']['message'] ?? ''))->values()->all();
    }

    /**
     * F1: the service's refusals are written for staff («Sync now says why», «Link here», buttons only the admin has);
     * the portal shows an InvalidArgumentException's text as it is, so the customer reads them.
     */
    public function test_f1_staff_only_refusals_never_reach_the_customer(): void
    {
        // The registrant has a contact here but is not linked to the registrar's handle (an imported domain, D4c).
        $unlinkedHolder = $this->domain(['registrant_handle' => null]);
        $tech = $this->contact(['first_name' => 'Tech']);
        $test = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $unlinkedHolder])
            ->set('editContacts', true)->set('contactRoles.tech', $tech->id)->call('saveContacts');
        $said = $this->notices($test);

        // An admin with a contact here, the registrar's admin handle not linked to it.
        $admin = $this->contact(['first_name' => 'Admin']);
        $unlinkedAdmin = $this->domain(['sld' => 'other', 'fqdn' => 'other.com', 'admin_contact_id' => $admin->id, 'admin_handle' => null,
            'meta' => ['registrar' => ['modify_owner_allowed' => false], 'registrar_handles' => ['admin' => 'ADM9-GR']]]);
        $test = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $unlinkedAdmin])
            ->set('editContacts', true)->set('contactRoles.tech', $tech->id)->call('saveContacts');
        $said = implode(' | ', array_merge($said, $this->notices($test)));
        $this->assertStringNotContainsString('Sync now', $said, 'staff-only wording shown to the customer: ' . $said);
        $this->assertStringNotContainsString('Link here', $said, 'staff-only wording shown to the customer: ' . $said);
        $this->assertSame([], $this->puts);
    }

    /**
     * F2: after an account move (staff move a domain to another account; DomainContactActions already guards this
     * «bad data after an account move») the domain's contacts are still the old account's: the new contacts block shows
     * their names and companies to the new account.
     */
    public function test_f2_the_old_accounts_contacts_are_not_shown_after_an_account_move(): void
    {
        $previous = User::factory()->create();
        $theirs = Contact::factory()->create(['client_id' => $previous->client()->id, 'first_name' => 'Zenobia', 'last_name' => 'Previousowner',
            'company_name' => 'Secret Holdings', 'email' => 'zenobia@previous.test', 'country' => 'GR']);
        $domain = $this->domain(['tech_contact_id' => $theirs->id, 'tech_handle' => 'TEC1-GR']);

        $page = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain]);
        $page->assertDontSee('Previousowner')->assertDontSee('Secret Holdings');
        $page->set('editContacts', true)->assertDontSee('zenobia@previous.test')->assertDontSee('Previousowner');
    }

    /**
     * F3: a role the customer did not touch is checked as if chosen: one still holding another account's contact (an
     * account move, F2) refuses every change, and on a .gr name, whose holder they cannot change here, for ever. The
     * service checks only the roles asked for.
     */
    public function test_f3_an_untouched_role_does_not_block_the_change_of_another(): void
    {
        $previous = User::factory()->create();
        $theirs = Contact::factory()->create(['client_id' => $previous->client()->id, 'country' => 'GR']);
        $domain = $this->domain();
        $domain->forceFill(['registrant_contact_id' => $theirs->id])->save();
        $tech = $this->contact(['first_name' => 'Tech']);

        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain->fresh()])
            ->set('editContacts', true)->set('contactRoles.tech', $tech->id)->call('saveContacts')
            ->assertHasNoErrors('contactRoles.registrant');
    }

    /**
     * F4: «—» on admin/tech/billing clears the role here, but Openprovider is sent only the roles with a handle: the
     * registrar keeps the old admin (adversarial D4c A1b) while the customer is told «Contacts changed at the registrar»
     * and the page shows «—». Someone removed as admin (a former employee) still is, at the registrar.
     */
    public function test_f4_clearing_a_role_is_not_reported_done_while_the_registrar_keeps_it(): void
    {
        $admin = $this->contact(['first_name' => 'Former', 'last_name' => 'Employee']);
        $domain = $this->domain(['admin_contact_id' => $admin->id, 'admin_handle' => 'ADM1-GR']);
        $this->theirHandles = ['owner_handle' => 'OWN1-GR', 'admin_handle' => 'ADM1-GR'];

        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])
            ->set('editContacts', true)->set('contactRoles.admin', '')->call('saveContacts');

        $cleared = $domain->fresh()->admin_contact_id === null;
        $sentAnotherAdmin = isset($this->puts[0]['admin_handle']) && $this->puts[0]['admin_handle'] !== 'ADM1-GR';
        $this->assertFalse($cleared && !$sentAnotherAdmin,
            'the admin was cleared here and reported changed, but the registrar was sent no admin: it keeps ADM1-GR');
    }

    /** F5: an archived contact, kept on the role it has, is offered (and taken) for the other roles too. */
    public function test_f5_an_archived_contact_is_not_spread_to_other_roles(): void
    {
        $archived = $this->contact(['first_name' => 'Archived', 'email' => 'archived@example.test']);
        $domain = $this->domain(['tech_contact_id' => $archived->id, 'tech_handle' => 'TEC1-GR']);
        $archived->forceFill(['archived_at' => now()])->save();

        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])
            ->set('editContacts', true)->set('contactRoles.admin', $archived->id)->call('saveContacts')
            ->assertHasErrors('contactRoles.admin');
        $this->assertNull($domain->fresh()->admin_contact_id);
    }

    /** Guard: tampered role values never reach the registrar nor take another account's or an archived contact. */
    public function test_guard_tampered_role_values_are_refused(): void
    {
        $domain = $this->domain();
        $theirs = Contact::factory()->create(['client_id' => User::factory()->create()->client()->id, 'email' => 'theirs@elsewhere.test']);
        $archivedUnused = $this->contact();
        $archivedUnused->forceFill(['archived_at' => now()])->save();

        foreach ([(string) $theirs->id, $theirs->id, -1, 'abc', [$theirs->id], $archivedUnused->id, $theirs->id . ' '] as $value) {
            Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])
                ->set('editContacts', true)->set('contactRoles.admin', $value)->call('saveContacts')
                ->assertHasErrors('contactRoles.admin')->assertDontSee('theirs@elsewhere.test');
        }
        // Clearing the holder where it may change: refused by the service, nothing sent.
        $domain->forceFill(['meta' => ['registrar' => ['modify_owner_allowed' => true]]])->save();
        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain->fresh()])
            ->set('editContacts', true)->set('contactRoles.registrant', null)->call('saveContacts');
        $this->assertNotNull($domain->fresh()->registrant_contact_id);
        $this->assertNull($domain->fresh()->admin_contact_id);
        $this->assertSame([], $this->puts);
    }

    /** Guard: the holder is locked on every spelling of a .gr/.ελ name, and where the flag is not a real true. */
    public function test_guard_the_holder_is_locked_on_greek_names_and_without_a_real_true(): void
    {
        $mine = $this->contact();
        $cases = [
            ['sld' => 'a', 'tld' => 'com.gr', 'fqdn' => 'a.com.gr', 'meta' => ['registrar' => ['modify_owner_allowed' => true]]],
            ['sld' => 'xn--hxa', 'tld' => 'xn--qxam', 'fqdn' => 'xn--hxa.xn--qxam', 'meta' => ['registrar' => ['modify_owner_allowed' => true]]],
            ['sld' => 'd', 'tld' => 'gr', 'fqdn' => 'd.gr', 'meta' => ['registrar' => ['modify_owner_allowed' => true]]],
            ['sld' => 'e', 'fqdn' => 'e.com', 'meta' => ['registrar' => ['modify_owner_allowed' => '1']]],
            ['sld' => 'f', 'fqdn' => 'f.com', 'meta' => ['registrar' => ['modify_owner_allowed' => 'true']]],
            ['sld' => 'g', 'fqdn' => 'g.com', 'meta' => []],
        ];
        foreach ($cases as $attributes) {
            $domain = $this->domain($attributes);
            Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])
                ->set('editContacts', true)->set('contactRoles.registrant', $mine->id)->call('saveContacts')
                ->assertHasErrors('contactRoles.registrant');
            $this->assertNotSame($mine->id, $domain->fresh()->registrant_contact_id, $attributes['fqdn']);
        }
        $this->assertSame([], $this->puts);
    }

    /** Guard: a member without managedomains, a revoked one, staff without admin.domains.manage, an ended domain. */
    public function test_guard_who_may_not_change_contacts(): void
    {
        $domain = $this->domain();
        $tech = $this->contact();
        $viewer = User::factory()->create();
        ClientUser::query()->create(['client_id' => $this->user->client()->id, 'user_id' => $viewer->id, 'granted_at' => now(), 'permissions' => ['domains']]);
        $revoked = User::factory()->create();
        ClientUser::query()->create(['client_id' => $this->user->client()->id, 'user_id' => $revoked->id, 'granted_at' => now(), 'revoked_at' => now(),
            'permissions' => ['domains', 'managedomains']]);
        foreach ([$viewer, $revoked] as $who) {
            try {
                Livewire::actingAs($who)->test(Show::class, ['domain' => $domain])->set('contactRoles.tech', $tech->id)->call('saveContacts');
            } catch (HttpException) {
            }
        }
        $this->assertNull($domain->fresh()->tech_contact_id);

        // «Login as client» without admin.domains.manage.
        session(['impersonating' => $this->user->id, CurrentClient::SESSION_KEY => $this->user->client()->id]);
        Auth::guard('staff')->setUser(Staff::factory()->withPermissions(['admin.users.impersonate', 'admin.domains.update'])->create());
        Auth::guard('web')->setUser($this->user);
        $component = app(Show::class);
        $component->domain = $domain;
        $component->mount();
        $component->contactRoles['tech'] = $tech->id;
        try {
            $component->saveContacts();
            $this->fail('changed without admin.domains.manage');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        Auth::guard('staff')->logout();
        session()->forget('impersonating');

        foreach ([DomainStatus::TransferredAway, DomainStatus::Cancelled] as $status) {
            $ended = $this->domain(['sld' => $sld = str_replace('_', '', $status->value), 'fqdn' => $sld . '.com', 'status' => $status]);
            try {
                Livewire::actingAs($this->user)->test(Show::class, ['domain' => $ended])->set('contactRoles.tech', $tech->id)->call('saveContacts');
            } catch (HttpException) {
            }
            $this->assertNull($ended->fresh()->tech_contact_id);
        }
        $this->assertSame([], $this->puts);
    }
}
