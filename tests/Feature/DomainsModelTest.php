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
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Admin\ClientSummary;
use App\Support\Redact;
use Filament\Facades\Filament;
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
