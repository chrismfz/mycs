<?php

namespace Tests\Feature;

use App\Admin\Pages\WhoisLookup;
use App\Admin\Resources\DomainResource;
use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\Lookup\DomainLookup;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** WHOIS for staff (WHMCS «Utilities → WHOIS Lookup»): the registry's answer, the domain here when ours, its own permission. */
class AdminWhoisLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->app->instance(DomainLookup::class, new class extends DomainLookup
        {
            public array $asked = [];

            public function __construct() {}

            public function whois(string $fqdn): array
            {
                $this->asked[] = $fqdn;

                return ['status' => 'registered', 'record' => ['registrar' => 'Example Registrar', 'nameservers' => ['ns1.example.net']], 'text' => 'Domain Name: ' . $fqdn];
            }
        });
    }

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
    }

    public function test_staff_look_a_name_up_and_see_it_is_ours(): void
    {
        $domain = Domain::factory()->create(['client_id' => User::factory()->create()->client()->id, 'sld' => 'acme', 'tld' => 'com', 'fqdn' => 'acme.com',
            'status' => DomainStatus::Active, 'expires_at' => '2027-11-17', 'currency_code' => 'EUR', 'years' => 1]);

        $this->livewireAs($this->staff(['admin.domains.viewAny', 'admin.domains.view']))->test(WhoisLookup::class)
            ->set('query', 'https://www.ACME.com/page')->call('lookup')
            ->assertSet('fqdn', 'acme.com')->assertSee('Example Registrar')->assertSee('ns1.example.net')
            ->assertSeeHtml(DomainResource::getUrl('view', ['record' => $domain]));
        $this->assertSame(['acme.com'], app(DomainLookup::class)->asked);
    }

    public function test_a_bad_name_is_not_asked(): void
    {
        $this->livewireAs($this->staff(['admin.domains.viewAny']))->test(WhoisLookup::class)
            ->set('query', 'not a name')->call('lookup')->assertSet('answer', null)->assertSee(__('domains.shop.not_a_full_name'));
        $this->assertSame([], app(DomainLookup::class)->asked);
    }

    public function test_only_staff_who_may_see_domains(): void
    {
        $this->actingAs($this->staff([]), 'staff')->get(WhoisLookup::getUrl())->assertForbidden();
        // Code review 1: not even asked by its link.
        $this->actingAs($this->staff([]), 'staff')->get(WhoisLookup::getUrl(['domain' => 'example.org']))->assertForbidden();
        $this->assertSame([], app(DomainLookup::class)->asked);
        $this->actingAs($this->staff(['admin.domains.viewAny']), 'staff')->get(WhoisLookup::getUrl(['domain' => 'example.org']))->assertOk()->assertSee('Example Registrar');
    }
}
