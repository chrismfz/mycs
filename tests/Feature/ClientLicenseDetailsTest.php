<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Index;
use App\Models\License;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner 2026-10-28: the customer sees on their license what staff see of where it is bound (domains, directory) and its
 * last good check with the IP it came from, not the client string of that check; the list shows each license's key.
 */
class ClientLicenseDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Service $service;

    private License $license;

    private Server $server;

    private int $productId;

    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
        $this->server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $this->server->id, 'name' => 'ZCAgent']);
        foreach (['key_prefix' => ['ZCAgent-', false], 'key_length' => ['16', false], 'secret_key' => ['TestSecret', true]] as $key => [$value, $encrypted]) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $encrypted, 'type' => 'string',
                'settingable_id' => $product->product->id, 'settingable_type' => $product->product->getMorphClass()])->save();
        }
        [$this->productId, $this->planId] = [$product->product->id, $product->plan->id];
        $this->owner = User::factory()->create();
        $this->service = $this->licensedService($this->owner);
        $this->license = $this->service->license()->sole();
    }

    private function licensedService(User $user): Service
    {
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $this->planId, 'product_id' => $this->productId,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1, 'expires_at' => now()->addMonth(), 'server_id' => $this->server->id]);
        ExtensionHelper::createServer($service);

        return $service;
    }

    private function page(User $user, ?Service $service = null)
    {
        $service ??= $this->service;

        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $user->client()->id]))
            ->get(route('services.show', $service));
    }

    public function test_the_service_page_shows_the_bindings_and_the_last_good_check_with_its_ip(): void
    {
        Carbon::setTestNow('2026-10-28 10:00:00');
        $this->license->update(['valid_ip' => '198.51.100.7', 'valid_domain' => 'panel.example.test,www.example.test', 'valid_directory' => '/opt/zonecloud',
            'last_ok_at' => now()->subHours(3), 'last_seen_ip' => '198.51.100.7', 'last_seen_client' => 'POST GuzzleHttp/7']);

        $html = $this->page($this->owner)->assertOk()->getContent();

        foreach ([__('services.license.valid_domains'), 'panel.example.test, www.example.test', __('services.license.valid_directory'), '/opt/zonecloud',
            __('services.license.last_check'), now()->subHours(3)->diffForHumans(), __('services.license.last_seen_from')] as $shown) {
            $this->assertStringContainsString($shown, $html);
        }
        $this->assertSame('Last good check', __('services.license.last_check'), 'one label for last_ok_at, as staff call it');
        $this->assertStringNotContainsString('GuzzleHttp', $html, 'the client string of the check stays staff\'s');
        Carbon::setTestNow();
    }

    public function test_empty_bindings_and_no_good_check_show_no_empty_rows(): void
    {
        $html = $this->page($this->owner)->assertOk()->getContent();

        $this->assertStringContainsString(__('services.license.not_bound'), $html);
        $this->assertStringContainsString(__('services.license.never_checked'), $html);
        foreach (['valid_domains', 'valid_directory', 'last_seen_from'] as $label) {
            $this->assertStringNotContainsString(__('services.license.' . $label), $html, $label);
        }
    }

    public function test_a_failed_check_never_shows_as_last_seen_from(): void
    {
        $this->license->update(['last_failure_at' => now(), 'last_failure' => 'IP Address Invalid (203.0.113.9)']);

        $html = $this->page($this->owner)->assertOk()->getContent();

        $this->assertStringNotContainsString(__('services.license.last_seen_from'), $html);
        $this->assertStringNotContainsString('203.0.113.9', $html);
    }

    public function test_greek_labels(): void
    {
        $this->license->update(['valid_domain' => 'panel.example.test', 'valid_directory' => '/opt/zonecloud', 'last_ok_at' => now(), 'last_seen_ip' => '198.51.100.7']);
        app()->setLocale('el');

        $html = $this->page($this->owner)->assertOk()->getContent();

        $this->assertStringContainsString('Έγκυρα domains', $html);
        $this->assertStringNotContainsString('services.license.', $html);
    }

    public function test_another_customers_license_page_is_not_reachable(): void
    {
        $this->license->update(['valid_domain' => 'panel.example.test', 'last_seen_ip' => '198.51.100.7']);
        $other = User::factory()->create();

        $response = $this->page($other);

        $this->assertContains($response->status(), [403, 404]);
        $response->assertDontSee('panel.example.test')->assertDontSee($this->license->license_key);
    }

    public function test_the_list_shows_each_license_key_with_copy_and_none_for_other_services(): void
    {
        $this->license->update(['valid_ip' => '198.51.100.7']);
        $unbound = $this->licensedService($this->owner)->license()->sole();
        $plain = $this->createProduct();
        $plain->product->update(['name' => 'Web Hosting']);
        Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $plain->plan->id, 'product_id' => $plain->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 5, 'quantity' => 1, 'expires_at' => now()->addMonth(), 'dedicated_ip' => '203.0.113.9']);

        $html = Livewire::actingAs($this->owner)->test(Index::class)
            ->assertSee($this->license->license_key)->assertSee('198.51.100.7')
            ->assertSee($unbound->license_key, false)
            ->assertSee('203.0.113.9')
            ->html();

        $this->assertSame(2, substr_count($html, '<code class="font-mono">'), 'a key for each license, none for the hosting');
        $this->assertStringContainsString('x-on:click.prevent.stop', $html, 'Copy, without opening the service');
        $this->assertSame(['key' => null, 'ip' => '203.0.113.9', 'ip_is_license' => false, 'fields' => []],
            Index::glance(Service::query()->where('dedicated_ip', '203.0.113.9')->sole()));
    }

    public function test_another_customers_license_key_never_appears_in_the_list(): void
    {
        $other = User::factory()->create();
        $theirs = $this->licensedService($other)->license()->sole();

        Livewire::actingAs($this->owner)->test(Index::class)
            ->assertSee($this->license->license_key)->assertDontSee($theirs->license_key);
        Livewire::actingAs($this->owner)->test(Index::class)->set('search', $theirs->license_key)
            ->assertSee(__('services.no_match', ['search' => $theirs->license_key]))->assertDontSee('<code class="font-mono">', false);
    }
}
