<?php

namespace Tests\Feature;

use App\Admin\Resources\LicenseResource\Pages\ListLicenses;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Helpers\ExtensionHelper;
use App\Livewire\Admin\BulkLicenseStaff;
use App\Models\LicenseIp;
use App\Models\LicenseUsagePeriod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Rules\BulkTiers;
use App\Services\Licensing\BulkConvert;
use App\Services\Licensing\BulkLicenseIps;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Staff's view of a bulk license (docs/zonecloud-bulk-license.md §7.2, PR 6, mockup approved 2026-11-13): at a glance, this
 * period and its prices, the list with the removed IPs, who did each change, the licenses moving in, the closed periods;
 * the licenses console's IPs; the product's tiers read on save.
 */
class BulkLicenseStaffTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private Service $bulk;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $this->staff = Staff::factory()->create(['first_name' => 'Maria', 'last_name' => 'K', 'role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $this->bulk = $this->service($this->product(bulk: true), User::factory()->create()->client()->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(bool $bulk): Product
    {
        $created = $this->createProduct();
        $created->product->update(['server_id' => $this->server->id]);
        foreach (['key_prefix' => 'ZCAgent-', 'key_length' => '16', 'secret_key' => 'ZoneCloudAgent', 'bulk' => $bulk ? '1' : '0', 'tiers' => '1=1.80, 51=1.50'] as $key => $value) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $key === 'secret_key', 'type' => $key === 'bulk' ? 'boolean' : 'string',
                'settingable_id' => $created->product->id, 'settingable_type' => (new Product)->getMorphClass()])->save();
        }

        return $created->product;
    }

    private function service(Product $product, int $clientId, ?string $ip = null): Service
    {
        $service = Service::factory()->create(['client_id' => $clientId, 'plan_id' => $product->plans()->first()->id, 'product_id' => $product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => '1.80', 'quantity' => 1, 'expires_at' => now()->addMonth()]);
        ExtensionHelper::createServer($service);
        if ($ip !== null) {
            $service->license->update(['valid_ip' => $ip]);
        }

        return $service->fresh(['license', 'product.settings']);
    }

    public function test_staff_see_the_list_its_counts_prices_and_who_did_each_change(): void
    {
        config(['audit.console' => true]); // audited as on the web (off for console runs)
        $this->actingAs($this->staff);
        BulkLicenseIps::add($this->bulk->license, "198.51.100.7 web1\n198.51.100.8 web2");
        Auth::guard('staff')->logout();
        Carbon::setTestNow('2026-11-13 11:00:00');
        $this->actingAs($this->bulk->client->owner(), 'web');
        BulkLicenseIps::remove($this->bulk->license, [LicenseIp::query()->where('ip', '198.51.100.8')->value('id')]);
        Auth::guard('web')->logout();
        LicenseIp::query()->where('ip', '198.51.100.7')->update(['last_ok_at' => now()->subDays(9)]);

        $this->livewireAs($this->staff)->test(BulkLicenseStaff::class, ['service' => $this->bulk])
            ->assertSeeText('1 / 200')
            ->assertSee('1.80 from the 1', false)
            ->assertSeeText("the product's")
            ->assertSee('198.51.100.7')->assertDontSee('198.51.100.8 </td>', false)
            ->assertSee('staff · Maria K')
            ->assertSee('customer')
            ->set('filter', 'removed')
            ->assertSee('198.51.100.8')
            ->set('filter', 'silent')
            ->assertSee('198.51.100.7');
    }

    public function test_the_closed_periods_and_the_licenses_moving_in(): void
    {
        LicenseUsagePeriod::query()->create(['license_id' => $this->bulk->license->id, 'period_start' => '2026-10-10', 'period_end' => '2026-11-10',
            'ips' => 61, 'actual' => '106.50', 'billed' => '90.00', 'adjustment' => '16.50', 'tiers' => '[]', 'closed_at' => now()]);
        $old = $this->service($this->product(bulk: false), $this->bulk->client_id, '203.0.113.10');
        $old->update(['expires_at' => '2026-11-20']);
        BulkConvert::convert($this->bulk, [$old->id]);

        $this->livewireAs($this->staff)->test(BulkLicenseStaff::class, ['service' => $this->bulk])
            ->assertSee('10/10/2026 – 09/11/2026')
            ->assertSee('+16.50 · on the next invoice')
            ->assertSee('Moving in: 1 joining (next 20/11/2026), 0 joined');
    }

    public function test_the_service_page_shows_it_only_for_a_bulk_license(): void
    {
        $this->livewireAs($this->staff)->test(EditService::class, ['record' => $this->bulk->getRouteKey()])
            ->assertSeeLivewire(BulkLicenseStaff::class);
        $single = $this->service($this->product(bulk: false), $this->bulk->client_id, '203.0.113.10');
        $this->livewireAs($this->staff)->test(EditService::class, ['record' => $single->getRouteKey()])
            ->assertDontSeeLivewire(BulkLicenseStaff::class);
    }

    public function test_staff_without_rights_to_the_service_are_refused(): void
    {
        $nobody = Staff::factory()->create(['role_id' => Role::create(['name' => 'None', 'permissions' => []])->id]);

        $this->livewireAs($nobody)->test(BulkLicenseStaff::class, ['service' => $this->bulk])->assertForbidden();
    }

    public function test_the_console_shows_a_bulk_licenses_ips_the_silent_ones_and_one_moving_in(): void
    {
        BulkLicenseIps::add($this->bulk->license, "198.51.100.7\n198.51.100.8\n198.51.100.9");
        LicenseIp::query()->where('ip', '198.51.100.7')->update(['last_ok_at' => now()->subDays(9)]);
        $old = $this->service($this->product(bulk: false), $this->bulk->client_id, '203.0.113.10');
        $old->update(['expires_at' => '2026-11-20']);
        BulkConvert::convert($this->bulk, [$old->id]);

        $this->livewireAs($this->staff)->test(ListLicenses::class)
            ->assertSee('3 · 1 silent')
            ->assertSee('joins bulk 20/11/2026')
            ->filterTable('moving')
            ->assertCanSeeTableRecords([$old->license])->assertCanNotSeeTableRecords([$this->bulk->license]);
        $this->livewireAs($this->staff)->test(ListLicenses::class)->filterTable('silent_ips')
            ->assertCanSeeTableRecords([$this->bulk->license])->assertCanNotSeeTableRecords([$old->license]);
    }

    public function test_the_products_tiers_are_read_on_save(): void
    {
        $this->assertTrue(Validator::make(['tiers' => '1=1.80, 51=1.50'], ['tiers' => [new BulkTiers]])->passes());
        $this->assertTrue(Validator::make(['tiers' => ''], ['tiers' => [new BulkTiers]])->passes());
        $bad = Validator::make(['tiers' => '1=1,80'], ['tiers' => [new BulkTiers]]);
        $this->assertTrue($bad->fails());
        $this->assertStringContainsString('is not a tier', $bad->errors()->first('tiers'));
        $this->assertTrue(Validator::make(['tiers' => '51=1.50'], ['tiers' => [new BulkTiers]])->fails(), 'they start at 1');
    }
}
