<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Admin\Resources\RoleResource\Pages\ListRoles;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use App\Support\Domains\DomainSyncResult;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Domains D4b (docs/domains.md): a domain managed from MyCS, not the registrar's panel. What the registrar holds is
 * shown; nameservers, lock, privacy and contacts change there; its EPP code is read, never stored; every call is
 * logged with who asked; a refused write changes nothing here.
 */
class DomainManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PROD = 'https://api.openprovider.eu';

    private DomainRegistrarConnection $op;

    private User $user;

    /** @var list<array<string, mixed>> the PUT bodies the registrar got */
    private array $puts = [];

    /** @var array<string, mixed> */
    private array $record = [];

    private bool $registrarDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
        Http::preventStrayRequests();
        config(['audit.console' => true]); // as in the admin: the domain's changes are audited
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->user = User::factory()->create();
    }

    private function client(): Client
    {
        return $this->user->client();
    }

    private function contact(array $attributes = []): Contact
    {
        return Contact::factory()->create($attributes + ['client_id' => $this->client()->id, 'country' => 'GR']);
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'registrar_connection_id' => $this->op->id, 'expires_at' => '2027-11-17', 'registrar_domain_id' => '30391502']);
    }

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
    }

    /**
     * Openprovider as the live answer looked (nixpal.com, 2026-10-16): a PUT changes the record the next GET returns,
     * unless $refuse says why it is refused.
     */
    private function fakeOp(array $record = [], ?string $refuse = null): void
    {
        $this->record = $record + ['id' => 30391502, 'domain' => ['name' => 'nixpal', 'extension' => 'com'], 'status' => 'ACT',
            'creation_date' => '2012-11-17 18:08:48', 'expiration_date' => '2027-11-17 18:08:48', 'is_locked' => true, 'is_lockable' => true,
            'is_private_whois_enabled' => false, 'is_private_whois_allowed' => true, 'dnssec' => 'unsigned', 'is_dnssec_enabled' => false,
            'autorenew' => 'default', 'owner_handle' => 'CP935231-GR', 'deleted_at' => '0000-00-00 00:00:00',
            'auth_code' => 'Leak-Me-Not-1', 'internal_auth_code' => 'Leak-Me-Not-2',
            'name_servers' => [['seq_nr' => 0, 'name' => 'ns1.nixpal.com', 'ip' => '192.0.2.6'], ['seq_nr' => 1, 'name' => 'ns2.nixpal.com', 'ip' => '']]];
        Http::fake(function (Request $request) use ($refuse) {
            $url = $request->url();
            if (str_contains($url, '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if ($this->registrarDown) {
                return Http::response([], 500);
            }
            if (str_contains($url, '/authcode')) {
                return Http::response(['data' => ['auth_code' => 'Epp-Code-77']]);
            }
            if (str_contains($url, '/customers/')) {
                return str_contains($url, 'CP935231-GR')
                    ? Http::response(['data' => ['name' => ['first_name' => 'Christos', 'last_name' => 'P'], 'company_name' => 'MyIP', 'email' => 'owner@example.test']])
                    : Http::response([], 404);
            }
            if ($request->method() === 'POST' && str_ends_with(parse_url($url, PHP_URL_PATH), '/customers')) {
                return Http::response(['data' => ['handle' => 'NEW123-GR']]);
            }
            if ($request->method() === 'PUT') {
                if ($refuse !== null) {
                    return Http::response(['code' => 399, 'desc' => $refuse], 400);
                }
                $body = $request->data();
                $this->puts[] = $body;
                if (isset($body['name_servers'])) {
                    $this->record['name_servers'] = array_map(fn ($ns) => ['name' => $ns['name'], 'ip' => $ns['ip'] ?? ''], $body['name_servers']);
                }
                foreach (['is_locked', 'is_private_whois_enabled', 'owner_handle', 'admin_handle', 'tech_handle', 'billing_handle'] as $key) {
                    if (array_key_exists($key, $body)) {
                        $this->record[$key] = $body[$key];
                    }
                }

                return Http::response(['data' => ['success' => true]]);
            }

            return Http::response(['data' => $this->record]);
        });
    }

    public function test_the_sync_brings_what_staff_would_look_up_in_the_registrars_panel_but_no_auth_code(): void
    {
        $tld = DomainTld::query()->create(['tld' => 'com', 'is_active' => true]);
        $domain = $this->domain(['registrant_contact_id' => $this->contact(['email' => 'Owner@Example.test'])->id, 'registered_at' => null]);
        $this->fakeOp();

        app(DomainSyncService::class)->sync($domain);

        $domain->refresh();
        $this->assertSame(['2012-11-17', 'CP935231-GR', $tld->id], [$domain->registered_at->toDateString(), $domain->registrant_handle, $domain->domain_tld_id]);
        $details = $domain->meta['registrar'];
        $this->assertSame([true, true, 'unsigned', 'default', 'ns1.nixpal.com 192.0.2.6'], [$details['is_lockable'], $details['is_private_whois_allowed'],
            $details['dnssec'], $details['autorenew'], $details['glue']]);
        $this->assertStringNotContainsString('Leak-Me-Not', json_encode(DB::table('domains')->get()) . json_encode(DB::table('audits')->get()));
    }

    public function test_nameservers_change_at_the_registrar_logged_with_who_asked_and_audited(): void
    {
        $domain = $this->domain();
        $this->fakeOp();
        $staff = $this->staff(['admin.domains.viewAny', 'admin.domains.manage']);

        app(DomainManagementService::class)->setNameservers($domain, ['NS1.Example.NET ', 'ns2.example.net', 'ns2.example.net'], $staff);

        $this->assertSame([['name' => 'ns1.example.net'], ['name' => 'ns2.example.net']], array_map(fn ($ns) => ['name' => $ns['name']], $this->puts[0]['name_servers']));
        $this->assertSame(['ns1.example.net', 'ns2.example.net'], $domain->fresh()->nameservers()->orderBy('sort')->pluck('host')->all());
        $log = DomainRegistrarLog::query()->sole();
        $this->assertSame(['nameservers', DomainRegistrarLog::STATUS_OK, $staff->id], [$log->action, $log->status, $log->staff_id]);
        $this->assertTrue($domain->audits()->where('event', 'updated')->exists());
    }

    public function test_a_refused_write_changes_nothing_here_and_is_logged(): void
    {
        $domain = $this->domain(['transfer_lock' => true]);
        $this->fakeOp([], refuse: 'Domain is in a state that does not allow this');

        try {
            app(DomainManagementService::class)->setTransferLock($domain, false, null);
            $this->fail('the refusal must reach staff');
        } catch (RuntimeException) {
        }

        $this->assertTrue($domain->fresh()->transfer_lock);
        $log = DomainRegistrarLog::query()->sole();
        $this->assertSame(['unlock', DomainRegistrarLog::STATUS_FAILED], [$log->action, $log->status]);
        $this->assertNotEmpty($log->error);
    }

    public function test_nameservers_are_checked_before_anything_is_sent(): void
    {
        $domain = $this->domain();
        $this->fakeOp();

        foreach ([['ns1.example.net'], ['ns1.example.net', 'not a host!'], array_map(fn ($i) => "ns{$i}.example.net", range(1, 14))] as $hosts) {
            try {
                app(DomainManagementService::class)->setNameservers($domain, $hosts, null);
                $this->fail('refused: ' . implode(',', $hosts));
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame([], $this->puts);
        $this->assertSame(0, DomainRegistrarLog::query()->count());
    }

    public function test_lock_and_privacy_follow_the_registrars_answer(): void
    {
        $domain = $this->domain(['transfer_lock' => true, 'id_protection' => false]);
        $this->fakeOp();

        app(DomainManagementService::class)->setTransferLock($domain, false, null);
        app(DomainManagementService::class)->setWhoisPrivacy($domain->fresh(), true, null);

        $this->assertSame([['is_locked' => false], ['is_private_whois_enabled' => true]], $this->puts);
        $this->assertSame([false, true], [$domain->fresh()->transfer_lock, $domain->fresh()->id_protection]);
    }

    public function test_the_epp_code_is_shown_but_never_stored_or_logged(): void
    {
        $domain = $this->domain();
        $this->fakeOp();
        $staff = $this->staff(['admin.domains.viewAny', 'admin.domains.epp']);

        $this->assertSame('Epp-Code-77', app(DomainManagementService::class)->eppCode($domain, $staff));

        $log = DomainRegistrarLog::query()->sole();
        $this->assertSame(['epp_code', DomainRegistrarLog::STATUS_OK, $staff->id], [$log->action, $log->status, $log->staff_id]);
        $everything = json_encode(DB::table('domain_registrar_logs')->get()) . json_encode(DB::table('domains')->get()) . json_encode(DB::table('audits')->get());
        $this->assertStringNotContainsString('Epp-Code-77', $everything);
    }

    public function test_contacts_are_the_accounts_and_a_refusal_puts_the_roles_back(): void
    {
        $owner = $this->contact();
        $other = $this->contact();
        $stranger = Contact::factory()->create(['client_id' => User::factory()->create()->client()->id, 'country' => 'GR']);
        $domain = $this->domain(['registrant_contact_id' => $owner->id, 'registrant_handle' => 'CP935231-GR']);
        $this->fakeOp([], refuse: 'Invalid handle');

        $this->expectExceptionThrown(fn () => app(DomainManagementService::class)->setContacts($domain, ['tech' => $stranger->id], null), InvalidArgumentException::class);
        $this->expectExceptionThrown(fn () => app(DomainManagementService::class)->setContacts($domain, ['registrant' => null], null), InvalidArgumentException::class);
        $this->expectExceptionThrown(fn () => app(DomainManagementService::class)->setContacts($domain, ['tech' => $other->id], null), RuntimeException::class);

        $domain->refresh();
        $this->assertSame([$owner->id, 'CP935231-GR', null, null], [$domain->registrant_contact_id, $domain->registrant_handle, $domain->tech_contact_id, $domain->tech_handle]);
    }

    public function test_a_contact_changed_gets_its_handle_at_the_registrar(): void
    {
        $owner = $this->contact();
        $tech = $this->contact();
        $domain = $this->domain(['registrant_contact_id' => $owner->id, 'registrant_handle' => 'CP935231-GR']);
        $this->fakeOp();

        app(DomainManagementService::class)->setContacts($domain, ['tech' => $tech->id], null);

        $domain->refresh();
        $this->assertSame([$tech->id, 'NEW123-GR', 'CP935231-GR'], [$domain->tech_contact_id, $domain->tech_handle, $domain->registrant_handle]);
        $this->assertSame('NEW123-GR', end($this->puts)['tech_handle']);
    }

    public function test_one_change_at_a_time_per_domain(): void
    {
        $domain = $this->domain();
        $this->fakeOp();
        $held = Cache::lock("domain-registrar:{$domain->id}", 120);
        $this->assertTrue($held->get());

        $this->expectExceptionThrown(fn () => app(DomainManagementService::class)->setTransferLock($domain, false, null), RuntimeException::class);
        $this->assertSame([], $this->puts);
        $held->release();
    }

    public function test_the_admin_shows_each_action_to_staff_who_may(): void
    {
        $domain = $this->domain();
        $this->fakeOp();
        app(DomainSyncService::class)->sync($domain);
        $page = fn (array $permissions) => $this->livewireAs($this->staff($permissions))->test(ViewDomain::class, ['record' => $domain->getRouteKey()]);

        $page(['admin.domains.viewAny'])->assertSee('CP935231-GR')->assertSee('ns1.nixpal.com 192.0.2.6')
            ->assertActionHidden('nameservers')->assertActionHidden('eppCode')->assertActionHidden('edit');
        $page(['admin.domains.viewAny', 'admin.domains.manage'])->assertActionVisible('nameservers')->assertActionVisible('lock')->assertActionHidden('eppCode');
        $page(['admin.domains.viewAny', 'admin.domains.epp'])->assertActionVisible('eppCode')->assertActionHidden('nameservers');

        $page(['admin.domains.viewAny', 'admin.domains.update'])->callAction('edit', ['auto_renew' => false, 'notes' => 'Customer asked to let it go.'])
            ->assertHasNoActionErrors();
        $this->assertSame([false, 'Customer asked to let it go.'], [$domain->fresh()->auto_renew, $domain->fresh()->notes]);
        $this->assertTrue($domain->audits()->where('event', 'updated')->get()->contains(fn ($audit) => array_key_exists('notes', $audit->new_values)));

        $undoRepeaterFake = Repeater::fake(); // list keys, as the test sends them
        $page(['admin.domains.viewAny', 'admin.domains.manage'])
            ->callAction('nameservers', ['nameservers' => [['host' => 'ns1.example.net'], ['host' => 'ns2.example.net']]])
            ->assertNotified(__('Nameservers changed at the registrar.'));
        // Submitted as filled (the current ones): sent unchanged, the shape the form really gives.
        $page(['admin.domains.viewAny', 'admin.domains.manage'])->callAction('nameservers')->assertNotified(__('Nameservers changed at the registrar.'));
        $this->assertSame(['ns1.example.net', 'ns2.example.net'], array_column(end($this->puts)['name_servers'], 'name'));
        $undoRepeaterFake();
        $this->assertSame(['ns1.example.net', 'ns2.example.net'], $domain->fresh()->nameservers()->orderBy('sort')->pluck('host')->all());
    }

    /** Code review 3: a nameserver inside the domain goes with its glue IP (OP refuses it without one). */
    public function test_an_in_domain_nameserver_is_sent_with_its_glue(): void
    {
        OpenproviderRegistrar::$glueResolver = fn (string $host) => $host === 'ns1.nixpal.com' ? '192.0.2.6' : null;
        $domain = $this->domain();
        $this->fakeOp();

        try {
            app(DomainManagementService::class)->setNameservers($domain, ['ns1.nixpal.com', 'ns2.example.net'], null);
        } finally {
            OpenproviderRegistrar::$glueResolver = null;
        }

        $this->assertSame([['name' => 'ns1.nixpal.com', 'ip' => '192.0.2.6'], ['name' => 'ns2.example.net']], $this->puts[0]['name_servers']);
    }

    /**
     * Code reviews 1, 2: the registrar's handle pairs only with a role that has a contact and no handle; a different
     * one is said, never swapped in; a contact-less role keeps none, so a new registrant is not followed by the old one.
     */
    public function test_registrar_handles_never_pair_silently(): void
    {
        $owner = $this->contact(['email' => 'owner@example.test']);
        $domain = $this->domain(['registrant_contact_id' => $owner->id]);
        $this->fakeOp(['admin_handle' => 'CP935231-GR', 'tech_handle' => 'CP935231-GR']);

        app(DomainSyncService::class)->sync($domain);
        $domain->refresh();
        $this->assertSame(['CP935231-GR', null, null], [$domain->registrant_handle, $domain->admin_handle, $domain->tech_handle]);
        $this->assertStringContainsString('admin CP935231-GR', $domain->meta['registrar']['handles']);

        $domain->forceFill(['registrant_handle' => 'OURS-1'])->save();
        app(DomainSyncService::class)->sync($domain->fresh());
        $this->assertSame('OURS-1', $domain->fresh()->registrant_handle);
        $this->assertStringContainsString('registrant handle is CP935231-GR', (string) $domain->fresh()->sync_error);
    }

    /** Code review 8: an imported registration date stays; code review 7: a write whose re-read failed is not «synced». */
    public function test_a_known_registration_date_stays_and_an_empty_reread_is_not_a_sync(): void
    {
        $domain = $this->domain(['registered_at' => '2012-11-16']);
        $this->fakeOp();
        app(DomainSyncService::class)->sync($domain);
        $this->assertSame('2012-11-16', $domain->fresh()->registered_at->toDateString());

        $domain->forceFill(['sync_error' => 'old error'])->save();
        $this->assertSame([], app(DomainSyncService::class)->applyResult($domain->fresh(), new DomainSyncResult(registrarDomainId: '30391502')));
        $this->assertSame('old error', $domain->fresh()->sync_error);
    }

    /** Code review 4: the EPP code is rendered in its modal only, never sent as a notification (Filament caches those). */
    public function test_the_epp_code_shows_in_its_modal_not_in_a_notification(): void
    {
        $domain = $this->domain();
        $this->fakeOp();

        $this->livewireAs($this->staff(['admin.domains.viewAny', 'admin.domains.epp']))->test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->mountAction('eppCode')->assertMountedActionModalSee('Epp-Code-77')->assertNotNotified();
        $this->assertSame(1, DomainRegistrarLog::query()->where('action', 'epp_code')->count(), 'one read, however often it renders');
    }

    /** Code review 6a: a refused contact change is put back with an audit, as it was made. */
    public function test_a_refused_contact_change_is_put_back_audited(): void
    {
        $owner = $this->contact();
        $other = $this->contact();
        $domain = $this->domain(['registrant_contact_id' => $owner->id, 'registrant_handle' => 'CP935231-GR']);
        $this->fakeOp([], refuse: 'Invalid handle');

        $this->expectExceptionThrown(fn () => app(DomainManagementService::class)->setContacts($domain, ['tech' => $other->id], null), RuntimeException::class);

        $reverted = $domain->audits()->where('event', 'updated')->get()->filter(fn ($audit) => array_key_exists('tech_contact_id', $audit->new_values));
        $this->assertSame([$other->id, null], $reverted->map(fn ($audit) => $audit->new_values['tech_contact_id'])->values()->all());
    }

    /** Adversarial F6: a new registrant only where the registrar lets the owner be modified; never an unlinked one sent. */
    public function test_a_registrant_change_only_where_the_owner_may_be_modified(): void
    {
        $owner = $this->contact();
        $buyer = $this->contact();
        $tech = $this->contact();
        $this->fakeOp();
        $unlinked = $this->domain(['registrant_contact_id' => $owner->id, 'registrant_handle' => null]);
        $this->expectExceptionThrown(fn () => app(DomainManagementService::class)->setContacts($unlinked, ['tech' => $tech->id], null), InvalidArgumentException::class);
        $this->assertSame([], $this->puts, 'an unlinked registrant would have gone as a new owner');

        $domain = $this->domain(['fqdn' => 'nixpal2.com', 'sld' => 'nixpal2', 'registrar_domain_id' => '30391503', 'registrant_contact_id' => $owner->id,
            'registrant_handle' => 'CP935231-GR', 'meta' => ['registrar' => ['modify_owner_allowed' => true]]]);
        app(DomainManagementService::class)->setContacts($domain, ['registrant' => $buyer->id], null);
        $this->assertSame([$buyer->id, 'NEW123-GR'], [$domain->fresh()->registrant_contact_id, end($this->puts)['owner_handle']]);
    }

    /** D4c: a handle the registrar reports but nobody linked is shown and read; staff link it to an existing contact. */
    public function test_staff_link_the_registrars_handle_to_an_existing_contact(): void
    {
        $owner = $this->contact(['email' => 'someone-else@example.test']);
        $domain = $this->domain(['registrant_contact_id' => $owner->id]);
        $this->fakeOp();
        app(DomainSyncService::class)->sync($domain);
        $domain->refresh();
        $service = app(DomainManagementService::class);
        $this->assertSame(['CP935231-GR', true], [$service->handleFor($domain, 'registrant'), $service->isUnlinked($domain, 'registrant')]);
        $this->assertNotNull($domain->sync_error);
        $this->assertSame('owner@example.test', $service->registrarContact($domain, 'registrant', null)?->email, 'read though not linked');
        $staff = $this->staff(['admin.domains.viewAny', 'admin.domains.manage']);
        $this->livewireAs($staff)->test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->assertSee('CP935231-GR (not linked here)', false);

        $service->linkContact($domain, 'registrant', $owner->id, $staff);

        $domain->refresh();
        $this->assertSame([$owner->id, 'CP935231-GR', null], [$domain->registrant_contact_id, $domain->registrant_handle, $domain->sync_error]);
        $this->assertSame([], $this->puts, 'nothing sent to the registrar');
        $this->assertSame(['link_contact', $staff->id], [DomainRegistrarLog::query()->where('action', 'link_contact')->value('action'), DomainRegistrarLog::query()->where('action', 'link_contact')->value('staff_id')]);
        $this->expectExceptionThrown(fn () => $service->linkContact($domain, 'registrant', $owner->id, null), InvalidArgumentException::class);
    }

    /** D4c: the agency case: the registrar's registrant becomes a new contact of the account, linked. */
    public function test_staff_link_the_registrars_handle_as_a_new_contact_of_the_account(): void
    {
        $agency = $this->contact(['email' => 'agency@example.test']);
        $stranger = Contact::factory()->create(['client_id' => User::factory()->create()->client()->id, 'country' => 'GR']);
        $domain = $this->domain(['registrant_contact_id' => $agency->id]);
        $this->fakeOp();
        app(DomainSyncService::class)->sync($domain);
        $domain->refresh();
        $service = app(DomainManagementService::class);
        $this->expectExceptionThrown(fn () => $service->linkContact($domain, 'registrant', $stranger->id, null), InvalidArgumentException::class);

        $contact = $service->linkContact($domain, 'registrant', null, null);

        $this->assertSame([$this->client()->id, 'owner@example.test', 'MyIP', 'Christos', 'P'], [$contact->client_id, $contact->email, $contact->company_name, $contact->first_name, $contact->last_name]);
        $this->assertSame([$contact->id, 'CP935231-GR'], [$domain->fresh()->registrant_contact_id, $domain->fresh()->registrant_handle]);
        $this->assertSame([], $this->puts);
    }

    /** D4c: the contact of a role is edited from the domain, with the account's permission. */
    public function test_a_roles_contact_is_edited_from_the_domain(): void
    {
        $owner = $this->contact(['first_name' => 'Old']);
        $domain = $this->domain(['registrant_contact_id' => $owner->id, 'registrant_handle' => 'CP935231-GR']);
        $this->fakeOp();
        $page = fn (array $permissions) => $this->livewireAs($this->staff($permissions))->test(ViewDomain::class, ['record' => $domain->getRouteKey()]);

        $page(['admin.domains.viewAny'])->assertDontSee(__('Edit contact'));
        $page(['admin.domains.viewAny', 'admin.clients.update'])
            ->callAction(TestAction::make('editContact_registrant')->schemaComponent('contacts_registrant_actions', 'infolist'), ['first_name' => 'New'])
            ->assertHasNoActionErrors();

        $this->assertSame('New', $owner->fresh()->first_name);
    }

    /** D4c: the full administrators' role is not offered for editing (it was a bare 403). */
    public function test_the_full_admin_role_is_not_offered_for_editing(): void
    {
        $admin = Role::query()->find(1) ?? Role::create(['id' => 1, 'name' => 'admin', 'permissions' => ['*']]);
        $other = Role::create(['name' => 'Support', 'permissions' => ['admin.domains.viewAny']]);
        $staff = Staff::factory()->create(['role_id' => $admin->id]);

        $this->livewireAs($staff)->test(ListRoles::class)
            ->assertTableActionHidden('edit', $admin)->assertTableActionVisible('edit', $other)
            ->assertSee(__('Full access, not editable'));
    }

    /** D4c review: every role the registrar gives the same handle is linked together, to one contact, never lent on. */
    public function test_a_handle_on_several_roles_is_linked_once_and_not_lent_to_other_roles(): void
    {
        $agency = $this->contact(['email' => 'agency@example.test']);
        $tech = $this->contact();
        $domain = $this->domain(['registrant_contact_id' => $agency->id]);
        $this->fakeOp(['admin_handle' => 'CP935231-GR', 'billing_handle' => 'CP935231-GR']);
        app(DomainSyncService::class)->sync($domain);

        $contact = app(DomainManagementService::class)->linkContact($domain->fresh(), 'registrant', null, null);

        $domain->refresh();
        $this->assertSame([$contact->id, $contact->id, $contact->id], [$domain->registrant_contact_id, $domain->admin_contact_id, $domain->billing_contact_id]);
        $this->assertSame(1, Contact::query()->where('email', 'owner@example.test')->count(), 'one new contact, not one per role');

        // Tech becomes the agency's own contact: it gets its own handle, not the end customer's linked by staff.
        $domain->forceFill(['tech_contact_id' => null])->save();
        app(DomainManagementService::class)->setContacts($domain->fresh(), ['tech' => $tech->id], null);
        $this->assertSame('NEW123-GR', end($this->puts)['tech_handle']);
    }

    /** D4c review: a link stands when the re-read after it fails; it is not reported as failed and redone. */
    public function test_a_link_stands_when_the_reread_fails(): void
    {
        $owner = $this->contact(['email' => 'other@example.test']);
        $domain = $this->domain(['registrant_contact_id' => $owner->id]);
        $this->fakeOp();
        app(DomainSyncService::class)->sync($domain);
        $this->registrarDown = true;

        app(DomainManagementService::class)->linkContact($domain->fresh(), 'registrant', $owner->id, null);

        $this->assertSame('CP935231-GR', $domain->fresh()->registrant_handle);
        $log = DomainRegistrarLog::query()->where('action', 'link_contact')->sole();
        $this->assertSame([DomainRegistrarLog::STATUS_OK, false], [$log->status, $log->response['synced']]);
    }

    /** @param  class-string<\Throwable>  $class */
    private function expectExceptionThrown(callable $call, string $class): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e, $e->getMessage());

            return;
        }
        $this->fail("expected {$class}");
    }
}
