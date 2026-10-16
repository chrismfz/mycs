<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\DomainResource;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Helpers\TemplateRenderer;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Policies\DomainPolicy;
use App\Services\Admin\ClientSummary;
use App\Support\Redact;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Domains, D1 (docs/domains.md): the model and the read-only admin. A domain is its own record on an account, its contact
 * roles the account's contacts (D-3); a registrar connection keeps its secrets as encrypted Settings (D-21); nothing
 * with history is deleted (D-18).
 */
class DomainsModelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Domains']);
    }

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create(['client_id' => $this->user->client()->id] + $attributes);
    }

    private function connection(string $label = 'Openprovider'): DomainRegistrarConnection
    {
        return DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => $label, 'is_active' => true, 'mode' => 'sandbox']);
    }

    public function test_a_domain_is_served_by_its_own_connection_else_its_tlds(): void
    {
        $op = $this->connection();
        $manual = DomainRegistrarConnection::query()->create(['registrar' => DomainRegistrarConnection::MANUAL, 'label' => 'CNIC (manual)', 'is_active' => true, 'mode' => 'production']);
        $tld = DomainTld::query()->create(['tld' => 'com', 'registrar_connection_id' => $op->id, 'grace_days' => 40, 'redemption_days' => 30]);

        $plain = $this->domain(['domain_tld_id' => $tld->id]);
        $cnic = $this->domain(['domain_tld_id' => $tld->id, 'registrar_connection_id' => $manual->id, 'redemption_days_override' => 5]);

        $this->assertSame([$op->id, $manual->id], [$plain->effectiveRegistrarConnection()?->id, $cnic->effectiveRegistrarConnection()?->id]);
        $this->assertSame([40, 30, 5], [$plain->graceDays(), $plain->redemptionDays(), $cnic->redemptionDays()]);
        $this->assertFalse($manual->isUsable(), 'the manual connection calls no API');
        $this->assertSame(DomainStatus::Active, $plain->fresh()->status);
        $this->assertSame([$plain->id, $cnic->id], $this->user->client()->domains()->orderBy('id')->pluck('id')->all());
    }

    public function test_a_price_is_closed_free_or_set_per_operation_years_and_currency(): void
    {
        $tld = DomainTld::query()->create(['tld' => 'gr']);
        DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'register', 'years' => 2, 'currency_code' => 'USD', 'price' => '19.00']);
        DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'transfer', 'years' => 1, 'currency_code' => 'USD', 'price' => '0.00']);
        DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 10, 'currency_code' => 'USD', 'price' => null, 'cost' => '80.00', 'cost_currency' => 'EUR']);
        $tld->load('prices');

        $this->assertSame('19.00', $tld->priceFor('register', 2, 'USD'));
        $this->assertSame('0.00', $tld->priceFor('transfer', 1, 'USD'), 'free and offered');
        $this->assertNull($tld->priceFor('renew', 10, 'USD'), 'a cost without a price is not offered');
        $this->assertNull($tld->priceFor('register', 1, 'USD'), 'no row: not offered');
    }

    public function test_registrar_credentials_are_encrypted_settings_scrubbed_from_logs(): void
    {
        $connection = $this->connection();
        $connection->storeCredentials(['username' => 'reseller-user', 'password' => 'Op-Secret-Pa55'], ['password']);

        $raw = DB::table('settings')->where('settingable_type', $connection->getMorphClass())->where('settingable_id', $connection->id)->pluck('value', 'key');
        $this->assertSame('reseller-user', $raw['username']);
        $this->assertNotSame('Op-Secret-Pa55', $raw['password']);
        $this->assertSame(['username' => 'reseller-user', 'password' => 'Op-Secret-Pa55'], $connection->credentials());
        $this->assertStringNotContainsString('Op-Secret-Pa55', Redact::text('login failed with Op-Secret-Pa55'));

        $connection->storeCredentials(['username' => 'other-user', 'password' => ''], ['password']); // a form never shows the secret
        $this->assertSame(['username' => 'other-user', 'password' => 'Op-Secret-Pa55'], $connection->fresh()->credentials());
    }

    public function test_what_has_history_is_not_deleted(): void
    {
        $connection = $this->connection();
        $this->domain(['registrar_connection_id' => $connection->id]);

        try {
            $connection->delete();
            $this->fail('a connection in use was deleted');
        } catch (DisplayException) {
        }
        try {
            $this->user->client()->delete();
            $this->fail('an account with domains was deleted');
        } catch (DisplayException) {
        }
        $this->assertSame(1, Domain::query()->count());
    }

    public function test_a_contact_that_holds_a_role_of_a_live_domain_is_not_archived(): void
    {
        $contact = (new Contact)->forceFill(['client_id' => $this->user->client()->id, 'first_name' => 'Tech', 'last_name' => 'Person', 'email' => 'tech@example.test']);
        $contact->save();
        $domain = $this->domain(['tech_contact_id' => $contact->id]);

        try {
            $contact->archive();
            $this->fail('archived while it is a domain\'s technical contact');
        } catch (DisplayException $e) {
            $this->assertStringContainsString($domain->fqdn, $e->getMessage());
        }

        $domain->update(['status' => DomainStatus::TransferredAway]);
        $contact->archive();
        $this->assertNotNull($contact->fresh()->archived_at);
    }

    public function test_staffs_notes_never_reach_a_template(): void
    {
        $domain = $this->domain(['notes' => 'Internal: friend of the house']);

        $this->assertSame($domain->fqdn . '|', TemplateRenderer::render('{{ domain.fqdn }}|{{ domain.notes }}', ['domain' => $domain], false));
    }

    public function test_an_ended_domains_name_may_come_back_but_only_once_live(): void
    {
        $old = $this->domain(['sld' => 'again', 'fqdn' => 'again.test']);
        try {
            $this->domain(['sld' => 'again', 'fqdn' => 'again.test']);
            $this->fail('two live rows for one name');
        } catch (UniqueConstraintViolationException) {
        }

        $old->update(['status' => DomainStatus::TransferredAway]);
        $new = $this->domain(['sld' => 'again', 'fqdn' => 'again.test']);

        $this->assertSame([null, 'again.test'], [$old->fresh()->live_fqdn, $new->fresh()->live_fqdn]);
        $this->assertSame(2, Domain::query()->where('fqdn', 'again.test')->count(), 'the ended one kept (D-18)');
    }

    public function test_neither_the_domain_nor_its_connection_leak_into_a_template(): void
    {
        $connection = $this->connection();
        $connection->storeCredentials(['username' => 'reseller-user', 'password' => 'Op-Secret-Pa55'], ['password']);
        $domain = $this->domain(['notes' => 'Internal: friend of the house', 'registrar_connection_id' => $connection->id, 'registrant_handle' => 'XX123-GR']);

        foreach (['{{ domain }}', '{{ domain.registrarConnection }}', '{{ domain.registrarConnection.settings }}', '{{ domain.registrant_handle }}'] as $template) {
            $out = TemplateRenderer::render($template, ['domain' => $domain->fresh()], false);
            foreach (['friend of the house', 'Op-Secret-Pa55', 'reseller-user', 'XX123-GR'] as $secret) {
                $this->assertStringNotContainsString($secret, $out, $template);
            }
        }
    }

    public function test_a_tld_with_domains_and_a_currency_they_bill_in_are_kept(): void
    {
        $tld = DomainTld::query()->create(['tld' => 'com']);
        $this->domain(['domain_tld_id' => $tld->id]);

        try {
            $tld->delete();
            $this->fail('a TLD with domains was deleted');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('.com', $e->getMessage());
        }
        $unused = DomainTld::query()->create(['tld' => 'net']);
        DomainTldPrice::query()->create(['domain_tld_id' => $unused->id, 'operation' => 'register', 'years' => 1, 'currency_code' => 'USD', 'price' => '10.00']);
        $unused->delete();
        $this->assertSame(0, DomainTldPrice::query()->where('domain_tld_id', $unused->id)->count(), 'its prices go with it');
    }

    public function test_a_connection_with_a_log_is_kept_and_one_deleted_takes_its_secrets(): void
    {
        $logged = $this->connection();
        DomainRegistrarLog::query()->create(['registrar_connection_id' => $logged->id, 'action' => 'ping', 'status' => 'ok']);
        try {
            $logged->delete();
            $this->fail('a connection with a log was deleted');
        } catch (DisplayException) {
        }

        $unused = $this->connection('Spare');
        $unused->storeCredentials(['password' => 'Spare-Secret-1'], ['password']);
        $unused->delete();
        $this->assertSame(0, DB::table('settings')->where('settingable_type', $unused->getMorphClass())->where('settingable_id', $unused->id)->count());
    }

    public function test_a_customer_sees_a_domain_only_with_the_domains_grant(): void
    {
        $domain = $this->domain();
        $policy = new DomainPolicy;

        $this->assertTrue($policy->view($this->user, $domain), 'the owner');
        $this->assertFalse($policy->view(User::factory()->create(), $domain), 'another customer');
        $this->assertFalse($policy->viewAny($this->user), 'no admin list for customers');
    }

    public function test_staff_see_domains_only_with_the_permission(): void
    {
        $domain = $this->domain();
        $client = $this->user->client();

        $this->actingAs($this->staff(['admin.domains.viewAny', 'admin.clients.viewAny']))->get(DomainResource::getUrl('index'))->assertOk()->assertSee($domain->fqdn);
        $this->actingAs($this->staff(['admin.domains.viewAny']))->get(DomainResource::getUrl('view', ['record' => $domain]))->assertOk()->assertSee($domain->fqdn);
        $this->actingAs($this->staff(['admin.clients.viewAny']))->get(DomainResource::getUrl('index'))->assertForbidden();

        $this->actingAs($this->staff(['admin.clients.viewAny', 'admin.domains.viewAny']))->get(ClientResource::getUrl('domains', ['record' => $client]))->assertOk()->assertSee($domain->fqdn);
        $this->actingAs($this->staff(['admin.clients.viewAny']))->get(ClientResource::getUrl('domains', ['record' => $client]))->assertForbidden();

        $this->actingAs($this->staff(['admin.clients.viewAny', 'admin.domains.viewAny']))->get(ClientResource::getUrl('view', ['record' => $client]))->assertSee($domain->fqdn);
        $this->actingAs($this->staff(['admin.clients.viewAny']))->get(ClientResource::getUrl('view', ['record' => $client]))->assertOk()->assertDontSee($domain->fqdn);
        $this->assertNull(ClientSummary::for($client, $this->staff(['admin.clients.viewAny']))['domains']);
    }
}
