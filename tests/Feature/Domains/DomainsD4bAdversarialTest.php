<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\DomainStatus;
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
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of D4b (PR #164): a domain managed from the admin. Each finding is a test that fails on the code
 * as reviewed; the «holds» tests are attacks that did not break it.
 */
class DomainsD4bAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $op;

    private User $user;

    /** @var list<array<string, mixed>> */
    private array $puts = [];

    /** @var list<array<string, mixed>> */
    private array $customerPosts = [];

    private int $authcodeReads = 0;

    /** @var array<string, mixed> */
    private array $record = [];

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
    }

    protected function tearDown(): void
    {
        OpenproviderRegistrar::$glueResolver = null;
        parent::tearDown();
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

    private bool $customerTimesOut = false;

    private function fakeOp(array $record = [], ?string $refuse = null, array $customer = []): void
    {
        $this->record = $record + ['id' => 30391502, 'domain' => ['name' => 'nixpal', 'extension' => 'com'], 'status' => 'ACT',
            'creation_date' => '2012-11-17 18:08:48', 'expiration_date' => '2027-11-17 18:08:48', 'is_locked' => true, 'is_lockable' => true,
            'is_private_whois_enabled' => false, 'is_private_whois_allowed' => true, 'dnssec' => 'unsigned', 'is_dnssec_enabled' => false,
            'autorenew' => 'default', 'owner_handle' => 'CP935231-GR', 'deleted_at' => '0000-00-00 00:00:00',
            'auth_code' => 'Leak-Me-Not-1', 'internal_auth_code' => 'Leak-Me-Not-2',
            'name_servers' => [['seq_nr' => 0, 'name' => 'ns1.nixpal.com', 'ip' => '192.0.2.6'], ['seq_nr' => 1, 'name' => 'ns2.nixpal.com', 'ip' => '']]];
        $customer = $customer + ['name' => ['first_name' => 'Christos', 'last_name' => 'P'], 'company_name' => 'MyIP', 'email' => 'owner@example.test'];
        Http::fake(function (Request $request) use ($refuse, $customer) {
            $url = $request->url();
            if (str_contains($url, '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if (str_contains($url, '/authcode')) {
                $this->authcodeReads++;

                return Http::response(['data' => ['auth_code' => 'Epp-Code-77']]);
            }
            if ($request->method() === 'POST' && str_ends_with((string) parse_url($url, PHP_URL_PATH), '/customers')) {
                $this->customerPosts[] = $request->data();
                if ($this->customerTimesOut) {
                    return Http::failedConnection('cURL error 28: Operation timed out');
                }

                return Http::response(['data' => ['handle' => 'NEW' . count($this->customerPosts) . '-GR']]);
            }
            if (str_contains($url, '/customers/')) {
                return Http::response(['data' => $customer]);
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

    private function swallow(callable $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    }

    // ── Findings (fail on the reviewed code) ─────────────────────────────────────────────────────────────────────

    /**
     * F1 (fixed in a70e22b, kept as a hold). docs/domains.md §6 D4b and the form's helper text: «glue from the DNS for a NS inside the domain». The update
     * PUT sends only the name (updateDomain does not use nameserverEntry): Openprovider answers «IP is not specified»
     * (the live finding of register), so in-bailiwick nameservers cannot be set from the admin.
     */
    public function test_holds_f1_a_nameserver_inside_the_domain_is_sent_with_its_glue_ip(): void
    {
        OpenproviderRegistrar::$glueResolver = fn (string $host) => $host === 'ns1.nixpal.com' ? '192.0.2.10' : null;
        $domain = $this->domain();
        $this->fakeOp();

        $this->swallow(fn () => app(DomainManagementService::class)->setNameservers($domain, ['ns1.nixpal.com', 'ns2.example.net'], null));

        $this->assertNotEmpty($this->puts, 'the change reached the registrar');
        $this->assertSame(['name' => 'ns1.nixpal.com', 'ip' => '192.0.2.10'], $this->puts[0]['name_servers'][0]);
    }

    /**
     * F2. A role with a MyCS contact but no handle (imported, adopted, or changed and not yet written) takes from the
     * sync whatever handle the registrar holds, a different person. From then on ensureHandles treats it as that
     * contact's handle: MyCS shows contact A as admin, WHOIS shows someone else, and staff cannot fix it (the form
     * already shows A, so setContacts sees no change). A later write of another role sends the stranger's handle.
     */
    public function test_f2_a_handle_from_the_sync_is_not_taken_as_another_contacts_handle(): void
    {
        $owner = $this->contact();
        $admin = $this->contact();
        $tech = $this->contact();
        $domain = $this->domain(['registrant_contact_id' => $owner->id, 'registrant_handle' => 'CP935231-GR', 'admin_contact_id' => $admin->id, 'admin_handle' => null]);
        $this->fakeOp(['admin_handle' => 'OTHER-PERSON-GR']);

        app(DomainSyncService::class)->sync($domain);
        // D4c: an unlinked admin with a contact is refused until staff link it; nothing reaches the registrar meanwhile.
        try {
            app(DomainManagementService::class)->setContacts($domain->fresh(), ['tech' => $tech->id], null);
        } catch (InvalidArgumentException) {
            $this->assertSame([], $this->puts);

            return;
        }

        $this->assertNotSame('OTHER-PERSON-GR', end($this->puts)['admin_handle'],
            'the admin contact chosen in MyCS never reaches the registrar: the role keeps another person\'s handle');
    }

    /**
     * F3. registered_at is new in plan() and goes through datePart(), which does not know Openprovider's zero date
     * (detailsFrom does): «0000-00-00 00:00:00» becomes a registered_at of «0000-00-00», which breaks the sync (or
     * stores a year -1).
     */
    public function test_f3_a_zero_creation_date_is_no_registration_date(): void
    {
        $domain = $this->domain(['registered_at' => null]);
        $this->fakeOp(['creation_date' => '0000-00-00 00:00:00']);

        $error = $this->swallow(fn () => app(DomainSyncService::class)->sync($domain));

        $this->assertNull($error, 'the sync failed: ' . $error?->getMessage());
        $this->assertNull($domain->fresh()->registered_at);
    }

    /**
     * F4 (fixed in a70e22b, kept as a hold). A refused contact change is put back with a query update (no audit), after the change itself was saved with
     * an audit: the domain's history says its tech contact became X, which never happened, and never says it went back.
     */
    public function test_holds_f4_a_refused_contact_change_leaves_no_false_history(): void
    {
        $owner = $this->contact();
        $other = $this->contact();
        $domain = $this->domain(['registrant_contact_id' => $owner->id, 'registrant_handle' => 'CP935231-GR']);
        $this->fakeOp([], refuse: 'Invalid handle');

        $this->assertNotNull($this->swallow(fn () => app(DomainManagementService::class)->setContacts($domain, ['tech' => $other->id], null)));

        $this->assertNull($domain->fresh()->tech_contact_id);
        $touching = $domain->audits()->orderBy('id')->get()->filter(fn ($audit) => array_key_exists('tech_contact_id', $audit->new_values ?? []));
        $this->assertTrue($touching->isEmpty() || $touching->last()->new_values['tech_contact_id'] === null,
            'the audit trail ends with tech_contact_id = ' . json_encode($touching->last()?->new_values['tech_contact_id']));
    }

    /**
     * F5. ensureHandles saves a new handle at once so «a later failure never creates a duplicate customer at the
     * registrar»; the undo of a refused change wipes it, so each retry creates another Openprovider customer.
     */
    public function test_f5_a_retry_after_a_refusal_does_not_create_another_customer_at_the_registrar(): void
    {
        $owner = $this->contact();
        $other = $this->contact();
        $domain = $this->domain(['registrant_contact_id' => $owner->id, 'registrant_handle' => 'CP935231-GR']);
        $this->fakeOp([], refuse: 'Temporary error');

        $this->swallow(fn () => app(DomainManagementService::class)->setContacts($domain, ['tech' => $other->id], null));
        $this->swallow(fn () => app(DomainManagementService::class)->setContacts($domain->fresh(), ['tech' => $other->id], null));

        $this->assertCount(1, $this->customerPosts, 'one contact, one customer at the registrar');
    }

    /**
     * F6. A registrant change at Openprovider is an owner change (a trade on many TLDs, which is charged and can need
     * the registrant's approval); the registrar's own flags on the domain (trade_allowed, modify_owner_allowed, shown in
     * «At the registrar») are not consulted, and the PUT is sent. «None of these charges» does not hold for it.
     */
    public function test_f6_a_registrant_change_the_registrar_says_is_not_allowed_is_not_sent(): void
    {
        $owner = $this->contact();
        $buyer = $this->contact();
        $domain = $this->domain(['registrant_contact_id' => $owner->id, 'registrant_handle' => 'CP935231-GR',
            'meta' => ['registrar' => ['trade_allowed' => false, 'modify_owner_allowed' => false]]]);
        $this->fakeOp(['trade_allowed' => false, 'modify_owner_allowed' => false]);

        $this->swallow(fn () => app(DomainManagementService::class)->setContacts($domain, ['registrant' => $buyer->id], null));

        $this->assertSame([], $this->puts, 'an owner change the registrar does not allow was sent');
        $this->assertSame([], $this->customerPosts);
    }

    /**
     * F7. D-22: «every call with a lock per domain». The sync (the cron, «Sync now») does not take the lock the writes
     * take: a sync that read before a write and applies after it puts the old nameservers/lock back here (audited as a
     * change) while a write is under way.
     */
    public function test_f7_the_sync_waits_for_a_write_under_way(): void
    {
        $domain = $this->domain(['transfer_lock' => false]);
        $this->fakeOp(['is_locked' => true]);
        $held = Cache::lock("domain-registrar:{$domain->id}", 120);
        $this->assertTrue($held->get());

        $this->swallow(fn () => app(DomainSyncService::class)->sync($domain));
        $held->release();

        $this->assertFalse($domain->fresh()->transfer_lock, 'the sync wrote the domain while a write held its lock');
    }

    /**
     * F8. A contact change whose registrar call times out (ConnectionException, here at the creation of the new
     * contact's customer) is not undone (a70e22b, «review 6b»): the role keeps the new contact with no handle, and the
     * re-sync then pairs it with the handle the registrar still holds, the previous person's, silently (no warning: the
     * pairing rule takes a registrar handle for «a role with a contact and no handle»). MyCS shows contact B as
     * registrant, WHOIS shows A, nothing tells staff, and the form already shows B, so staff cannot send it again.
     */
    public function test_f8_a_timed_out_contact_change_does_not_pair_the_new_contact_with_the_old_handle(): void
    {
        $owner = $this->contact();
        $buyer = $this->contact();
        $domain = $this->domain(['registrant_contact_id' => $owner->id, 'registrant_handle' => 'CP935231-GR']);
        $this->fakeOp();
        $this->customerTimesOut = true;

        $this->assertNotNull($this->swallow(fn () => app(DomainManagementService::class)->setContacts($domain, ['registrant' => $buyer->id], null)));

        $domain->refresh();
        $paired = $domain->registrant_contact_id === $buyer->id && $domain->registrant_handle === 'CP935231-GR';
        $this->assertFalse($paired && $domain->sync_error === null,
            'registrant = the new contact, handle = the old owner\'s at the registrar, and no warning');
    }

    // ── Holds (attacks that did not break it) ─────────────────────────────────────────────────────────────────────

    public function test_holds_actions_hidden_by_permission_cannot_be_called_through_livewire(): void
    {
        $domain = $this->domain(['notes' => 'before', 'transfer_lock' => true]);
        $this->fakeOp();
        $page = fn (array $permissions) => $this->livewireAs($this->staff($permissions))->test(ViewDomain::class, ['record' => $domain->getRouteKey()]);
        // Straight to the Livewire methods, as a forged request would, past the test helpers' visibility asserts.
        $forge = function ($component, string $action, array $data = []) {
            $this->swallow(fn () => $component->call('mountAction', $action));
            foreach ($data as $key => $value) {
                $this->swallow(fn () => $component->set("mountedActions.0.data.{$key}", $value));
            }
            $this->swallow(fn () => $component->call('callMountedAction'));
        };

        $forge($page(['admin.domains.viewAny', 'admin.domains.manage']), 'eppCode');
        $forge($page(['admin.domains.viewAny', 'admin.domains.epp']), 'nameservers', ['nameservers' => ['ns1.evil.test', 'ns2.evil.test']]);
        $forge($page(['admin.domains.viewAny', 'admin.domains.epp']), 'lock');
        $forge($page(['admin.domains.viewAny', 'admin.domains.update']), 'registrarContacts');
        $forge($page(['admin.domains.viewAny', 'admin.domains.manage', 'admin.domains.epp']), 'edit', ['auto_renew' => false, 'notes' => 'after']);
        $page(['admin.domains.viewAny', 'admin.domains.epp']); // the page alone reads no code

        $this->assertSame([0, [], 0], [$this->authcodeReads, $this->puts, DomainRegistrarLog::query()->count()]);
        $this->assertSame(['before', true], [$domain->fresh()->notes, $domain->fresh()->transfer_lock]);
    }

    public function test_holds_a_domain_on_a_manual_or_inactive_connection_or_ended_is_not_written(): void
    {
        $this->fakeOp();
        $manual = DomainRegistrarConnection::query()->create(['registrar' => 'manual', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
        $off = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP off', 'is_active' => false, 'mode' => 'production']);
        $domains = [
            $this->domain(['registrar_connection_id' => $manual->id]),
            $this->domain(['sld' => 'b', 'fqdn' => 'b.com', 'registrar_connection_id' => $off->id]),
            $this->domain(['sld' => 'c', 'fqdn' => 'c.com', 'status' => DomainStatus::Cancelled]),
            $this->domain(['sld' => 'd', 'fqdn' => 'd.com', 'status' => DomainStatus::TransferredAway]),
        ];
        foreach ($domains as $domain) {
            $this->assertNotNull($this->swallow(fn () => app(DomainManagementService::class)->setNameservers($domain, ['ns1.a.net', 'ns2.a.net'], null)));
            $this->assertNotNull($this->swallow(fn () => app(DomainManagementService::class)->eppCode($domain, null)));
        }
        $this->assertSame([[], 0], [$this->puts, $this->authcodeReads]);
    }

    public function test_holds_nameserver_input_is_normalised_or_refused(): void
    {
        $domain = $this->domain();
        $this->fakeOp();
        $service = app(DomainManagementService::class);

        $service->setNameservers($domain, ['NS1.Example.NET.', 'ns1.example.net', ' ns2.παράδειγμα.gr '], null);
        $this->assertSame(['ns1.example.net', Domain::normalise('ns2.παράδειγμα.gr')], array_column($this->puts[0]['name_servers'], 'name'));

        foreach ([['ns1.a.net', '192.0.2.1'], ['ns1.a.net', "ns2.a.net\nns3.b.net"], ['ns1.a.net', 'ns2.a.net;id'], ['ns1.a.net', 'localhost'],
            ['ns1.a.net', 'ns1.a.net.'], ['ns1.a.net', ''], ['-x.a.net', 'ns2.a.net']] as $hosts) {
            $this->assertNotNull($this->swallow(fn () => $service->setNameservers($domain->fresh(), $hosts, null)), json_encode($hosts));
        }
        $this->assertCount(1, $this->puts);
    }

    public function test_holds_the_epp_code_reaches_no_log_audit_or_debug_log(): void
    {
        config(['settings.debug' => true]);
        $domain = $this->domain();
        $this->fakeOp();
        $staff = $this->staff(['admin.domains.viewAny', 'admin.domains.epp']);

        $this->livewireAs($staff)->test(ViewDomain::class, ['record' => $domain->getRouteKey()])->callAction('eppCode');
        app(DomainSyncService::class)->sync($domain);

        $this->assertSame(1, $this->authcodeReads);
        $everything = '';
        foreach (['domain_registrar_logs', 'domains', 'audits', 'debug_logs', 'notifications'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $everything .= json_encode(DB::table($table)->get());
            }
        }
        foreach (['Epp-Code-77', 'Leak-Me-Not'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
    }

    public function test_holds_another_accounts_contact_cannot_be_chosen_through_livewire(): void
    {
        $owner = $this->contact();
        $stranger = Contact::factory()->create(['client_id' => User::factory()->create()->client()->id, 'country' => 'GR']);
        $domain = $this->domain(['registrant_contact_id' => $owner->id, 'registrant_handle' => 'CP935231-GR']);
        $this->fakeOp();

        $this->livewireAs($this->staff(['admin.domains.viewAny', 'admin.domains.manage']))->test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->callAction('contacts', ['registrant' => $stranger->id, 'admin' => $stranger->id, 'tech' => null, 'billing' => null]);

        $this->assertSame([[], []], [$this->puts, $this->customerPosts]);
        $this->assertSame([$owner->id, null], [$domain->fresh()->registrant_contact_id, $domain->fresh()->admin_contact_id]);
    }

    public function test_holds_registrar_supplied_text_is_escaped(): void
    {
        $xss = '<img src=x onerror=alert(1)>';
        $domain = $this->domain(['registrant_handle' => 'CP935231-GR']);
        $this->fakeOp(['owner_company_name' => $xss, 'dnssec' => $xss], customer: ['company_name' => $xss, 'name' => ['first_name' => $xss, 'last_name' => '']]);
        app(DomainSyncService::class)->sync($domain);

        $this->livewireAs($this->staff(['admin.domains.viewAny', 'admin.domains.manage']))->test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->assertDontSee($xss, false)->assertSee(e($xss), false)
            ->mountAction('registrarContacts')->assertDontSee($xss, false);
    }

    public function test_holds_the_tld_linked_is_the_domains_own(): void
    {
        DomainTld::query()->create(['tld' => 'gr', 'is_active' => true]);
        $comGr = DomainTld::query()->create(['tld' => 'com.gr', 'is_active' => true]);
        DomainTld::query()->create(['tld' => 'com', 'is_active' => true]);
        $domain = $this->domain(['sld' => 'nixpal', 'tld' => 'com.gr', 'fqdn' => 'nixpal.com.gr', 'domain_tld_id' => null]);
        $this->fakeOp(['domain' => ['name' => 'nixpal', 'extension' => 'com.gr']]);

        app(DomainSyncService::class)->sync($domain);

        $this->assertSame($comGr->id, $domain->fresh()->domain_tld_id);
    }

    public function test_holds_the_registrant_cannot_be_removed_and_a_concurrent_write_is_refused(): void
    {
        $owner = $this->contact();
        $domain = $this->domain(['registrant_contact_id' => $owner->id, 'registrant_handle' => 'CP935231-GR']);
        $this->fakeOp();

        $this->assertInstanceOf(InvalidArgumentException::class, $this->swallow(fn () => app(DomainManagementService::class)->setContacts($domain, ['registrant' => null, 'admin' => $owner->id], null)));
        $held = Cache::lock("domain-registrar:{$domain->id}", 120);
        $held->get();
        $this->assertNotNull($this->swallow(fn () => app(DomainManagementService::class)->eppCode($domain, null)));
        $held->release();
        $this->assertSame([[], 0], [$this->puts, $this->authcodeReads]);
    }
}
