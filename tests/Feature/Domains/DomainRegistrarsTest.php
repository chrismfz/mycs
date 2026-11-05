<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainRegistrarConnectionResource;
use App\Admin\Resources\DomainRegistrarConnectionResource\Pages\CreateDomainRegistrarConnection;
use App\Admin\Resources\DomainRegistrarConnectionResource\Pages\EditDomainRegistrarConnection;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Domains D2 (docs/domains.md D-21): registrar connections in the admin, their credentials encrypted and never shown
 * again, «Test Connection» that only logs in, and the Openprovider driver's contact roles (D-3).
 */
class DomainRegistrarsTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX = 'http://api.sandbox.openprovider.nl:8480';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function staff(array $permissions = ['*']): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    public function test_staff_add_a_connection_and_its_password_is_stored_encrypted_and_never_shown(): void
    {
        $this->livewireAs($this->staff())->test(CreateDomainRegistrarConnection::class)
            ->fillForm(['registrar' => 'openprovider', 'label' => 'Openprovider', 'mode' => 'sandbox', 'is_active' => true,
                'credentials' => ['username' => 'reseller-user', 'password' => 'Op-Secret-Pa55']])
            ->call('create')->assertHasNoFormErrors();

        $connection = DomainRegistrarConnection::query()->sole();
        $raw = DB::table('settings')->where('settingable_id', $connection->id)->where('settingable_type', $connection->getMorphClass())->pluck('value', 'key');
        $this->assertSame('reseller-user', $raw['username']);
        $this->assertNotSame('Op-Secret-Pa55', $raw['password']);

        $page = $this->livewireAs($this->staff())->test(EditDomainRegistrarConnection::class, ['record' => $connection->getRouteKey()])
            ->assertSchemaStateSet(['credentials.username' => 'reseller-user', 'credentials.password' => null]);
        $this->assertStringNotContainsString('Op-Secret-Pa55', $page->html());

        $page->fillForm(['label' => 'OP live', 'credentials' => ['username' => 'other-user', 'password' => '']])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['username' => 'other-user', 'password' => 'Op-Secret-Pa55'], $connection->fresh()->credentials(), 'an empty secret keeps the stored one');
        $this->assertSame('OP live', $connection->fresh()->label);
    }

    public function test_test_connection_logs_in_on_the_sandbox_and_changes_nothing(): void
    {
        $connection = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'sandbox']);
        $connection->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        Http::fake([self::SANDBOX . '/v1/auth/login' => Http::sequence()
            ->push(['data' => ['token' => 'tok']])->push(['desc' => 'Authentication failed'], 401)]);

        $this->livewireAs($this->staff())->test(EditDomainRegistrarConnection::class, ['record' => $connection->getRouteKey()])
            ->callAction('test')->assertNotified('Connected.');

        Http::assertSentCount(1);
        Cache::flush(); // the token
        $this->livewireAs($this->staff())->test(EditDomainRegistrarConnection::class, ['record' => $connection->getRouteKey()])
            ->callAction('test')->assertNotified('The registrar refused the login or could not be reached.');
        $this->assertSame(0, DB::table('domain_registrar_logs')->count(), 'a test leaves no history that would keep the connection');
    }

    public function test_only_production_reaches_the_live_registrar_and_credentials_never_dump(): void
    {
        $connection = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'off']);
        $connection->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $factory = app(DomainRegistrarFactory::class);

        $this->assertTrue($factory->credentialsFor($connection)->sandbox);
        $connection->update(['mode' => 'production']);
        $credentials = $factory->credentialsFor($connection->fresh());
        $this->assertFalse($credentials->sandbox);
        $this->assertStringNotContainsString('Op-Secret-Pa55', print_r($credentials, true));
        $this->assertStringNotContainsString('Op-Secret-Pa55', var_export(get_object_vars((object) $credentials->__debugInfo()), true));
    }

    public function test_a_connection_in_use_is_kept_with_a_message_and_staff_need_the_permission(): void
    {
        $connection = DomainRegistrarConnection::query()->create(['registrar' => 'manual', 'label' => 'CNIC (manual)', 'is_active' => true, 'mode' => 'production']);
        Domain::factory()->create(['client_id' => User::factory()->create()->client()->id, 'registrar_connection_id' => $connection->id]);

        $this->livewireAs($this->staff())->test(EditDomainRegistrarConnection::class, ['record' => $connection->getRouteKey()])
            ->callAction('delete')->assertNotified(__('domains.connection_in_use'));
        $this->assertNotNull($connection->fresh());

        $this->actingAs($this->staff(['admin.domains.viewAny']))->get(DomainRegistrarConnectionResource::getUrl('index'))->assertForbidden();
        $this->actingAs($this->staff(['admin.domain_registrars.viewAny']))->get(DomainRegistrarConnectionResource::getUrl('index'))->assertOk()->assertSee('CNIC (manual)');
        $this->actingAs($this->staff(['admin.domain_registrars.viewAny']))->get(DomainRegistrarConnectionResource::getUrl('edit', ['record' => $connection]))->assertForbidden();
    }

    public function test_openprovider_creates_one_customer_per_contact_and_keeps_the_handles_on_the_domain(): void
    {
        $user = User::factory()->create();
        $owner = $user->client()->primaryContact;
        $owner->update(['first_name' => 'Maria', 'last_name' => 'Owner', 'phone' => '+30 2310 123456', 'address1' => 'Egnatia 12', 'city' => 'Thessaloniki', 'postcode' => '54630', 'country' => 'GR']);
        $tech = (new Contact)->forceFill(['client_id' => $user->client()->id, 'first_name' => 'Tech', 'last_name' => 'Person', 'email' => 'tech@example.test', 'country' => 'GR']);
        $tech->save();
        $domain = Domain::factory()->create(['client_id' => $user->client()->id, 'sld' => 'shop', 'fqdn' => 'shop.test', 'status' => 'pending_register',
            'registrant_contact_id' => $owner->id, 'admin_contact_id' => $owner->id, 'tech_contact_id' => $tech->id, 'billing_handle' => 'EX111-GR']);
        $domain->nameservers()->create(['host' => 'ns1.example.net', 'sort' => 0]);
        $created = 0;
        Http::fake([
            self::SANDBOX . '/v1/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1/customers' => function () use (&$created) {
                $created++;

                return Http::response(['data' => ['handle' => 'NEW' . $created . '-GR']]);
            },
            self::SANDBOX . '/v1/domains' => Http::response(['code' => 0, 'data' => ['id' => 777, 'status' => 'REQ']]),
            self::SANDBOX . '/v1/domains/777' => Http::response(['data' => ['id' => 777, 'status' => 'REQ']]),
        ]);

        (new OpenproviderRegistrar)->register($domain->fresh(), 1, new DomainRegistrarCredentials(['username' => 'u', 'password' => 'p'], sandbox: true));

        $this->assertSame(2, $created, 'owner (two roles) once, tech once; billing kept its handle');
        $domain->refresh();
        $this->assertSame(['NEW1-GR', 'NEW1-GR', 'NEW2-GR', 'EX111-GR'], [$domain->registrant_handle, $domain->admin_handle, $domain->tech_handle, $domain->billing_handle]);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/domains') && $request['autorenew'] === 'off'
            && $request['owner_handle'] === 'NEW1-GR' && $request['tech_handle'] === 'NEW2-GR' && $request['billing_handle'] === 'EX111-GR');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/customers') && isset($request['phone']) && $request['phone']['area_code'] !== '');
    }

    /** WG-23: a customer made at Openprovider from Greek contact data gets it in Latin letters (ELOT 743). */
    public function test_greek_contact_data_goes_to_openprovider_in_latin_letters(): void
    {
        $user = User::factory()->create();
        $owner = $user->client()->primaryContact;
        $owner->update(['first_name' => 'Χαράλαμπος', 'last_name' => 'Ψαρράς', 'company_name' => 'ΕΥΡΩΠΑΪΚΗ Ο.Ε.', 'phone' => '+30 2310 123456',
            'address1' => 'Εγνατίας 12', 'address2' => 'Όροφος 2', 'city' => 'Θεσσαλονίκη', 'state' => 'Κεντρική Μακεδονία', 'postcode' => '54630', 'country' => 'GR']);
        $domain = Domain::factory()->create(['client_id' => $user->client()->id, 'sld' => 'shop', 'fqdn' => 'shop.test', 'status' => 'pending_register',
            'registrant_contact_id' => $owner->id, 'admin_contact_id' => $owner->id, 'tech_contact_id' => $owner->id, 'billing_handle' => 'EX111-GR']);
        $domain->nameservers()->create(['host' => 'ns1.example.net', 'sort' => 0]);
        Http::fake([
            self::SANDBOX . '/v1/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1/customers' => Http::response(['data' => ['handle' => 'NEW1-GR']]),
            self::SANDBOX . '/v1/domains' => Http::response(['code' => 0, 'data' => ['id' => 777, 'status' => 'REQ']]),
            self::SANDBOX . '/v1/domains/777' => Http::response(['data' => ['id' => 777, 'status' => 'REQ']]),
        ]);

        (new OpenproviderRegistrar)->register($domain->fresh(), 1, new DomainRegistrarCredentials(['username' => 'u', 'password' => 'p'], sandbox: true));

        Http::assertSent(function ($request) {
            if (!str_ends_with($request->url(), '/v1/customers')) {
                return false;
            }
            $this->assertSame(['first_name' => 'Charalampos', 'last_name' => 'Psarras'], $request['name']);
            $this->assertSame('EVROPAIKI O.E.', $request['company_name']);
            $this->assertSame(['street' => 'Egnatias', 'number' => '12', 'suffix' => 'Orofos 2', 'city' => 'Thessaloniki', 'state' => 'Kentriki Makedonia'],
                array_intersect_key($request['address'], array_flip(['street', 'number', 'suffix', 'city', 'state'])));
            $this->assertDoesNotMatchRegularExpression('/[^\x20-\x7E]/', json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'ASCII only (review of #669)');

            return true;
        });
        $this->assertSame('Θεσσαλονίκη', $owner->fresh()->city, 'Greek here, as the customer wrote it');

        // An update of the customer there (review of #669): its address in Latin letters, its email and postcode as they are.
        $owner->update(['email' => 'μαρία@example.test', 'city' => 'Ηράκλειο', 'postcode' => '71202']);
        Http::fake([self::SANDBOX . '/v1/auth/login' => Http::response(['data' => ['token' => 'tok']]), self::SANDBOX . '/v1/customers/NEW1-GR' => Http::response(['code' => 0])]);
        (new OpenproviderRegistrar)->updateContact('NEW1-GR', $owner->fresh(), new DomainRegistrarCredentials(['username' => 'u', 'password' => 'p'], sandbox: true));
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/v1/customers/NEW1-GR')
            && $request['address']['city'] === 'Irakleio' && $request['address']['zipcode'] === '71202' && $request['email'] === 'μαρία@example.test');
    }

    /** WG-32: a domain with ID protection keeps it at Openprovider (free there), only where its TLD offers it. */
    public function test_id_protection_is_sent_on_a_registration_where_its_tld_offers_it(): void
    {
        $user = User::factory()->create();
        $owner = $user->client()->primaryContact;
        $owner->update(['first_name' => 'Maria', 'last_name' => 'Owner', 'phone' => '+30 2310 123456', 'address1' => 'Egnatia 12', 'city' => 'Thessaloniki', 'postcode' => '54630', 'country' => 'GR']);
        Http::fake([
            self::SANDBOX . '/v1/auth/login' => Http::response(['data' => ['token' => 'tok']]),
            self::SANDBOX . '/v1/customers' => Http::response(['data' => ['handle' => 'NEW1-GR']]),
            self::SANDBOX . '/v1/domains' => Http::response(['code' => 0, 'data' => ['id' => 777, 'status' => 'REQ']]),
            self::SANDBOX . '/v1/domains/777' => Http::response(['data' => ['id' => 777, 'status' => 'REQ']]),
        ]);
        $sent = [];
        foreach (['com' => true, 'eu' => false] as $tld => $offered) {
            $model = DomainTld::query()->firstOrCreate(['tld' => $tld], ['is_active' => true]);
            $model->forceFill(['id_protection' => $offered])->save();
            $domain = Domain::factory()->create(['client_id' => $user->client()->id, 'sld' => 'shop', 'tld' => $tld, 'fqdn' => "shop.{$tld}", 'status' => 'pending_register',
                'domain_tld_id' => $model->id, 'id_protection' => true, 'registrant_contact_id' => $owner->id, 'admin_contact_id' => $owner->id, 'tech_contact_id' => $owner->id]);
            $domain->nameservers()->create(['host' => 'ns1.example.net', 'sort' => 0]);
            (new OpenproviderRegistrar)->register($domain->fresh(), 1, new DomainRegistrarCredentials(['username' => 'u', 'password' => 'p'], sandbox: true));
        }

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/domains') && $request['domain']['extension'] === 'com' && ($request['is_private_whois_enabled'] ?? null) === true);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/domains') && $request['domain']['extension'] === 'eu' && !isset($request['is_private_whois_enabled']));
    }
}
