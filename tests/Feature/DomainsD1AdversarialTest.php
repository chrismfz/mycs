<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\CurrencyResource\Pages\EditCurrency;
use App\Admin\Resources\DomainResource;
use App\Enums\DomainStatus;
use App\Helpers\TemplateRenderer;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Admin\ClientSummary;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * Adversarial review of PR #157 (domains D1). A1–A4 failed on ab563cb and are fixed; the rest guard what holds.
 */
class DomainsD1AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = User::factory()->create();
    }

    private function staff(array $permissions, bool $active = true): Staff
    {
        return Staff::factory()->create(['active' => $active, 'role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function domain(array $attributes = [], ?User $user = null): Domain
    {
        return Domain::factory()->create(['client_id' => ($user ?? $this->user)->client()->id] + $attributes);
    }

    private function connectionWithSecret(): DomainRegistrarConnection
    {
        $connection = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'sandbox']);
        $connection->storeCredentials(['username' => 'reseller-user', 'password' => 'Op-Secret-Pa55'], ['password']);

        return $connection;
    }

    // ---- findings -------------------------------------------------------------------------------------------------

    /** A1: EditCurrency deletes TLD prices in ->after(), but domain_tld_prices.currency_code is restrictOnDelete. */
    public function test_an_unused_currency_with_tld_prices_can_be_deleted(): void
    {
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        $tld = DomainTld::query()->create(['tld' => 'com']);
        DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'register', 'years' => 1, 'currency_code' => 'EUR', 'price' => '10.00']);

        $this->livewireAs($this->staff(['*']))->test(EditCurrency::class, ['record' => 'EUR'])->callAction('delete');

        $this->assertNull(Currency::find('EUR'), 'the comment says TLD prices go with the currency');
        $this->assertSame(0, DomainTldPrice::query()->where('currency_code', 'EUR')->count());
    }

    /** A2: the one-live-row rule lives in a model event; a query-builder restore makes two live rows. */
    public function test_a_query_builder_restore_cannot_make_two_live_rows(): void
    {
        $old = $this->domain(['sld' => 'again', 'fqdn' => 'again.test']);
        $old->update(['status' => DomainStatus::TransferredAway]);
        $this->domain(['sld' => 'again', 'fqdn' => 'again.test']);

        try {
            Domain::query()->whereKey($old->id)->update(['status' => DomainStatus::Active->value]);
        } catch (UniqueConstraintViolationException) {
        }

        $live = Domain::query()->where('fqdn', 'again.test')->whereNotIn('status', DomainStatus::terminalValues())->count();
        $this->assertSame(1, $live, 'two live rows for one name');
    }

    /** A2b: an ended domain by query builder keeps its name blocked (live_fqdn not cleared). */
    public function test_a_query_builder_end_frees_the_name(): void
    {
        $old = $this->domain(['sld' => 'freed', 'fqdn' => 'freed.test']);
        Domain::query()->whereKey($old->id)->update(['status' => DomainStatus::Cancelled->value]);

        $this->domain(['sld' => 'freed', 'fqdn' => 'freed.test']);
        $this->assertSame(2, Domain::query()->where('fqdn', 'freed.test')->count());
    }

    /** A3: the fqdn is not normalised: a trailing dot is a second live row for the same name. */
    public function test_a_trailing_dot_is_the_same_name(): void
    {
        $this->domain(['sld' => 'dot', 'fqdn' => 'dot.test']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->domain(['sld' => 'dot', 'fqdn' => 'dot.test.']);
    }

    /** A3b: case. On MariaDB (utf8mb4_*_ci) the unique index catches it; on SQLite it would not (no normalisation). */
    public function test_a_different_case_is_the_same_name(): void
    {
        $this->domain(['sld' => 'case', 'fqdn' => 'case.test']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->domain(['sld' => 'CASE', 'fqdn' => 'CASE.test']);
    }

    /** A4: a template reaches other customers' domains through the TLD (or the connection) relations. */
    public function test_a_template_does_not_reach_other_customers_domains(): void
    {
        $tld = DomainTld::query()->create(['tld' => 'test']);
        $mine = $this->domain(['domain_tld_id' => $tld->id]);
        $theirs = $this->domain(['domain_tld_id' => $tld->id], User::factory()->create());

        $out = TemplateRenderer::render('{% for d in domain.domainTld.domains %}{{ d.fqdn }} {% endfor %}', ['domain' => $mine->fresh()], false);

        $this->assertStringNotContainsString($theirs->fqdn, $out);
    }

    // ---- what holds (regression guards) ----------------------------------------------------------------------------

    public function test_credentials_never_reach_config_json_or_templates(): void
    {
        $connection = $this->connectionWithSecret();
        $tld = DomainTld::query()->create(['tld' => 'test', 'registrar_connection_id' => $connection->id]);
        $domain = $this->domain(['domain_tld_id' => $tld->id, 'registrar_connection_id' => $connection->id]);

        $this->assertNull(config('settings.password'));
        $this->assertNull(config('settings.username'));

        $loaded = Domain::query()->with(['registrarConnection.settings', 'domainTld.registrarConnection.settings', 'client'])->find($domain->id);
        $json = $loaded->toJson() . $connection->load('settings')->toJson() . $tld->load('registrarConnection.settings')->toJson();
        $this->assertStringNotContainsString('Op-Secret-Pa55', $json);
        $this->assertStringNotContainsString('reseller-user', $json);

        foreach (['{{ domain.domainTld.registrarConnection.settings }}', '{% for s in domain.domainTld.registrarConnection.settings %}{{ s.value }}{% endfor %}',
            '{{ domain.domainTld.registrarConnection }}', '{{ domain.domainTld }}'] as $template) {
            $out = TemplateRenderer::render($template, ['domain' => $domain->fresh()], false);
            $this->assertStringNotContainsString('Op-Secret-Pa55', $out, $template);
        }
    }

    public function test_the_audit_of_a_credential_is_redacted(): void
    {
        config(['audit.console' => true]);
        $connection = $this->connectionWithSecret();
        $connection->storeCredentials(['password' => 'Op-Secret-Changed'], ['password']);

        $audits = Audit::query()->where('auditable_type', (new Setting)->getMorphClass())->get();
        $this->assertNotEmpty($audits);
        $dump = $audits->map(fn (Audit $a) => json_encode($a->old_values) . json_encode($a->new_values))->implode('');
        $this->assertStringNotContainsString('Op-Secret-Pa55', $dump);
        $this->assertStringNotContainsString('Op-Secret-Changed', $dump);
    }

    public function test_an_ended_domain_restored_by_the_model_while_another_is_live_is_refused(): void
    {
        $old = $this->domain(['sld' => 'back', 'fqdn' => 'back.test']);
        $old->update(['status' => DomainStatus::Deleted]);
        $this->domain(['sld' => 'back', 'fqdn' => 'back.test']);

        $this->expectException(UniqueConstraintViolationException::class);
        $old->update(['status' => DomainStatus::Active]);
    }

    public function test_inactive_staff_and_staff_without_the_permission_do_not_see_domains(): void
    {
        $domain = $this->domain();

        $this->actingAs($this->staff(['admin.domains.viewAny'], false))->get(DomainResource::getUrl('view', ['record' => $domain]))->assertForbidden();
        $this->actingAs($this->staff(['admin.clients.viewAny']))->get(DomainResource::getUrl('view', ['record' => $domain]))->assertForbidden();

        $this->actingAs($this->staff(['admin.clients.viewAny']), 'staff');
        $this->assertFalse(DomainResource::canGloballySearch());
    }

    public function test_the_summary_lists_live_domains_first_and_survives_a_null_expiry(): void
    {
        $ended = $this->domain(['status' => DomainStatus::Cancelled, 'expires_at' => now()->subYears(2)->toDateString()]);
        $pending = $this->domain(['status' => DomainStatus::PendingRegister, 'expires_at' => null, 'registered_at' => null]);
        $active = $this->domain(['expires_at' => now()->addMonth()->toDateString()]);

        $rows = ClientSummary::for($this->user->client(), $this->staff(['*']))['domains']['rows'];

        $this->assertSame([$pending->id, $active->id, $ended->id], array_column($rows, 'id'));
        $this->actingAs($this->staff(['*']))->get(ClientResource::getUrl('view', ['record' => $this->user->client()]))
            ->assertOk()->assertSee($pending->fqdn);
    }
}
