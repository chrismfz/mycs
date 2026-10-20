<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Admin\Resources\RoleResource;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainSyncService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of D4c (PR #165): registrar handles linked by staff to a contact of the account, the role's
 * contact edited from the domain, the domain page's layout, the full admin role not editable.
 */
class DomainsD4cAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $op;

    private User $user;

    /** @var list<array<string, mixed>> */
    private array $puts = [];

    /** @var array<string, mixed> */
    private array $record = [];

    /** @var array<string, array<string, mixed>> handle => customer data */
    private array $customers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
        Http::preventStrayRequests();
        config(['audit.console' => true]);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->user = User::factory()->create();
        $this->customers = [
            'CP935231-GR' => ['name' => ['first_name' => 'Christos', 'last_name' => 'P'], 'company_name' => 'MyIP', 'email' => 'owner@example.test',
                'address' => ['street' => 'Odos', 'number' => '12', 'city' => 'Athens', 'zipcode' => '11111', 'country' => 'GR']],
        ];
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

    private function fakeOp(array $record = []): void
    {
        $this->record = $record + ['id' => 30391502, 'domain' => ['name' => 'nixpal', 'extension' => 'com'], 'status' => 'ACT',
            'creation_date' => '2012-11-17 18:08:48', 'expiration_date' => '2027-11-17 18:08:48', 'is_locked' => true, 'is_lockable' => true,
            'is_private_whois_enabled' => false, 'is_private_whois_allowed' => true, 'dnssec' => 'unsigned', 'is_dnssec_enabled' => false,
            'autorenew' => 'default', 'owner_handle' => 'CP935231-GR', 'deleted_at' => '0000-00-00 00:00:00',
            'name_servers' => [['seq_nr' => 0, 'name' => 'ns1.nixpal.com', 'ip' => '192.0.2.6'], ['seq_nr' => 1, 'name' => 'ns2.nixpal.com', 'ip' => '']]];
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if (str_contains($url, '/customers/')) {
                $handle = rawurldecode(basename(parse_url($url, PHP_URL_PATH)));

                return isset($this->customers[$handle]) ? Http::response(['data' => $this->customers[$handle]]) : Http::response([], 404);
            }
            if ($request->method() === 'POST' && str_ends_with(parse_url($url, PHP_URL_PATH), '/customers')) {
                return Http::response(['data' => ['handle' => 'NEW123-GR']]);
            }
            if ($request->method() === 'PUT') {
                $body = $request->data();
                $this->puts[] = $body;
                foreach (['owner_handle', 'admin_handle', 'tech_handle', 'billing_handle'] as $key) {
                    if (array_key_exists($key, $body)) {
                        $this->record[$key] = $body[$key];
                    }
                }

                return Http::response(['data' => ['success' => true]]);
            }

            return Http::response(['data' => $this->record]);
        });
    }

    /** A domain whose registrant is linked and whose admin the registrar reports (OLD1-GR) but nobody linked. */
    private function domainWithUnlinkedAdmin(): Domain
    {
        $owner = $this->contact(['email' => 'owner@example.test']);
        $domain = $this->domain(['registrant_contact_id' => $owner->id, 'registrant_handle' => 'CP935231-GR']);
        $this->fakeOp(['admin_handle' => 'OLD1-GR']);
        app(DomainSyncService::class)->sync($domain);
        $domain->refresh();
        $this->assertTrue(app(DomainManagementService::class)->isUnlinked($domain, 'admin'));

        return $domain;
    }

    private function page(Staff $staff, Domain $domain)
    {
        return $this->livewireAs($staff)->test(ViewDomain::class, ['record' => $domain->getRouteKey()]);
    }

    // ---------------------------------------------------------------- findings

    /**
     * A1: the handle linked is the one of the LAST sync (meta), never checked against the registrar now. The sync
     * inside linkContact sees the registrar's admin is another handle, writes a warning, and keeps the stale link.
     */
    public function test_a1_a_stale_handle_is_not_linked(): void
    {
        $domain = $this->domainWithUnlinkedAdmin();
        $this->record['admin_handle'] = 'NEW9-GR'; // changed at the registrar since the last sync
        $x = $this->contact();

        try {
            app(DomainManagementService::class)->linkContact($domain, 'admin', $x->id, null);
        } catch (InvalidArgumentException) {
            // refusing is fine too
        }

        $this->assertNotSame('OLD1-GR', $domain->fresh()->admin_handle, 'a handle the registrar no longer has for the role was linked');
    }

    /**
     * A1b: the consequence. The next contacts write (another role) sends every role's handle: the stale one is sent
     * as the admin, and the registrar's admin (WHOIS) changes without anyone asking.
     */
    public function test_a1b_a_stale_link_does_not_rewrite_the_registrars_admin_on_the_next_contacts_write(): void
    {
        $domain = $this->domainWithUnlinkedAdmin();
        $this->record['admin_handle'] = 'NEW9-GR';
        $x = $this->contact();
        $y = $this->contact();
        $service = app(DomainManagementService::class);
        try {
            $service->linkContact($domain, 'admin', $x->id, null);
        } catch (InvalidArgumentException) {
        }

        $service->setContacts($domain->fresh(), ['tech' => $y->id], null);

        // Fix round: a role we hold no handle for is left out of the PUT (the registrar keeps it); sent, it must be NEW9.
        $this->assertSame('NEW9-GR', end($this->puts)['admin_handle'] ?? 'NEW9-GR', 'the registrar\'s admin was changed by a tech change');
    }

    /**
     * A2: isUnlinked is checked on the caller's (possibly stale) model, outside the domain's lock and never again inside
     * it: two staff linking the same role at once (two requests) both «succeed», the second overwrites the first.
     */
    public function test_a2_a_role_linked_meanwhile_is_not_linked_again(): void
    {
        $domain = $this->domainWithUnlinkedAdmin();
        $staleCopy = Domain::query()->findOrFail($domain->id); // the other request's model, loaded before the first link
        $first = $this->contact();
        $second = $this->contact();
        $service = app(DomainManagementService::class);
        $service->linkContact($domain, 'admin', $first->id, null);

        try {
            $service->linkContact($staleCopy, 'admin', $second->id, null);
        } catch (InvalidArgumentException) {
        }

        $this->assertSame($first->id, $domain->fresh()->admin_contact_id, 'the second link silently replaced the first');
    }

    /** A2b: the same race with «a new contact»: a second contact of the account is made from the same handle. */
    public function test_a2b_a_role_linked_meanwhile_makes_no_second_contact(): void
    {
        $this->customers['OLD1-GR'] = ['name' => ['first_name' => 'End', 'last_name' => 'Customer'], 'email' => 'end@example.test',
            'address' => ['country' => 'GR']];
        $domain = $this->domainWithUnlinkedAdmin();
        $staleCopy = Domain::query()->findOrFail($domain->id);
        $service = app(DomainManagementService::class);
        $service->linkContact($domain, 'admin', null, null);
        $count = Contact::query()->count();

        try {
            $service->linkContact($staleCopy, 'admin', null, null);
        } catch (InvalidArgumentException) {
        }

        $this->assertSame($count, Contact::query()->count(), 'a duplicate contact was made for a role already linked');
    }

    /**
     * A3: linking one role re-links every unlinked role with the same handle (review 3), also a role that HAS a contact
     * here. Linking the admin replaces the registrant's contact (the domain's owner here, the account's billing party)
     * though staff picked the admin: an owner change here that setContacts refuses without modify_owner_allowed.
     */
    public function test_a3_linking_the_admin_does_not_replace_the_registrants_contact(): void
    {
        $owner = $this->contact(['email' => 'not-the-registrars@example.test']); // email differs: registrant stays unlinked
        $domain = $this->domain(['registrant_contact_id' => $owner->id]);
        $this->fakeOp(['admin_handle' => 'CP935231-GR']); // contact-less admin falls back to the owner's handle at OP
        app(DomainSyncService::class)->sync($domain);
        $domain->refresh();
        $this->assertTrue(app(DomainManagementService::class)->isUnlinked($domain, 'registrant'));
        $adminContact = $this->contact();

        app(DomainManagementService::class)->linkContact($domain, 'admin', $adminContact->id, null);

        $this->assertSame($owner->id, $domain->fresh()->registrant_contact_id, 'the registrant\'s contact was replaced by an admin link');
    }

    /**
     * A4: the form leaves archived contacts out, the service still links one. Contact::archive refuses a contact that holds a domain role
     * (D-3): linking one makes an archived contact a role's registrar data.
     */
    public function test_a4_an_archived_contact_is_not_linked(): void
    {
        $domain = $this->domainWithUnlinkedAdmin();
        $archived = $this->contact(['archived_at' => now()]);

        try {
            app(DomainManagementService::class)->linkContact($domain, 'admin', $archived->id, null);
        } catch (InvalidArgumentException) {
        }
        $this->assertNull($domain->fresh()->admin_contact_id, 'an archived contact holds a domain role');
    }

    /**
     * A5: a «new contact» for a handle already linked to a contact of this domain (a contact-less role falls back to
     * the registrant's handle at the registrar, so admin/tech/billing usually report the registrant's) makes a
     * duplicate of that contact instead of reusing it.
     */
    public function test_a5_a_new_contact_is_not_made_for_a_handle_already_linked_here(): void
    {
        $owner = $this->contact(['email' => 'owner@example.test']);
        $domain = $this->domain(['registrant_contact_id' => $owner->id, 'registrant_handle' => 'CP935231-GR']);
        $this->fakeOp(['admin_handle' => 'CP935231-GR']);
        app(DomainSyncService::class)->sync($domain);
        $domain->refresh();
        $count = Contact::query()->count();

        $linked = app(DomainManagementService::class)->linkContact($domain, 'admin', null, null);

        $this->assertSame([$count, $owner->id], [Contact::query()->count(), $linked->id], 'the registrant\'s contact was duplicated');
    }

    /** A6: the registrar's text longer than a column (255) fails the link with a database error instead of fitting it. */
    public function test_a6_long_registrar_text_does_not_break_the_link(): void
    {
        $this->customers['OLD1-GR'] = ['name' => ['first_name' => str_repeat('Α', 300), 'last_name' => 'B'],
            'company_name' => str_repeat('C', 400), 'address' => ['street' => str_repeat('S', 300), 'city' => 'Athens', 'country' => 'GR']];
        $domain = $this->domainWithUnlinkedAdmin();

        try {
            $contact = app(DomainManagementService::class)->linkContact($domain, 'admin', null, null);
        } catch (QueryException $e) {
            $this->fail('the link broke on the registrar\'s data: ' . mb_substr($e->getMessage(), 0, 120));
        }

        $this->assertSame($contact->id, $domain->fresh()->admin_contact_id);
    }

    // ---------------------------------------------------------------- holds

    public function test_holds_another_accounts_contact_is_not_linked_by_service_or_livewire(): void
    {
        $domain = $this->domainWithUnlinkedAdmin();
        $stranger = Contact::factory()->create(['client_id' => User::factory()->create()->client()->id, 'country' => 'GR']);
        try {
            app(DomainManagementService::class)->linkContact($domain, 'admin', $stranger->id, null);
            $this->fail('linked');
        } catch (InvalidArgumentException) {
        }

        $this->page($this->staff(['admin.domains.viewAny', 'admin.domains.manage']), $domain)
            ->callAction(TestAction::make('linkContact_admin')->schemaComponent('contacts_admin_actions', 'infolist'), ['contact' => (string) $stranger->id]);

        $this->assertNull($domain->fresh()->admin_contact_id);
    }

    public function test_holds_a_linked_role_or_an_unknown_role_is_not_linked(): void
    {
        $domain = $this->domainWithUnlinkedAdmin();
        $service = app(DomainManagementService::class);
        foreach (['registrant', 'owner', 'admin_contact_id', ''] as $role) {
            try {
                $service->linkContact($domain, $role, $this->contact()->id, null);
                $this->fail("linked {$role}");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame('CP935231-GR', $domain->fresh()->registrant_handle);
    }

    public function test_holds_link_and_edit_need_their_permissions_even_through_forged_livewire_calls(): void
    {
        $domain = $this->domainWithUnlinkedAdmin();
        $x = $this->contact();
        $viewer = $this->staff(['admin.domains.viewAny']);

        try {
            $this->page($viewer, $domain)
                ->callAction(TestAction::make('linkContact_admin')->schemaComponent('contacts_admin_actions', 'infolist'), ['contact' => (string) $x->id]);
        } catch (Throwable) {
        }
        try {
            $this->page($viewer, $domain)
                ->callAction(TestAction::make('editContact_registrant')->schemaComponent('contacts_registrant_actions', 'infolist'), ['first_name' => 'Hacked']);
        } catch (Throwable) {
        }
        // manage but not clients.update: may link, may not edit
        try {
            $this->page($this->staff(['admin.domains.viewAny', 'admin.domains.manage']), $domain)
                ->callAction(TestAction::make('editContact_registrant')->schemaComponent('contacts_registrant_actions', 'infolist'), ['first_name' => 'Hacked']);
        } catch (Throwable) {
        }

        $this->assertNull($domain->fresh()->admin_contact_id);
        $this->assertNotSame('Hacked', $domain->fresh()->registrantContact->first_name);
        $this->assertSame(0, DomainRegistrarLog::query()->where('action', 'link_contact')->count());
    }

    public function test_holds_a_new_contact_needs_the_account_permission_even_forged(): void
    {
        $this->customers['OLD1-GR'] = ['name' => ['first_name' => 'End', 'last_name' => 'Customer'], 'address' => ['country' => 'GR']];
        $domain = $this->domainWithUnlinkedAdmin();
        $count = Contact::query()->count();

        try {
            $this->page($this->staff(['admin.domains.viewAny', 'admin.domains.manage']), $domain)
                ->callAction(TestAction::make('linkContact_admin')->schemaComponent('contacts_admin_actions', 'infolist'), ['contact' => 'new']);
        } catch (Throwable) {
        }

        $this->assertSame([$count, null], [Contact::query()->count(), $domain->fresh()->admin_contact_id]);
    }

    public function test_holds_another_accounts_contact_on_a_role_is_not_editable_from_the_domain(): void
    {
        $foreign = Contact::factory()->create(['client_id' => User::factory()->create()->client()->id, 'country' => 'GR', 'first_name' => 'Theirs']);
        $domain = $this->domain(['registrant_contact_id' => $foreign->id, 'registrant_handle' => 'CP935231-GR']);
        $this->fakeOp();

        try {
            $this->page($this->staff(['admin.domains.viewAny', 'admin.clients.update']), $domain)
                ->callAction(TestAction::make('editContact_registrant')->schemaComponent('contacts_registrant_actions', 'infolist'), ['first_name' => 'Hacked']);
        } catch (Throwable) {
        }

        $this->assertSame('Theirs', $foreign->fresh()->first_name);
    }

    public function test_holds_edit_contact_does_not_take_fields_outside_the_form(): void
    {
        $owner = $this->contact(['first_name' => 'Old']);
        $owner->makePrimary();
        $other = User::factory()->create()->client();
        $domain = $this->domain(['registrant_contact_id' => $owner->id, 'registrant_handle' => 'CP935231-GR']);
        $this->fakeOp();
        $before = $owner->fresh()->only(['client_id', 'primary_of_client_id', 'vat_status', 'archived_at']);

        $this->page($this->staff(['admin.domains.viewAny', 'admin.clients.update']), $domain)
            ->callAction(TestAction::make('editContact_registrant')->schemaComponent('contacts_registrant_actions', 'infolist'), [
                'first_name' => 'New', 'address1' => 'Odos 9', 'client_id' => $other->id, 'primary_of_client_id' => null, 'vat_status' => 'valid',
                'archived_at' => now()->toDateTimeString(), 'id' => 999999,
            ]);

        $fresh = $owner->fresh();
        // P1b-1: the details change, the name is locked while the domain uses the contact.
        $this->assertSame(['Old', 'Odos 9'], [$fresh->first_name, $fresh->address1]);
        $this->assertSame($before, $fresh->only(['client_id', 'primary_of_client_id', 'vat_status', 'archived_at']));
    }

    public function test_holds_hostile_registrar_text_is_escaped_on_the_page(): void
    {
        $xss = '<img src=x onerror=alert(1)>';
        $this->customers['OLD1-GR'] = ['name' => ['first_name' => '<script>alert(1)</script>', 'last_name' => 'X'],
            'company_name' => $xss, 'email' => 'e@example.test', 'address' => ['street' => $xss, 'city' => $xss, 'country' => 'GR']];
        $owner = $this->contact();
        $domain = $this->domain(['registrant_contact_id' => $owner->id, 'registrant_handle' => 'CP935231-GR',
            'meta' => ['registrar_handles' => ['tech' => $xss]]]);
        $this->fakeOp(['admin_handle' => 'OLD1-GR']);
        app(DomainSyncService::class)->sync($domain);
        $domain->refresh();
        $domain->forceFill(['meta' => ['registrar_handles' => ['tech' => $xss, 'admin' => 'OLD1-GR']] + $domain->meta])->save();
        app(DomainManagementService::class)->linkContact($domain->fresh(), 'admin', null, null);
        $staff = $this->staff(['admin.domains.viewAny', 'admin.domains.manage', 'admin.clients.update']);

        $this->page($staff, $domain->fresh())
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee($xss, false)
            ->mountAction(TestAction::make('registrarContact_tech')->schemaComponent('contacts_tech_actions', 'infolist'))
            ->assertDontSee($xss, false);
    }

    public function test_holds_viewing_the_page_calls_no_registrar_and_a_domain_without_meta_renders(): void
    {
        $domain = $this->domainWithUnlinkedAdmin();
        $logs = DomainRegistrarLog::query()->count();
        Http::fake(fn () => throw new \RuntimeException('no registrar call on a page view'));
        $staff = $this->staff(['admin.domains.viewAny', 'admin.domains.manage', 'admin.clients.update']);

        $this->page($staff, $domain)->assertOk()->assertSee('OLD1-GR (not linked here)', false);
        $this->assertSame($logs, DomainRegistrarLog::query()->count());

        $bare = Domain::factory()->create(['client_id' => $this->client()->id, 'sld' => 'bare', 'tld' => 'gr', 'fqdn' => 'bare.gr',
            'registrar_connection_id' => null, 'meta' => null]);
        $this->page($staff, $bare)->assertOk();
    }

    public function test_holds_the_full_admin_role_cannot_be_edited_by_url(): void
    {
        $admin = Role::query()->find(1) ?? Role::create(['id' => 1, 'name' => 'admin', 'permissions' => ['*']]);
        $staff = Staff::factory()->create(['role_id' => $admin->id]);

        $this->actingAs($staff)->get(RoleResource::getUrl('edit', ['record' => $admin]))->assertForbidden();
    }
}
