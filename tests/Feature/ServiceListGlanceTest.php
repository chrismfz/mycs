<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Index;
use App\Models\License;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The portal's list of services (owner 2026-10-23): each with its IP (a license's valid IPs, else its dedicated IP) and
 * the fields its license reports (nixpal's «comments»), never staff's notes; searched by those too.
 */
class ServiceListGlanceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Service $licensed;

    private Service $hosting;

    protected function setUp(): void
    {
        parent::setUp();
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id, 'name' => 'ZCAgent']);
        foreach (['key_prefix' => ['ZCAgent-', false], 'key_length' => ['16', false], 'secret_key' => ['TestSecret', true], 'custom_fields' => ['comments', false]] as $key => [$value, $encrypted]) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $encrypted, 'type' => 'string',
                'settingable_id' => $product->product->id, 'settingable_type' => $product->product->getMorphClass()])->save();
        }
        $this->owner = User::factory()->create();
        $this->licensed = Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1, 'expires_at' => now()->addMonth(), 'server_id' => $server->id,
            'notes' => 'STAFF-ONLY note']);
        ExtensionHelper::createServer($this->licensed);
        License::query()->sole()->update(['valid_ip' => '198.51.100.7,198.51.100.8']);
        $this->licensed->properties()->create(['key' => 'comments', 'name' => 'comments', 'value' => 'saf.example.test']);
        $this->licensed->properties()->create(['key' => 'internal', 'name' => 'internal', 'value' => 'SECRET-VALUE']);

        $plain = $this->createProduct();
        $plain->product->update(['name' => 'Web Hosting']);
        $this->hosting = Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $plain->plan->id, 'product_id' => $plain->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 5, 'quantity' => 1, 'expires_at' => now()->addMonth(), 'dedicated_ip' => '203.0.113.9']);
    }

    public function test_each_service_shows_its_ip_and_reported_fields_never_staff_notes(): void
    {
        Livewire::actingAs($this->owner)->test(Index::class)
            ->assertSee('198.51.100.7, 198.51.100.8')->assertSee('saf.example.test')
            ->assertSee('203.0.113.9')
            ->assertDontSee('STAFF-ONLY note')->assertDontSee('SECRET-VALUE');
    }

    public function test_search_by_ip_or_comments_only_what_the_customer_sees(): void
    {
        Livewire::actingAs($this->owner)->test(Index::class)->set('search', 'saf.example')
            ->assertSee('ZCAgent')->assertDontSee('Web Hosting');
        Livewire::actingAs($this->owner)->test(Index::class)->set('search', '203.0.113')
            ->assertSee('Web Hosting')->assertDontSee('saf.example.test');
        Livewire::actingAs($this->owner)->test(Index::class)->set('search', 'SECRET-VALUE')
            ->assertSee(__('services.no_match', ['search' => 'SECRET-VALUE']));
        Livewire::actingAs($this->owner)->test(Index::class)->set('search', 'STAFF-ONLY')
            ->assertSee(__('services.no_match', ['search' => 'STAFF-ONLY']));
    }

    public function test_another_accounts_services_never_appear(): void
    {
        $other = User::factory()->create();

        Livewire::actingAs($other)->test(Index::class)->set('search', '198.51')->assertDontSee('198.51.100.7');
    }

    /** Code review 1: a value the list does not show (a hosting service's own «comments») is never found. */
    public function test_a_value_not_shown_is_never_found(): void
    {
        $this->hosting->properties()->create(['key' => 'comments', 'name' => 'comments', 'value' => 'HIDDEN-HOSTING-NOTE']);

        Livewire::actingAs($this->owner)->test(Index::class)->assertDontSee('HIDDEN-HOSTING-NOTE')
            ->set('search', 'HIDDEN-HOSTING')->assertSee(__('services.no_match', ['search' => 'HIDDEN-HOSTING']));
    }

    /** Code review 2, 3, 4: the customer's own label; the IP named for what it is; «_» found on every database. */
    public function test_label_ip_name_and_underscores(): void
    {
        $this->hosting->forceFill(['label' => 'My Shop Server', 'domain' => 'my_site.test'])->save();
        License::query()->sole()->update(['valid_ip' => null]);
        $this->licensed->update(['dedicated_ip' => '192.0.2.44']);

        Livewire::actingAs($this->owner)->test(Index::class)->set('search', 'Shop Server')->assertSee('203.0.113.9');
        Livewire::actingAs($this->owner)->test(Index::class)->set('search', 'my_site')->assertSee('203.0.113.9');
        Livewire::actingAs($this->owner)->test(Index::class)->set('search', 'my%site')->assertSee(__('services.no_match', ['search' => 'my%site']));
        $this->assertSame(['ip' => '192.0.2.44', 'ip_is_license' => false], array_intersect_key(Index::glance($this->licensed->fresh()), ['ip' => 0, 'ip_is_license' => 0]));
    }
}
