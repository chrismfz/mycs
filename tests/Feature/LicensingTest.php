<?php

namespace Tests\Feature;

use App\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Helpers\ExtensionHelper;
use App\Models\License;
use App\Models\LicenseAccessLog;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Licensing\Licenses;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * nixpal licenses on MyCS: the NixpalLicensing server extension issues the key on activation and keeps the status with
 * the service; a reissue clears the bindings; the PSK is stored encrypted and never sent to the page; zlic.php records
 * signs of life (beyond WHMCS); the access log is pruned after 90 days.
 */
class LicensingTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        foreach (['key_prefix' => ['ZCAgent-', false], 'key_length' => ['16', false], 'secret_key' => ['TestSecret', true], 'allow_reissue' => ['1', false]] as $key => [$value, $encrypted]) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $encrypted, 'type' => $key === 'allow_reissue' ? 'boolean' : 'string',
                'settingable_id' => $product->product->id, 'settingable_type' => $product->product->getMorphClass()])->save();
        }
        $user = User::factory()->create();
        $this->service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
    }

    private function check(string $key, string $psk = 'TestSecret', string $ip = '198.51.100.7'): array
    {
        return json_decode($this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/zlic.php', ['license' => $key, 'license_PSK' => $psk])->getContent(), true);
    }

    public function test_the_key_is_issued_on_creation_in_todays_format_and_kept_on_a_second_create(): void
    {
        ExtensionHelper::createServer($this->service);
        $license = License::query()->where('service_id', $this->service->id)->sole();

        $this->assertMatchesRegularExpression('/^ZCAgent-[0-9a-f]{16}$/', $license->license_key);
        $this->assertSame(License::STATUS_ACTIVE, $license->status);

        ExtensionHelper::createServer($this->service->fresh());
        $this->assertSame($license->license_key, License::query()->where('service_id', $this->service->id)->sole()->license_key);
    }

    public function test_the_license_follows_its_service_and_zlic_answers_so(): void
    {
        ExtensionHelper::createServer($this->service);
        $key = License::query()->sole()->license_key;

        $this->assertSame('Active', $this->check($key)['status'], 'the first check binds it');
        ExtensionHelper::suspendServer($this->service);
        $this->assertSame('Suspended', $this->check($key)['status']);
        ExtensionHelper::unsuspendServer($this->service);
        $this->assertSame('Active', $this->check($key)['status']);
        ExtensionHelper::terminateServer($this->service);
        $this->assertSame('Expired', $this->check($key)['status']);
    }

    public function test_a_reissue_lets_the_license_move_to_another_server(): void
    {
        ExtensionHelper::createServer($this->service);
        $license = License::query()->sole();
        $this->check($license->license_key, ip: '198.51.100.7');
        $this->assertSame('IP Address Invalid', $this->check($license->license_key, ip: '203.0.113.9')['message'] ?? null);

        Licenses::reissue($license->fresh()->load('service.product.settings'), '192.0.2.1');
        $this->assertSame('Active', $this->check($license->license_key, ip: '203.0.113.9')['status']);

        $license->refresh();
        $this->assertSame('203.0.113.9', $license->valid_ip);
        $this->assertSame(1, $license->reissues);
        $this->assertTrue(LicenseAccessLog::query()->where('license_id', $license->id)->where('message', 'License Reissued')->exists());
    }

    public function test_the_customer_reissues_within_the_limit_only_if_the_product_allows_it(): void
    {
        ExtensionHelper::createServer($this->service);
        $license = License::query()->sole();
        $license->update(['reissues' => Licenses::MAX_REISSUES]);

        $this->assertFalse(Licenses::canReissue($license->fresh()));
        $license->update(['reissues' => 0]);
        $this->assertTrue(Licenses::canReissue($license->fresh()));
        Setting::query()->where('key', 'allow_reissue')->update(['value' => '0']);
        $this->assertFalse(Licenses::canReissue($license->fresh()));
        Licenses::reissue($license->fresh(), '192.0.2.1', byStaff: true); // staff may, whatever the product says
        $this->assertSame(License::STATUS_REISSUED, $license->fresh()->status);
    }

    public function test_signs_of_life_are_recorded(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        ExtensionHelper::createServer($this->service);
        $key = License::query()->sole()->license_key;

        $this->check($key);
        $this->check($key);
        $this->check($key, psk: 'wrong');
        $license = License::query()->sole();

        $this->assertSame('2026-10-20 10:00:00', $license->last_ok_at->format('Y-m-d H:i:s'));
        $this->assertSame('MD5 Checksum Verification Failed (198.51.100.7)', $license->last_failure);
        $this->assertSame('198.51.100.7', $license->last_seen_ip);
        $this->assertStringStartsWith('POST ', $license->last_seen_client);
        $day = DB::table('license_check_days')->where('license_id', $license->id)->sole();
        $this->assertSame([2, 1], [(int) $day->ok, (int) $day->failed]);
        Carbon::setTestNow();
    }

    public function test_a_good_check_is_in_the_access_log_once_a_day(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        ExtensionHelper::createServer($this->service);
        $key = License::query()->sole()->license_key;

        $this->check($key);
        $this->check($key);
        $this->assertSame(['License Active'], LicenseAccessLog::query()->pluck('message')->all(), 'one a day, not one a check');
        $this->assertSame('198.51.100.7', LicenseAccessLog::query()->value('ip'));

        $this->assertSame(License::query()->sole()->valid_domain, LicenseAccessLog::query()->value('domain'), 'the binding it answered for');

        $this->check($key, psk: 'wrong');
        $this->assertSame(1, LicenseAccessLog::query()->where('message', 'License Active')->count(), 'a refused check is not one');
        Setting::query()->where('key', 'allow_ip_conflict')->delete();
        (new Setting)->forceFill(['key' => 'allow_ip_conflict', 'value' => '1', 'type' => 'boolean', 'settingable_id' => $this->service->product_id,
            'settingable_type' => $this->service->product->getMorphClass()])->save();
        $this->check($key, ip: '203.0.113.9');
        $this->assertSame(2, LicenseAccessLog::query()->where('message', 'License Active')->count(), 'per address');

        ExtensionHelper::suspendServer($this->service->fresh());
        $this->check($key);
        ExtensionHelper::unsuspendServer($this->service->fresh());
        $this->check($key);
        $this->assertSame(['License Active', 'License Active', 'License Suspended'], LicenseAccessLog::query()->whereIn('message', ['License Active', 'License Suspended'])->orderBy('message')->pluck('message')->all(),
            'the same day: one of each, no shared dedupe');

        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->check($key);
        $this->assertSame(3, LicenseAccessLog::query()->where('message', 'License Active')->count());
        Carbon::setTestNow();
    }

    public function test_a_failed_log_write_never_changes_the_answer(): void
    {
        ExtensionHelper::createServer($this->service);
        $key = License::query()->sole()->license_key;
        $expected = $this->check($key)['status'];
        Cache::flush(); // the day's row not yet written
        Schema::drop('license_access_logs');

        $this->assertSame($expected, $this->check($key)['status']);
        $this->assertSame(2, (int) DB::table('license_check_days')->value('ok'), 'counted before the log write');
    }

    public function test_the_access_log_is_pruned_after_90_days(): void
    {
        LicenseAccessLog::query()->create(['message' => 'old', 'created_at' => now()->subDays(91)]);
        LicenseAccessLog::query()->create(['message' => 'recent', 'created_at' => now()->subDays(10)]);

        $this->artisan('app:cron-job');

        $this->assertSame(['recent'], LicenseAccessLog::query()->pluck('message')->all());
    }

    public function test_the_psk_is_stored_encrypted_and_never_sent_to_the_product_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $product = $this->service->product;

        $page = Livewire::actingAs($staff, 'staff')->test(EditProduct::class, ['record' => $product->getRouteKey()]);
        $this->assertNull($page->get('data.settings.secret_key'), 'the PSK went to the browser');

        $page->set('data.settings.key_prefix', 'ZCNode-')->call('save')->assertHasNoErrors();
        $stored = DB::table('settings')->where('settingable_id', $product->id)->where('key', 'secret_key')->first();
        $this->assertTrue((bool) $stored->encrypted);
        $this->assertNotSame('TestSecret', $stored->value);
        $this->assertSame('TestSecret', ExtensionHelper::settingsToArray($product->fresh()->settings)['secret_key'], 'left empty: kept');

        Livewire::actingAs($staff, 'staff')->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->set('data.settings.secret_key', 'NewSecret')->call('save')->assertHasNoErrors();
        $this->assertSame('NewSecret', ExtensionHelper::settingsToArray($product->fresh()->settings)['secret_key']);
    }

    public function test_the_key_is_the_services_domain_as_whmcs_keeps_it(): void
    {
        ExtensionHelper::createServer($this->service);
        $key = License::query()->sole()->license_key;

        $this->assertSame($key, $this->service->fresh()->domain, 'shown under the product and found by the search');

        $other = Service::factory()->create(['client_id' => $this->service->client_id, 'plan_id' => $this->service->plan_id, 'product_id' => $this->service->product_id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1, 'domain' => 'kept.example.test']);
        ExtensionHelper::createServer($other);
        $this->assertSame('kept.example.test', $other->fresh()->domain, 'a domain the service has is kept');
    }

    public function test_quantity_and_the_old_subscription_id_show_only_where_they_mean_something(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        $page = $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()]);
        $page->assertFormFieldIsHidden('quantity')->assertFormFieldIsHidden('subscription_id');

        $this->service->update(['subscription_id' => 'I-LEGACY123']);
        $this->service->product->update(['allow_quantity' => 'separated']);
        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->assertFormFieldIsVisible('quantity')->assertFormFieldIsVisible('subscription_id');
    }
}
